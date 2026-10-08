<?php

use App\Models\Purchase;
use App\Models\Reservation;
use App\Models\User;

function pendingPassCheckout(string $session, int $hoursOld): Purchase
{
    $purchase = Purchase::create([
        'user_id' => User::factory()->create()->id,
        'subscription_type_id' => makeSubscriptionType()->id,
        'stripe_checkout_session_id' => $session,
        'price_cents' => 800,
        'status' => 'pending',
    ]);
    Purchase::whereKey($purchase->id)->update(['created_at' => now()->subHours($hoursOld)]);

    return $purchase;
}

test('unfinished checkouts older than the Stripe session lifetime are closed', function () {
    $oldPass = pendingPassCheckout('cs_old_pass', 26);
    $freshPass = pendingPassCheckout('cs_fresh_pass', 3);

    $oldReservation = makeReservation(User::factory()->create(), now()->addDays(2), now()->addDays(2)->addHour(), ['status' => 'pending']);
    $freshReservation = makeReservation(User::factory()->create(), now()->addDays(3), now()->addDays(3)->addHour(), ['status' => 'pending']);
    Reservation::whereKey($oldReservation->id)->update(['created_at' => now()->subHours(26)]);

    $this->artisan('payments:expire-abandoned')->assertSuccessful();

    expect($oldPass->fresh()->status)->toBe('abandoned')
        ->and($freshPass->fresh()->status)->toBe('pending')
        ->and($oldReservation->fresh()->status)->toBe('cancelled')
        ->and($freshReservation->fresh()->status)->toBe('pending');
});

test('paid and finished rows are left alone', function () {
    $paid = pendingPassCheckout('cs_paid_pass', 40);
    Purchase::whereKey($paid->id)->update(['status' => 'active']);

    $this->artisan('payments:expire-abandoned')->assertSuccessful();

    expect($paid->fresh()->status)->toBe('active');
});
