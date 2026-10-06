<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\SubscriptionType;
use App\Models\User;
use App\Support\Payments\StripeGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('e2e:seed')]
#[Description('Seed the throwaway database used by the browser tests (E2E mode only)')]
class E2eSeed extends Command
{
    public const PASSWORD = 'E2e-Passw0rd!x';

    public function handle(StripeGateway $stripe): int
    {
        if (! config('services.e2e') || app()->isProduction()) {
            $this->error('Only available in E2E mode.');

            return self::FAILURE;
        }

        ReservationSetting::current();

        $customer = $this->user('customer', 'user');
        $this->user('staff', 'employee');
        $this->user('friend', 'user');

        // A day pass worth one visit — the customer can be scanned in once.
        $type = SubscriptionType::withoutEvents(fn () => SubscriptionType::create([
            'name' => 'E2E pass', 'price_cents' => 500, 'billing_interval' => 'one_time',
            'visit_limit' => 1, 'unlimited_entries' => false, 'active' => true,
        ]));
        Purchase::create([
            'user_id' => $customer->id, 'subscription_type_id' => $type->id,
            'stripe_checkout_session_id' => 'cs_e2e_pass', 'status' => 'active', 'visits_remaining' => 1,
            'price_cents' => 500, 'payment_status' => 'paid', 'paid_at' => now(),
        ]);

        // A reservation three days out that the customer started but hasn't paid.
        $start = now()->addDays(3)->setTime(12, 0);
        $reservation = Reservation::create([
            'user_id' => $customer->id, 'starts_at' => $start, 'ends_at' => $start->addHour(),
            'group_size' => 3, 'price_cents' => 1500, 'status' => 'pending',
        ]);
        $session = $stripe->createReservationCheckout(
            $customer, 1500, 'E2E reservation', url('/reservations/success').'?session_id={CHECKOUT_SESSION_ID}',
            url('/reservations'), now()->addMinutes(30)->getTimestamp(),
        );
        $reservation->update(['stripe_checkout_session_id' => $session['id']]);

        $this->info('E2E data seeded.');

        return self::SUCCESS;
    }

    private function user(string $name, string $role): User
    {
        return User::factory()->create([
            'name' => "E2E {$name}",
            'email' => "e2e-{$name}@test.test",
            'role' => $role,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ]);
    }
}
