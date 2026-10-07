<?php

namespace App\Filament\Resources\SubscriptionTypes\Actions;

use App\Models\SubscriptionType;
use App\Support\Payments\PlanStripeSync;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Pushes a plan to Stripe and tells the admin how it went. A plan is saved
 * whether or not Stripe is reachable; when it isn't, this is the retry (and
 * `plans:sync-stripe` runs the same thing on a schedule).
 */
class SyncToStripeAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'syncToStripe';
    }

    /**
     * Used right after a plan is saved from the admin pages: says something
     * only when it did not work.
     */
    public static function afterSave(SubscriptionType $plan): void
    {
        if ($plan->needsStripeSync()) {
            static::attempt($plan);
        }
    }

    public static function attempt(SubscriptionType $plan): bool
    {
        try {
            app(PlanStripeSync::class)->sync($plan);
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->warning()
                ->title('Saved, but not synced to Stripe yet')
                ->body('Stripe couldn\'t be reached or refused the plan. It stays off sale until it is synced; use "Sync to Stripe" to retry, or it is retried automatically.')
                ->persistent()
                ->send();

            return false;
        }

        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Sync to Stripe');
        $this->icon('heroicon-o-arrow-path');
        $this->visible(fn (SubscriptionType $record): bool => $record->needsStripeSync());

        $this->action(function (SubscriptionType $record) {
            if (static::attempt($record)) {
                Notification::make()->success()->title('Synced to Stripe')->send();
            }
        });
    }
}
