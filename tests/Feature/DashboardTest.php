<?php

use App\Models\CheckInEvent;
use App\Models\Purchase;
use App\Models\User;

test('the shared auth user prop reflects current check-in status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.user.checked_in', false)
    );

    CheckInEvent::create(['user_id' => $user->id, 'checked_in' => true]);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('auth.user.checked_in', true)
    );
});

test('the dashboard shows no pass and no booking for a new customer', function () {
    $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('pass', null)
        ->where('nextReservation', null)
    );
});

test("the dashboard shows the customer's usable one-time pass", function () {
    $user = User::factory()->create();
    $type = makeSubscriptionType(['name' => '5 visits', 'name_lv' => '5 apmeklējumi', 'visit_limit' => 5]);
    Purchase::create([
        'user_id' => $user->id,
        'subscription_type_id' => $type->id,
        'stripe_checkout_session_id' => 'cs_test_dashboard',
        'status' => 'active',
        'visits_remaining' => 3,
    ]);

    $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
        ->where('pass.kind', 'purchase')
        ->where('pass.name', '5 visits')
        ->where('pass.name_lv', '5 apmeklējumi')
        ->where('pass.visits_remaining', 3)
    );
});

test('the dashboard shows the next reservation the customer is part of', function () {
    $owner = User::factory()->create();
    $participant = User::factory()->create();
    $later = makeReservation($owner, now()->addDays(3), now()->addDays(3)->addHour());
    $sooner = makeReservation($owner, now()->addDay(), now()->addDay()->addHours(2), ['group_size' => 6]);
    $sooner->participants()->attach($participant);
    // Cancelled and past ones never count as "next".
    makeReservation($owner, now()->addHours(2), now()->addHours(3), ['status' => 'cancelled']);
    makeReservation($owner, now()->subDays(2), now()->subDays(2)->addHour());

    foreach ([$owner, $participant] as $user) {
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('nextReservation.id', $sooner->id)
            ->where('nextReservation.group_size', 6)
            ->missing('nextReservation.user_id')
        );
    }

    expect($later->id)->not->toBe($sooner->id);
});
