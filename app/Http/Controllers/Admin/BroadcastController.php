<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BroadcastStatus;
use App\Enums\ContactStatus;
use App\Enums\RecipientStatus;
use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\WhatsappTemplate;
use App\Services\Broadcasts\Audience;
use App\Services\Broadcasts\BroadcastLauncher;
use App\Services\Inbox\TemplateRenderer;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\WhatsAppClient;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Blasts: one message to many leads at once (WhatsApp templates, or
 * Messenger text to people who messaged in the last 24 hours).
 */
class BroadcastController extends Controller
{
    public function index(): Response
    {
        $broadcasts = Broadcast::query()
            ->with(['channel', 'template', 'creator'])
            ->withCount([
                'recipients as sent_count' => fn ($q) => $q->whereIn('status', [RecipientStatus::Sent, RecipientStatus::Delivered, RecipientStatus::Read]),
                'recipients as delivered_count' => fn ($q) => $q->whereIn('status', [RecipientStatus::Delivered, RecipientStatus::Read]),
                'recipients as read_count' => fn ($q) => $q->where('status', RecipientStatus::Read),
                'recipients as failed_count' => fn ($q) => $q->where('status', RecipientStatus::Failed),
                'recipients as replied_count' => fn ($q) => $q->whereNotNull('replied_at'),
            ])
            ->latest()
            ->paginate(20)
            ->through(fn (Broadcast $broadcast) => [
                ...$this->summary($broadcast),
                'sent_count' => $broadcast->sent_count,
                'delivered_count' => $broadcast->delivered_count,
                'read_count' => $broadcast->read_count,
                'failed_count' => $broadcast->failed_count,
                'replied_count' => $broadcast->replied_count,
            ]);

        return Inertia::render('broadcasts/Index', ['broadcasts' => $broadcasts]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(Broadcast $broadcast): Response|RedirectResponse
    {
        if (! $broadcast->isEditable()) {
            return to_route('admin.broadcasts.show', $broadcast);
        }

        return $this->form($broadcast);
    }

    public function store(Request $request, BroadcastLauncher $launcher): RedirectResponse
    {
        $broadcast = new Broadcast(['status' => BroadcastStatus::Draft, 'created_by' => $request->user()->id]);

        return $this->save($request, $broadcast, $launcher);
    }

    public function update(Request $request, Broadcast $broadcast, BroadcastLauncher $launcher): RedirectResponse
    {
        abort_unless($broadcast->isEditable(), 403, 'This blast has already been sent.');

        return $this->save($request, $broadcast, $launcher);
    }

    public function show(Request $request, Broadcast $broadcast): Response
    {
        $broadcast->load(['channel', 'template', 'creator']);

        $counts = $broadcast->recipients()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $status = RecipientStatus::tryFrom((string) $request->query('status'));

        $recipients = $broadcast->recipients()
            ->with('contact')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (BroadcastRecipient $recipient) => [
                'id' => $recipient->id,
                'contact' => ['id' => $recipient->contact->id, 'name' => $recipient->contact->display_name, 'phone' => $recipient->contact->phone],
                'conversation_id' => $recipient->conversation_id,
                'status' => $recipient->status->value,
                'error' => $recipient->error,
                'sent_at' => $recipient->sent_at?->toIso8601String(),
                'replied_at' => $recipient->replied_at?->toIso8601String(),
            ]);

        return Inertia::render('broadcasts/Show', [
            'broadcast' => [
                ...$this->summary($broadcast),
                'body' => $broadcast->body,
                'template_params' => $broadcast->template_params,
                'audience_labels' => $this->audienceLabels($broadcast->audience ?? []),
            ],
            'counts' => [
                'total' => (int) $counts->sum(),
                'pending' => (int) ($counts['pending'] ?? 0),
                'sent' => (int) ($counts['sent'] ?? 0) + (int) ($counts['delivered'] ?? 0) + (int) ($counts['read'] ?? 0),
                'delivered' => (int) ($counts['delivered'] ?? 0) + (int) ($counts['read'] ?? 0),
                'read' => (int) ($counts['read'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'skipped' => (int) ($counts['skipped'] ?? 0),
                'replied' => $broadcast->recipients()->whereNotNull('replied_at')->count(),
            ],
            'recipients' => $recipients,
            'filter' => $status?->value,
        ]);
    }

    public function destroy(Broadcast $broadcast): RedirectResponse
    {
        abort_if($broadcast->status === BroadcastStatus::Sending, 403, 'Cancel the blast before deleting it.');

        $broadcast->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Blast deleted.')]);

        return to_route('admin.broadcasts.index');
    }

    public function cancel(Broadcast $broadcast): RedirectResponse
    {
        if (in_array($broadcast->status, [BroadcastStatus::Scheduled, BroadcastStatus::Sending], true)) {
            $broadcast->update(['status' => BroadcastStatus::Cancelled, 'completed_at' => now()]);

            // Anyone not reached yet is skipped by the queued jobs.
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Blast cancelled. Messages already sent can\'t be recalled.')]);
        }

        return back();
    }

    /**
     * Live audience size while building a blast.
     */
    public function audience(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel_id' => ['required', 'integer', Rule::exists(Channel::class, 'id')],
            'tag_ids' => ['array'],
            'tag_ids.*' => ['integer'],
            'exclude_tag_ids' => ['array'],
            'exclude_tag_ids.*' => ['integer'],
            'statuses' => ['array'],
            'statuses.*' => ['string'],
        ]);

        $query = Audience::query(Channel::findOrFail($validated['channel_id']), $validated);

        return response()->json([
            'count' => (clone $query)->count(),
            'sample' => $query->limit(5)->get()->map(fn (Contact $contact) => $contact->display_name),
        ]);
    }

    /**
     * Send the template to one number (e.g. your own) to check how it looks.
     */
    public function test(Request $request, TemplateRenderer $renderer): RedirectResponse
    {
        $validated = $request->validate([
            'channel_id' => ['required', 'integer', Rule::exists(Channel::class, 'id')->where('type', 'whatsapp')],
            'whatsapp_template_id' => ['required', 'integer'],
            'template_params' => ['array'],
            'template_params.*' => ['nullable', 'string', 'max:1024'],
            'phone' => ['required', 'string', 'max:32'],
        ]);

        $channel = Channel::findOrFail($validated['channel_id']);
        $template = WhatsappTemplate::query()->where('channel_id', $channel->id)->findOrFail($validated['whatsapp_template_id']);
        $phone = Phone::normalize($validated['phone']);
        $values = $validated['template_params'] ?? [];

        if (! $phone) {
            throw ValidationException::withMessages(['phone' => __('Enter a valid phone number.')]);
        }

        if ($missing = $renderer->missing($template, $values)) {
            throw ValidationException::withMessages(['template_params' => __('Fill in every template variable (:keys).', ['keys' => implode(', ', $missing)])]);
        }

        // A stand-in contact so {first_name} etc. show something realistic.
        $sample = new Contact(['name' => $request->user()->name, 'phone' => $phone]);

        try {
            (new WhatsAppClient($channel))->sendTemplate(
                $phone,
                $template->name,
                $template->language,
                $renderer->components($template, $values, $sample),
            );
        } catch (MetaApiException $e) {
            throw ValidationException::withMessages(['phone' => $e->friendlyMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test message sent to +:phone.', ['phone' => $phone])]);

        return back();
    }

    private function save(Request $request, Broadcast $broadcast, BroadcastLauncher $launcher): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'channel_id' => ['required', 'integer', Rule::exists(Channel::class, 'id')],
            'whatsapp_template_id' => ['nullable', 'integer', Rule::exists(WhatsappTemplate::class, 'id')],
            'template_params' => ['nullable', 'array'],
            'template_params.*' => ['nullable', 'string', 'max:1024'],
            'body' => ['nullable', 'string', 'max:2000'],
            'audience' => ['array'],
            'audience.tag_ids' => ['array'],
            'audience.tag_ids.*' => ['integer'],
            'audience.exclude_tag_ids' => ['array'],
            'audience.exclude_tag_ids.*' => ['integer'],
            'audience.statuses' => ['array'],
            'audience.statuses.*' => [Rule::enum(ContactStatus::class)],
            'scheduled_at' => ['nullable', 'date'],
            'intent' => ['required', Rule::in(['draft', 'send'])],
        ]);

        $broadcast->fill([
            'name' => $validated['name'],
            'channel_id' => $validated['channel_id'],
            'whatsapp_template_id' => $validated['whatsapp_template_id'] ?? null,
            'template_params' => $validated['template_params'] ?? [],
            'body' => $validated['body'] ?? null,
            'audience' => [
                'tag_ids' => array_map('intval', $validated['audience']['tag_ids'] ?? []),
                'exclude_tag_ids' => array_map('intval', $validated['audience']['exclude_tag_ids'] ?? []),
                'statuses' => $validated['audience']['statuses'] ?? [],
            ],
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            // Editing a scheduled blast puts it back to draft until it is re-scheduled.
            'status' => BroadcastStatus::Draft,
        ]);

        if ($validated['intent'] === 'draft') {
            $broadcast->save();

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft saved.')]);

            return to_route('admin.broadcasts.edit', $broadcast);
        }

        // Check before saving, so a failed "Send" doesn't leave a stray draft behind.
        $broadcast->load(['channel', 'template']);
        $launcher->ensureReady($broadcast);

        $broadcast->save();
        $launcher->launch($broadcast);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $broadcast->fresh()->status === BroadcastStatus::Scheduled
                ? __('Blast scheduled.')
                : __('Blast is on its way!'),
        ]);

        return to_route('admin.broadcasts.show', $broadcast);
    }

    private function form(?Broadcast $broadcast): Response
    {
        $renderer = app(TemplateRenderer::class);

        return Inertia::render('broadcasts/Form', [
            'broadcast' => $broadcast ? [
                'id' => $broadcast->id,
                'name' => $broadcast->name,
                'channel_id' => $broadcast->channel_id,
                'whatsapp_template_id' => $broadcast->whatsapp_template_id,
                'template_params' => (object) ($broadcast->template_params ?? []),
                'body' => $broadcast->body,
                'audience' => $broadcast->audience ?? ['tag_ids' => [], 'exclude_tag_ids' => [], 'statuses' => []],
                'scheduled_at' => $broadcast->scheduled_at?->toIso8601String(),
                'status' => $broadcast->status->value,
            ] : null,
            'channels' => Channel::query()->where('is_active', true)->orderBy('name')->get()->map->toSummary(),
            'templates' => WhatsappTemplate::query()
                ->where('status', 'APPROVED')
                ->orderBy('name')
                ->get()
                ->map(fn (WhatsappTemplate $template) => [...$template->toPayload(), 'variables' => $renderer->variables($template)]),
            'tags' => Tag::query()->orderBy('name')->get()->map->toSummary(),
            'statuses' => ContactStatus::options(),
            'ratePerMinute' => (int) config('crm.broadcast_per_minute'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Broadcast $broadcast): array
    {
        return [
            'id' => $broadcast->id,
            'name' => $broadcast->name,
            'status' => $broadcast->status->value,
            'channel' => $broadcast->channel->toSummary(),
            'template' => $broadcast->template ? ['name' => $broadcast->template->name, 'language' => $broadcast->template->language] : null,
            'total_recipients' => $broadcast->total_recipients,
            'scheduled_at' => $broadcast->scheduled_at?->toIso8601String(),
            'started_at' => $broadcast->started_at?->toIso8601String(),
            'completed_at' => $broadcast->completed_at?->toIso8601String(),
            'created_at' => $broadcast->created_at?->toIso8601String(),
            'creator' => $broadcast->creator?->name,
        ];
    }

    /**
     * @param  array{tag_ids?: list<int>, exclude_tag_ids?: list<int>, statuses?: list<string>}  $audience
     * @return array{include: list<string>, exclude: list<string>, statuses: list<string>}
     */
    private function audienceLabels(array $audience): array
    {
        $names = Tag::query()->pluck('name', 'id');

        return [
            'include' => collect($audience['tag_ids'] ?? [])->map(fn ($id) => $names[$id] ?? '(deleted tag)')->values()->all(),
            'exclude' => collect($audience['exclude_tag_ids'] ?? [])->map(fn ($id) => $names[$id] ?? '(deleted tag)')->values()->all(),
            'statuses' => collect($audience['statuses'] ?? [])->map(fn ($s) => ContactStatus::tryFrom($s)?->label() ?? $s)->values()->all(),
        ];
    }
}
