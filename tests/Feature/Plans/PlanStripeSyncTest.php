<?php

use App\Filament\Resources\SubscriptionTypes\Pages\CreateSubscriptionType;
use App\Filament\Resources\SubscriptionTypes\Pages\EditSubscriptionType;
use App\Filament\Resources\SubscriptionTypes\Pages\ListSubscriptionTypes;
use App\Models\SubscriptionType;
use App\Models\User;
use App\Support\Payments\PlanStripeSync;
use Livewire\Livewire;
use Stripe\Exception\ApiConnectionException;

test('saving a plan never talks to Stripe', function () {
    $stripe = fakeStripe();

    $plan = SubscriptionType::create(['name' => 'Pass', 'price_cents' => 500, 'billing_interval' => 'one_time', 'visit_limit' => 1]);
    $plan->update(['name' => 'Better pass', 'price_cents' => 600]);

    expect($stripe->products)->toBeEmpty()
        ->and($stripe->prices)->toBeEmpty()
        ->and($plan->fresh()->needsStripeSync())->toBeTrue();
});

test('a new plan gets a Product and a Price, and is then in step', function () {
    $stripe = fakeStripe();
    $plan = makeSubscriptionType(['name' => 'Ten visits', 'price_cents' => 4000]);

    app(PlanStripeSync::class)->sync($plan);

    $plan->refresh();
    expect($plan->stripe_product_id)->not->toBeNull()
        ->and($plan->stripe_price_id)->not->toBeNull()
        ->and($plan->needsStripeSync())->toBeFalse()
        ->and($stripe->productNames[$plan->stripe_product_id])->toBe('Ten visits')
        ->and(array_values($stripe->prices)[0])->toMatchArray(['amount' => 4000, 'interval' => null]);
});

test('a recurring plan gets a recurring Price', function () {
    $stripe = fakeStripe();
    $plan = makeSubscriptionType(['name' => 'Monthly', 'price_cents' => 2500, 'billing_interval' => 'month', 'visit_limit' => null]);

    app(PlanStripeSync::class)->sync($plan);

    expect(array_values($stripe->prices)[0])->toMatchArray(['amount' => 2500, 'interval' => 'month']);
});

test('renaming a plan renames its Product and does not create another Price', function () {
    $stripe = fakeStripe();
    $plan = makeSubscriptionType(['name' => 'Old name']);
    app(PlanStripeSync::class)->sync($plan);
    $priceId = $plan->fresh()->stripe_price_id;

    $plan->refresh()->update(['name' => 'New name']);

    expect($plan->needsStripeSync())->toBeTrue();

    app(PlanStripeSync::class)->sync($plan);

    expect($plan->fresh()->stripe_price_id)->toBe($priceId)
        ->and($stripe->prices)->toHaveCount(1)
        ->and($stripe->products)->toHaveCount(1)
        ->and($stripe->productNames[$plan->stripe_product_id])->toBe('New name')
        ->and($plan->fresh()->needsStripeSync())->toBeFalse();
});

test('changing the price or the interval creates a new Price on the same Product', function () {
    $stripe = fakeStripe();
    $plan = makeSubscriptionType(['price_cents' => 500]);
    app(PlanStripeSync::class)->sync($plan);
    $first = $plan->fresh();

    $plan->refresh()->update(['price_cents' => 700]);
    app(PlanStripeSync::class)->sync($plan);

    $second = $plan->fresh();
    expect($second->stripe_product_id)->toBe($first->stripe_product_id)
        ->and($second->stripe_price_id)->not->toBe($first->stripe_price_id)
        ->and($stripe->products)->toHaveCount(1)
        ->and($stripe->prices)->toHaveCount(2);
});

test('syncing a plan that is already in step does nothing', function () {
    $stripe = fakeStripe();
    $plan = makeSubscriptionType();
    app(PlanStripeSync::class)->sync($plan);
    $calls = [count($stripe->products), count($stripe->prices)];

    app(PlanStripeSync::class)->sync($plan->refresh());

    expect([count($stripe->products), count($stripe->prices)])->toBe($calls);
});

test('when the Price call fails the Product is kept and the retry reuses it', function () {
    $stripe = fakeStripe();
    $stripe->priceFails = true;
    $plan = makeSubscriptionType();

    expect(fn () => app(PlanStripeSync::class)->sync($plan))->toThrow(ApiConnectionException::class);

    $afterFailure = $plan->fresh();
    expect($afterFailure->stripe_product_id)->not->toBeNull()
        ->and($afterFailure->stripe_price_id)->toBeNull()
        ->and($afterFailure->needsStripeSync())->toBeTrue();

    $stripe->priceFails = false;
    app(PlanStripeSync::class)->sync($plan->refresh());

    expect($stripe->products)->toHaveCount(1)
        ->and($plan->fresh()->stripe_product_id)->toBe($afterFailure->stripe_product_id)
        ->and($plan->fresh()->stripe_price_id)->not->toBeNull()
        ->and($plan->fresh()->needsStripeSync())->toBeFalse();
});

test('the scheduled command syncs every plan that is out of step and reports failures', function () {
    $stripe = fakeStripe();
    $needs = makeSubscriptionType(['name' => 'Needs sync']);
    $done = makeSubscriptionType(['name' => 'Already done']);
    app(PlanStripeSync::class)->sync($done);
    $productsBefore = count($stripe->products);

    $this->artisan('plans:sync-stripe')->assertSuccessful();

    expect($needs->fresh()->needsStripeSync())->toBeFalse()
        ->and(count($stripe->products))->toBe($productsBefore + 1);

    $stripe->priceFails = true;
    makeSubscriptionType(['name' => 'Will fail']);

    $this->artisan('plans:sync-stripe')->assertFailed();
});

test('creating a plan in the admin panel saves it even if Stripe is down, and says so', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']));
    $stripe = fakeStripe();
    $stripe->priceFails = true;

    Livewire::test(CreateSubscriptionType::class)
        ->fillForm(['name' => 'Unlucky', 'price_cents' => 12, 'billing_interval' => 'one_time', 'visit_limit' => 3])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved, but not synced to Stripe yet');

    $plan = SubscriptionType::where('name', 'Unlucky')->first();
    expect($plan)->not->toBeNull()
        ->and($plan->needsStripeSync())->toBeTrue();
});

test('a plan edited in the admin panel is synced straight away', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']));
    $stripe = fakeStripe();
    $plan = makeSubscriptionType(['name' => 'Before']);
    app(PlanStripeSync::class)->sync($plan);

    Livewire::test(EditSubscriptionType::class, ['record' => $plan->id])
        ->fillForm(['name' => 'After'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($stripe->productNames[$plan->fresh()->stripe_product_id])->toBe('After')
        ->and($plan->fresh()->needsStripeSync())->toBeFalse();
});

test('the Sync to Stripe action retries a plan that is not in step and is hidden when there is nothing to do', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']));
    fakeStripe();
    $pending = makeSubscriptionType(['name' => 'Pending']);
    $synced = makeSubscriptionType(['name' => 'Synced']);
    app(PlanStripeSync::class)->sync($synced);

    Livewire::test(ListSubscriptionTypes::class)
        ->assertTableActionVisible('syncToStripe', $pending)
        ->assertTableActionHidden('syncToStripe', $synced)
        ->callTableAction('syncToStripe', $pending)
        ->assertNotified('Synced to Stripe');

    expect($pending->fresh()->needsStripeSync())->toBeFalse();
});
