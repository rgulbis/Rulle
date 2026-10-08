<?php

use App\Models\SubscriptionType;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SubscriptionTypeSeeder;

test('the seeder creates the launch plans, bilingual and active', function () {
    config(['cashier.secret' => null]);

    (new SubscriptionTypeSeeder)->run();

    $plans = SubscriptionType::orderBy('price_cents')->get();

    expect($plans->pluck('name')->all())->toBe(['Day pass', 'Monthly pass', 'Yearly pass'])
        ->and($plans->pluck('price_cents')->all())->toBe([800, 3500, 30000])
        ->and($plans->pluck('billing_interval')->all())->toBe(['one_time', 'month', 'year'])
        ->and($plans->every(fn (SubscriptionType $plan) => $plan->active && filled($plan->name_lv) && filled($plan->description_lv)))->toBeTrue()
        ->and($plans->first()->unlimited_entries)->toBeTrue();
});

test('seeding again neither duplicates plans nor overwrites admin edits', function () {
    config(['cashier.secret' => null]);

    (new SubscriptionTypeSeeder)->run();
    SubscriptionType::where('name', 'Day pass')->update(['price_cents' => 1000, 'active' => false]);

    (new SubscriptionTypeSeeder)->run();

    expect(SubscriptionType::count())->toBe(3)
        ->and(SubscriptionType::where('name', 'Day pass')->first())
        ->price_cents->toBe(1000)
        ->active->toBeFalse();
});

test('with Stripe configured the seeded plans get a Stripe product and price', function () {
    config(['cashier.secret' => 'sk_test_fake']);
    fakeStripe();

    (new SubscriptionTypeSeeder)->run();

    expect(SubscriptionType::count())->toBe(3)
        ->and(SubscriptionType::all()->every(fn (SubscriptionType $plan) => ! $plan->needsStripeSync()))->toBeTrue();
});

test('a Stripe outage does not stop the plans from being seeded', function () {
    config(['cashier.secret' => 'sk_test_fake']);
    fakeStripe()->priceFails = true;

    (new SubscriptionTypeSeeder)->run();

    expect(SubscriptionType::count())->toBe(3)
        ->and(SubscriptionType::all()->every(fn (SubscriptionType $plan) => $plan->needsStripeSync()))->toBeTrue();
});

test('the database seeder includes the plans', function () {
    config(['cashier.secret' => null, 'app.seed_password' => 'a-test-seed-password']);

    (new DatabaseSeeder)->run();

    expect(SubscriptionType::count())->toBe(3);
});
