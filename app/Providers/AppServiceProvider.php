<?php

namespace App\Providers;

use App\Filament\Auth\LogoutResponse;
use Carbon\CarbonImmutable;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        // Send admins to the regular login page (not Filament's own admin
        // login) after signing out of the panel, since it's the same app.
        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        Event::listen(Registered::class, SendEmailVerificationNotification::class);

        DevCommands::artisan('reverb:start --debug', 'reverb');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);
        $this->applyFakedTimeForLocalTesting();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(10)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->uncompromised(),
        );
    }

    /**
     * Chat limiters are named, not `throttle:N,M`: numeric throttles are keyed
     * by user alone, so every one of them would share a single counter with
     * the reservation and subscription pages' own throttle.
     */
    protected function configureRateLimiting(): void
    {
        // Posting a message, in either the global room or a reservation's
        // group chat. Slow mode only exists in the global room and only when
        // the room is busy; this is the always-on ceiling for both.
        RateLimiter::for('chat-send', fn (Request $request) => Limit::perMinute(20)->by('send:'.($request->user()->id ?? $request->ip())));

        // Looking up customers to invite: a search box that fires as someone
        // types, so it gets a budget of its own, and a small one - it's also
        // the only way to enumerate customers by name.
        RateLimiter::for('user-search', fn (Request $request) => Limit::perMinute(20)->by('user-search:'.($request->user()->id ?? $request->ip())));

        // The staff scanner fires a request per decoded frame it hasn't
        // already handled, so this is generous: it is there to stop a stuck
        // loop or a script, not a busy door.
        RateLimiter::for('scan', fn (Request $request) => Limit::perMinute(120)->by('scan:'.($request->user()->id ?? $request->ip())));

        // Everything else in the global room: loading it, moderating it.
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(90)->by('chat:'.($request->user()->id ?? $request->ip())));
    }

    /**
     * Lets `php artisan time:fake` shift what now() returns, so time-dependent
     * features (like reservation exclusivity) can be tested without waiting
     * for the real clock. No-op unless a fake time has been set, and disabled
     * entirely in production.
     */
    protected function applyFakedTimeForLocalTesting(): void
    {
        if ($this->app->isProduction()) {
            return;
        }

        $path = storage_path('framework/testing/fake-now.txt');

        if (is_file($path)) {
            Date::setTestNow(CarbonImmutable::parse(trim(file_get_contents($path) ?: '')));
        }
    }
}
