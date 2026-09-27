<?php

namespace App\Jobs;

use App\Enums\BroadcastStatus;
use App\Enums\RecipientStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Services\Broadcasts\Audience;
use App\Services\Broadcasts\BroadcastLauncher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Takes a snapshot of the audience and queues one job per recipient.
 */
class StartBroadcast implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public Broadcast $broadcast)
    {
        $this->onQueue('broadcasts');
    }

    public function handle(): void
    {
        $broadcast = $this->broadcast->fresh('channel');

        if (! $broadcast || $broadcast->status !== BroadcastStatus::Sending) {
            return;
        }

        $now = now();

        Audience::query($broadcast->channel, $broadcast->audience ?? [])
            ->select('contacts.id')
            ->orderBy('contacts.id')
            ->chunkById(1000, function ($contacts) use ($broadcast, $now) {
                DB::table('broadcast_recipients')->insertOrIgnore($contacts->map(fn ($contact) => [
                    'broadcast_id' => $broadcast->id,
                    'contact_id' => $contact->id,
                    'status' => RecipientStatus::Pending->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }, 'contacts.id', 'id');

        $broadcast->update(['total_recipients' => $broadcast->recipients()->count()]);

        BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->id)
            ->where('status', RecipientStatus::Pending)
            ->select('id')
            ->chunkById(500, function ($recipients) use ($broadcast) {
                foreach ($recipients as $recipient) {
                    SendBroadcastMessage::dispatch($recipient->id, $broadcast->channel_id);
                }
            });

        BroadcastLauncher::completeIfFinished($broadcast);
    }

    public function failed(): void
    {
        $this->broadcast->update(['status' => BroadcastStatus::Failed]);
    }
}
