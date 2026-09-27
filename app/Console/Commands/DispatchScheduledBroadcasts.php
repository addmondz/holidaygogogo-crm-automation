<?php

namespace App\Console\Commands;

use App\Enums\BroadcastStatus;
use App\Jobs\StartBroadcast;
use App\Models\Broadcast;
use Illuminate\Console\Command;

class DispatchScheduledBroadcasts extends Command
{
    protected $signature = 'broadcasts:dispatch-due';

    protected $description = 'Start scheduled blasts whose time has come (runs every minute)';

    public function handle(): int
    {
        $due = Broadcast::query()
            ->where('status', BroadcastStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $broadcast) {
            // Guard against two schedulers picking up the same blast.
            $claimed = Broadcast::query()
                ->whereKey($broadcast->id)
                ->where('status', BroadcastStatus::Scheduled)
                ->update(['status' => BroadcastStatus::Sending, 'started_at' => now()]);

            if ($claimed) {
                StartBroadcast::dispatch($broadcast);
                $this->components->info("Started blast \"{$broadcast->name}\".");
            }
        }

        return self::SUCCESS;
    }
}
