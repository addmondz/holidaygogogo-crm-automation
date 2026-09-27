<?php

namespace App\Services\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Jobs\StartBroadcast;
use App\Models\Broadcast;
use App\Services\Inbox\TemplateRenderer;
use Illuminate\Validation\ValidationException;

class BroadcastLauncher
{
    public function __construct(private readonly TemplateRenderer $templates) {}

    /**
     * Check everything needed to send, so problems show up before anything goes out.
     */
    public function ensureReady(Broadcast $broadcast): void
    {
        $channel = $broadcast->channel;
        $errors = [];

        if (! $channel->is_active) {
            $errors['channel_id'] = __('This channel is switched off.');
        }

        if ($channel->isWhatsApp()) {
            $template = $broadcast->template;

            if (! $template || ! $template->isApproved() || $template->channel_id !== $channel->id) {
                $errors['whatsapp_template_id'] = __('Choose an approved template for this WhatsApp number.');
            } elseif ($missing = $this->templates->missing($template, $broadcast->template_params ?? [])) {
                $errors['template_params'] = __('Fill in every template variable (:keys).', ['keys' => implode(', ', $missing)]);
            }
        } elseif (blank($broadcast->body)) {
            $errors['body'] = __('Write the message to send.');
        }

        if (! isset($errors['channel_id']) && Audience::query($channel, $broadcast->audience ?? [])->doesntExist()) {
            $errors['audience'] = $channel->isMessenger()
                ? __('Nobody matches. Messenger blasts only reach people who messaged your Page in the last 24 hours.')
                : __('Nobody matches this audience (opted-out contacts and contacts without a phone number are left out).');
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Send now, or at the scheduled time (picked up by the scheduler every minute).
     */
    public function launch(Broadcast $broadcast): void
    {
        $this->ensureReady($broadcast);

        if ($broadcast->scheduled_at?->isFuture()) {
            $broadcast->update(['status' => BroadcastStatus::Scheduled]);

            return;
        }

        $broadcast->update(['status' => BroadcastStatus::Sending, 'started_at' => now()]);

        StartBroadcast::dispatch($broadcast);
    }

    /**
     * Mark a blast completed once no recipient is waiting any more.
     */
    public static function completeIfFinished(Broadcast $broadcast): void
    {
        if ($broadcast->recipients()->where('status', 'pending')->exists()) {
            return;
        }

        Broadcast::query()
            ->whereKey($broadcast->id)
            ->where('status', BroadcastStatus::Sending)
            ->update(['status' => BroadcastStatus::Completed, 'completed_at' => now()]);
    }
}
