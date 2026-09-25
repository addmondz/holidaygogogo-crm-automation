<?php

use Illuminate\Support\Facades\Schedule;

// Requires the cron entry: * * * * * php /path/to/artisan schedule:run
Schedule::command('broadcasts:dispatch-due')->everyMinute()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes()
    ->when(fn () => config('queue.default') === 'redis');

Schedule::command('queue:prune-failed', ['--hours' => 24 * 14])->daily();
