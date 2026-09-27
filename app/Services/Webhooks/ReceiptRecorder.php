<?php

namespace App\Services\Webhooks;

use App\Enums\MessageStatus;
use App\Enums\RecipientStatus;
use App\Events\MessageSaved;
use App\Models\BroadcastRecipient;
use App\Models\Message;
use App\Support\Realtime;

/**
 * Applies delivery/read receipts to outbound messages (and their blast rows).
 */
class ReceiptRecorder
{
    public function apply(Message $message, MessageStatus $status, ?string $error = null): void
    {
        if (! $message->advanceStatus($status, $error)) {
            return;
        }

        $recipientStatus = RecipientStatus::tryFrom($status->value);

        if ($recipientStatus) {
            BroadcastRecipient::query()
                ->where('message_id', $message->id)
                ->whereNotIn('status', [RecipientStatus::Failed, RecipientStatus::Skipped])
                ->when($status !== MessageStatus::Failed, fn ($q) => $q->whereIn('status', $this->lowerThan($recipientStatus)))
                ->update(['status' => $recipientStatus, 'error' => $error]);
        }

        Realtime::send(new MessageSaved($message));
    }

    /**
     * @return list<RecipientStatus>
     */
    private function lowerThan(RecipientStatus $status): array
    {
        $order = [RecipientStatus::Pending, RecipientStatus::Sent, RecipientStatus::Delivered, RecipientStatus::Read];

        return array_slice($order, 0, (int) array_search($status, $order, true));
    }
}
