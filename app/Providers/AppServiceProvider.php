<?php

namespace App\Providers;

use App\Filament\Auth\LogoutResponse;
use App\Support\Payments\E2eStripeGateway;
use App\Support\Payments\StripeGateway;
use Carbon\CarbonImmutable;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
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

        $this->registerE2eMode();
    }

    /**
     * Browser tests (tests/e2e, run with `npm run e2e`) start the app with
     * E2E_MODE=1: Stripe is replaced by a fake that needs no network, with its
     * own payment page, and the compiled assets are used even if a Vite dev
     * server is running. Refuses to run in production — a fake payment
     * provider there would let anyone "pay" for free.
     */
    private function registerE2eMode(): void
    {
        if (! config('services.e2e')) {
            return;
        }

        if ($this->app->isProduction()) {
            throw new \LogicException('E2E_MODE must never be enabled in production.');
        }

        $this->app->singleton(StripeGateway::class, E2eStripeGateway::class);

        $this->app->booted(function () {
            Vite::useHotFile(storage_path('framework/e2e-no-hot'));
            Route::middleware('web')->group(base_path('routes/e2e.php'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

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
