<?php

namespace App\Http\Controllers\Inbox;

use App\Enums\ContactStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Events\ConversationChanged;
use App\Http\Controllers\Controller;
use App\Jobs\MarkWhatsAppMessageRead;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Note;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Services\Inbox\Outbox;
use App\Services\Inbox\TemplateRenderer;
use App\Support\Realtime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public const PAGE_SIZE = 50;

    public function index(Request $request): Response
    {
        return $this->render($request);
    }

    public function show(Request $request, Conversation $conversation, Outbox $outbox, TemplateRenderer $templates): Response
    {
        Gate::authorize('view', $conversation);

        $this->markAsRead($conversation);

        $conversation->load(['channel', 'contact.tags', 'assignee']);

        $messages = $conversation->messages()
            ->with('user')
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $notes = Note::query()
            ->with('user')
            ->where('contact_id', $conversation->contact_id)
            ->latest()
            ->get()
            ->map(fn (Note $note) => [
                'id' => $note->id,
                'body' => $note->body,
                'user' => $note->user?->name,
                'created_at' => $note->created_at?->toIso8601String(),
                'can_delete' => $request->user()->isAdmin() || $note->user_id === $request->user()->id,
            ]);

        $approvedTemplates = $conversation->channel->isWhatsApp()
            ? WhatsappTemplate::query()
                ->where('channel_id', $conversation->channel_id)
                ->where('status', 'APPROVED')
                ->orderBy('name')
                ->get()
                ->map(fn (WhatsappTemplate $template) => [
                    ...$template->toPayload(),
                    'variables' => $templates->variables($template),
                ])
            : [];

        return $this->render($request, [
            'selected' => [
                'conversation' => $conversation->toSummary(),
                'messages' => $messages->take(self::PAGE_SIZE)->reverse()->values()->map->toPayload(),
                'has_older' => $messages->count() > self::PAGE_SIZE,
                'can_reply' => $outbox->canReplyFreely($conversation),
                'notes' => $notes,
                'templates' => $approvedTemplates,
                'other_chats' => $conversation->contact->conversations()
                    ->with('channel')
                    ->whereKeyNot($conversation->id)
                    ->visibleTo($request->user())
                    ->get()
                    ->map(fn (Conversation $other) => ['id' => $other->id, 'channel' => $other->channel->toSummary()]),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function render(Request $request, array $extra = []): Response
    {
        $user = $request->user();

        $filters = [
            'folder' => in_array($request->query('folder'), ['all', 'mine', 'unassigned'], true) ? $request->query('folder') : 'all',
            'status' => $request->query('status') === 'closed' ? 'closed' : 'open',
            'channel' => $request->integer('channel') ?: null,
            'tag' => $request->integer('tag') ?: null,
            'agent' => $user->isAdmin() ? ($request->integer('agent') ?: null) : null,
            'q' => trim((string) $request->query('q', '')),
            'limit' => min(max($request->integer('limit', self::PAGE_SIZE), self::PAGE_SIZE), 500),
        ];

        $conversations = $this->query($user, $filters)
            ->with(['channel', 'contact.tags', 'assignee'])
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit($filters['limit'] + 1)
            ->get();

        $open = fn () => Conversation::query()->visibleTo($user)->where('status', ConversationStatus::Open);

        return Inertia::render('inbox/Index', [
            'filters' => $filters,
            'conversations' => $conversations->take($filters['limit'])->map->toSummary()->values(),
            'hasMore' => $conversations->count() > $filters['limit'],
            'counts' => [
                'all' => $open()->count(),
                'mine' => $open()->where('assigned_user_id', $user->id)->count(),
                'unassigned' => $open()->whereNull('assigned_user_id')->count(),
            ],
            'options' => fn () => [
                'channels' => Channel::query()->orderBy('name')->get()->map->toSummary(),
                'tags' => Tag::query()->orderBy('name')->get()->map->toSummary(),
                'agents' => User::query()->active()->orderBy('name')->get(['id', 'name', 'is_available']),
                'statuses' => ContactStatus::options(),
            ],
            'quickReplies' => fn () => QuickReply::query()
                ->availableTo($user)
                ->with('user')
                ->orderBy('shortcut')
                ->get()
                ->map(fn (QuickReply $reply) => $reply->toPayload($user)),
            'selected' => null,
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Conversation>
     */
    private function query(User $user, array $filters): Builder
    {
        return Conversation::query()
            ->visibleTo($user)
            ->where('status', $filters['status'])
            ->when($filters['folder'] === 'mine', fn (Builder $q) => $q->where('assigned_user_id', $user->id))
            ->when($filters['folder'] === 'unassigned', fn (Builder $q) => $q->whereNull('assigned_user_id'))
            ->when($filters['agent'], fn (Builder $q, int $agent) => $q->where('assigned_user_id', $agent))
            ->when($filters['channel'], fn (Builder $q, int $channel) => $q->where('channel_id', $channel))
            ->when($filters['tag'], fn (Builder $q, int $tag) => $q->whereHas('contact.tags', fn (Builder $t) => $t->whereKey($tag)))
            ->when($filters['q'] !== '', fn (Builder $q) => $q->whereHas('contact', fn (Builder $c) => $c->search($filters['q'])));
    }

    private function markAsRead(Conversation $conversation): void
    {
        if ($conversation->unread_count === 0) {
            return;
        }

        $conversation->update(['unread_count' => 0]);

        $lastInbound = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound)
            ->whereNotNull('external_id')
            ->latest('id')
            ->first();

        if ($lastInbound && $conversation->channel->isWhatsApp()) {
            MarkWhatsAppMessageRead::dispatch($conversation->channel, $lastInbound->external_id);
        }

        Realtime::send(new ConversationChanged($conversation));
    }
}
