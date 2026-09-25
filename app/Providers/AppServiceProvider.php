<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Without Reverb keys, fall back to "refresh every few seconds" rather
        // than failing every request.
        if (config('broadcasting.default') === 'reverb' && blank(config('broadcasting.connections.reverb.key'))) {
            config(['broadcasting.default' => 'log']);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureDevCommands();
    }

    /**
     * `composer dev` runs the web server, Vite, Reverb, the scheduler and a
     * queue worker: Horizon when the queue is Redis, `queue:listen` otherwise.
     */
    protected function configureDevCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        DevCommands::artisan('queue:listen --queue=webhooks,messages,broadcasts,default --tries=1 --timeout=0', 'queue');
        DevCommands::artisan('schedule:work', 'scheduler');
        DevCommands::except(config('queue.default') === 'redis' ? 'queue' : 'horizon');
    }

    protected function configureRateLimiting(): void
    {
        // Meta can send bursts of webhooks; this only stops abuse.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(1200)->by($request->ip()));

        // Blast speed per channel. Uses the cache store, so it works with
        // both the Redis and the database queue.
        RateLimiter::for('broadcasts', fn (object $job) => Limit::perMinute(
            max(1, (int) config('crm.broadcast_per_minute')),
        )->by('broadcast-channel:'.($job->channelId ?? 'default')));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
