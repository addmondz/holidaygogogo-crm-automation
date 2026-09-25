<?php

namespace App\Services\Inbox;

use App\Enums\ChannelType;
use App\Enums\ContactStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\ConversationChanged;
use App\Events\MessageSaved;
use App\Jobs\SendMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Support\Realtime;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Queues messages written by agents (or blasts) and keeps the chat list in sync.
 * The actual call to Meta happens in the SendMessage job.
 */
class Outbox
{
    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly TemplateRenderer $templates,
    ) {}

    public function sendText(Conversation $conversation, User $agent, string $body): Message
    {
        $this->ensureCanReplyFreely($conversation);

        return $this->queue($conversation, $agent, [
            'type' => MessageType::Text,
            'body' => $body,
        ]);
    }

    /**
     * @param  UploadedFile|array{path: string, mime: ?string, filename: ?string, size: int}  $file  An upload, or an already-stored file (e.g. a quick-reply attachment).
     */
    public function sendMedia(Conversation $conversation, User $agent, UploadedFile|array $file, ?string $caption = null): Message
    {
        $this->ensureCanReplyFreely($conversation);

        $media = $file instanceof UploadedFile ? MediaStore::putUpload($file) : $file;

        return $this->queue($conversation, $agent, [
            'type' => MessageType::fromMime($media['mime']),
            'body' => $caption,
            'media' => $media,
        ]);
    }

    /**
     * Approved WhatsApp templates can be sent at any time, e.g. to restart a
     * chat after the 24-hour window has closed, or in a blast.
     *
     * @param  array<string, string|null>  $values
     */
    public function sendTemplate(Conversation $conversation, ?User $agent, WhatsappTemplate $template, array $values): Message
    {
        if (! $conversation->channel->isWhatsApp()) {
            throw ValidationException::withMessages(['template' => __('Templates can only be sent on WhatsApp.')]);
        }

        $contact = $conversation->contact;

        return $this->queue($conversation, $agent, [
            'type' => MessageType::Template,
            'body' => $this->templates->render($template, $values, $contact, $agent),
            'meta' => [
                'template' => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'language' => $template->language,
                    'components' => $this->templates->components($template, $values, $contact, $agent),
                ],
            ],
        ]);
    }

    /**
     * Agents may reply freely within 24h of the customer's last message.
     * Messenger allows 7 days with the HUMAN_AGENT tag (needs Meta approval).
     */
    public function ensureCanReplyFreely(Conversation $conversation): void
    {
        if ($this->canReplyFreely($conversation)) {
            return;
        }

        throw ValidationException::withMessages([
            'body' => $conversation->channel->isWhatsApp()
                ? __('The 24-hour reply window has closed. Send an approved template to restart the chat.')
                : __('Messenger only allows replies within 24 hours of the customer\'s last message.'),
        ]);
    }

    public function canReplyFreely(Conversation $conversation): bool
    {
        if ($conversation->isWindowOpen()) {
            return true;
        }

        return $conversation->channel->type === ChannelType::Messenger
            && config('services.meta.messenger_human_agent')
            && $conversation->last_inbound_at?->gt(now()->subDays(7));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function queue(Conversation $conversation, ?User $agent, array $attributes): Message
    {
        // Replying to an unassigned chat claims it, so two agents don't answer the same lead.
        if ($agent && ! $conversation->assigned_user_id) {
            $this->assignments->assign($conversation, $agent, $agent);
        }

        $message = $conversation->messages()->create([
            ...$attributes,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Pending,
            'user_id' => $agent?->id,
        ]);

        $message->setRelation('user', $agent);

        $conversation->update([
            'last_message_at' => $message->created_at,
            'last_message_preview' => $message->preview(),
            'unread_count' => 0,
        ]);

        $contact = $conversation->contact;

        if ($agent && $contact->status === ContactStatus::New) {
            $contact->update(['status' => ContactStatus::Contacted]);
        }

        Realtime::send(new MessageSaved($message));
        Realtime::send(new ConversationChanged($conversation));

        SendMessage::dispatch($message);

        return $message;
    }
}
