<?php

use App\Filament\Resources\ReservationSettings\Pages\ManageReservationSettings;
use App\Filament\Resources\SubscriptionTypes\Pages\CreateSubscriptionType;
use App\Filament\Resources\SubscriptionTypes\Pages\EditSubscriptionType;
use App\Filament\Resources\SubscriptionTypes\Pages\ListSubscriptionTypes;
use App\Models\Purchase;
use App\Models\SubscriptionType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function planWithProduct(array $attributes = []): SubscriptionType
{
    $plan = makeSubscriptionType($attributes);
    $plan->forceFill(['stripe_product_id' => 'prod_'.$plan->id, 'stripe_price_id' => 'price_'.$plan->id])->saveQuietly();

    return $plan;
}

function buy(SubscriptionType $plan, array $attributes = []): Purchase
{
    return Purchase::create(array_merge([
        'user_id' => User::factory()->create()->id,
        'subscription_type_id' => $plan->id,
        'stripe_checkout_session_id' => 'cs_'.str()->random(8),
        'price_cents' => $plan->price_cents,
        'status' => 'active',
        'payment_status' => 'paid',
        'visits_remaining' => 1,
    ], $attributes));
}

function subscribeTo(SubscriptionType $plan): void
{
    $subscriptionId = DB::table('subscriptions')->insertGetId([
        'user_id' => User::factory()->create()->id,
        'type' => 'default',
        'stripe_id' => 'sub_'.str()->random(8),
        'stripe_status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('subscription_items')->insert([
        'subscription_id' => $subscriptionId,
        'stripe_id' => 'si_'.str()->random(8),
        'stripe_product' => $plan->stripe_product_id,
        'stripe_price' => $plan->stripe_price_id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function adminPage(): void
{
    test()->actingAs(User::factory()->create(['role' => 'admin']));
}

// ---------------------------------------------------------------- deleting

test('a plan nobody bought can be deleted', function () {
    $plan = planWithProduct();

    expect($plan->deletionBlocker())->toBeNull();

    $plan->delete();

    expect(SubscriptionType::find($plan->id))->toBeNull();
});

test('a plan with purchases cannot be deleted, and the reason is a validation message', function () {
    $plan = planWithProduct();
    buy($plan);
    buy($plan);

    expect($plan->deletionBlocker())->toContain('2 purchases');

    try {
        $plan->delete();
        $this->fail('Expected the delete to be refused.');
    } catch (ValidationException $e) {
        expect($e->errors()['plan'][0])->toContain('2 purchases')->toContain('Deactivate');
    }

    expect(SubscriptionType::find($plan->id))->not->toBeNull();
});

test('an unfinished checkout also keeps a plan from being deleted', function () {
    $plan = planWithProduct();
    buy($plan, ['status' => 'pending', 'payment_status' => 'unpaid']);

    expect($plan->deletionBlocker())->toContain('1 purchase');
});

test('a plan with subscribers cannot be deleted', function () {
    $plan = planWithProduct(['billing_interval' => 'month', 'visit_limit' => null]);
    subscribeTo($plan);

    expect($plan->deletionBlocker())->toContain('1 subscriber')
        ->and(fn () => $plan->delete())->toThrow(ValidationException::class);
});

test('the admin panel explains why a plan cannot be deleted instead of failing', function () {
    adminPage();
    $sold = planWithProduct(['name' => 'Sold pass']);
    buy($sold);
    $unsold = planWithProduct(['name' => 'Fresh pass']);

    Livewire::test(ListSubscriptionTypes::class)
        ->callTableAction('delete', $sold)
        ->assertNotified("Sold pass can't be deleted")
        ->callTableAction('delete', $unsold);

    expect(SubscriptionType::find($sold->id))->not->toBeNull()
        ->and(SubscriptionType::find($unsold->id))->toBeNull();
});

test('the database itself refuses to delete a plan that has purchases', function () {
    $plan = planWithProduct();
    buy($plan);

    expect(fn () => DB::table('subscription_types')->where('id', $plan->id)->delete())->toThrow(QueryException::class);
});

test('plans have no bulk delete', function () {
    adminPage();

    expect(Livewire::test(ListSubscriptionTypes::class)->instance()->getTable()->getBulkActions())->toBeEmpty();
});

// ------------------------------------------------------- locking the billing

test('the billing type is locked once a plan has sales', function () {
    $plan = planWithProduct();
    buy($plan);

    expect($plan->hasSales())->toBeTrue();

    try {
        $plan->update(['billing_interval' => 'month']);
        $this->fail('Expected the change to be refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('billing_interval');
    }

    expect($plan->fresh()->billing_interval)->toBe('one_time');
});

test('the billing type is locked for a plan with subscribers', function () {
    $plan = planWithProduct(['billing_interval' => 'month', 'visit_limit' => null]);
    subscribeTo($plan);

    expect(fn () => $plan->update(['billing_interval' => 'year']))->toThrow(ValidationException::class);
});

test('other details of a plan with sales can still be changed', function () {
    $plan = planWithProduct();
    buy($plan);

    $plan->update(['name' => 'Renamed pass', 'price_cents' => 700, 'active' => false]);

    expect($plan->fresh()->name)->toBe('Renamed pass');
});

test('an unfinished checkout alone does not lock the billing type', function () {
    $plan = planWithProduct();
    buy($plan, ['status' => 'pending', 'payment_status' => 'unpaid']);

    expect($plan->hasSales())->toBeFalse();

    $plan->update(['billing_interval' => 'month']);

    expect($plan->fresh()->billing_interval)->toBe('month');
});

test('the edit form shows the billing type as locked for a plan with sales', function () {
    adminPage();
    $sold = planWithProduct();
    buy($sold);
    $fresh = planWithProduct(['name' => 'Fresh']);

    Livewire::test(EditSubscriptionType::class, ['record' => $sold->id])
        ->assertFormFieldIsDisabled('billing_interval');

    Livewire::test(EditSubscriptionType::class, ['record' => $fresh->id])
        ->assertFormFieldIsEnabled('billing_interval');
});

test('saving the edit form of a plan with sales keeps its billing type', function () {
    adminPage();
    fakeStripe();
    $plan = planWithProduct();
    buy($plan);

    Livewire::test(EditSubscriptionType::class, ['record' => $plan->id])
        ->fillForm(['name' => 'Better name'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($plan->fresh()->billing_interval)->toBe('one_time')
        ->and($plan->fresh()->name)->toBe('Better name');
});

// ---------------------------------------------------------------- validation

test('a price under 50 cents is refused', function () {
    adminPage();
    fakeStripe();

    Livewire::test(CreateSubscriptionType::class)
        ->fillForm(['name' => 'Cheap', 'price_cents' => 0.49, 'billing_interval' => 'one_time', 'visit_limit' => 1])
        ->call('create')
        ->assertHasFormErrors(['price_cents']);

    Livewire::test(CreateSubscriptionType::class)
        ->fillForm(['name' => 'Cheap', 'price_cents' => 0.5, 'billing_interval' => 'one_time', 'visit_limit' => 1])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SubscriptionType::first()->price_cents)->toBe(50);
});

test('a one-time plan needs a visit limit of at least 1 unless it is unlimited', function () {
    adminPage();
    fakeStripe();

    foreach ([null, 0] as $limit) {
        Livewire::test(CreateSubscriptionType::class)
            ->fillForm(['name' => 'Pass', 'price_cents' => 5, 'billing_interval' => 'one_time', 'unlimited_entries' => false, 'visit_limit' => $limit])
            ->call('create')
            ->assertHasFormErrors(['visit_limit']);
    }

    Livewire::test(CreateSubscriptionType::class)
        ->fillForm(['name' => 'Ten visits', 'price_cents' => 5, 'billing_interval' => 'one_time', 'visit_limit' => 10])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateSubscriptionType::class)
        ->fillForm(['name' => 'Day pass', 'price_cents' => 5, 'billing_interval' => 'one_time', 'unlimited_entries' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateSubscriptionType::class)
        ->fillForm(['name' => 'Monthly', 'price_cents' => 20, 'billing_interval' => 'month'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SubscriptionType::where('name', 'Ten visits')->first()->visit_limit)->toBe(10)
        ->and(SubscriptionType::where('name', 'Day pass')->first()->visit_limit)->toBeNull()
        ->and(SubscriptionType::where('name', 'Monthly')->first()->visit_limit)->toBeNull();
});

test('a leftover visit limit and unlimited flag are cleared when a plan becomes recurring', function () {
    adminPage();
    fakeStripe();
    $plan = planWithProduct(['visit_limit' => 5, 'unlimited_entries' => false]);

    Livewire::test(EditSubscriptionType::class, ['record' => $plan->id])
        ->fillForm(['billing_interval' => 'month'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($plan->fresh()->billing_interval)->toBe('month')
        ->and($plan->fresh()->visit_limit)->toBeNull()
        ->and($plan->fresh()->unlimited_entries)->toBeFalse();
});

test('reservation pricing refuses a rate below the Stripe minimum', function () {
    adminPage();
    $settings = makeReservationSettings();

    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['price_cents_per_person_per_hour' => 0.4])
        ->assertHasTableActionErrors(['price_cents_per_person_per_hour']);

    expect($settings->fresh()->price_cents_per_person_per_hour)->toBe(500);
});

test('reservation pricing refuses a rate that makes the smallest booking cost under 50 cents', function () {
    adminPage();
    $settings = makeReservationSettings(['min_group_size' => 1, 'min_duration_minutes' => 15]);

    // €1.50/h for 1 person for 15 minutes is 38 cents.
    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['price_cents_per_person_per_hour' => 1.5])
        ->assertHasTableActionErrors(['price_cents_per_person_per_hour']);

    // €2.00/h is exactly 50 cents.
    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['price_cents_per_person_per_hour' => 2])
        ->assertHasNoTableActionErrors();

    expect($settings->fresh()->price_cents_per_person_per_hour)->toBe(200);
});

test('reservation pricing keeps its other minimums', function () {
    adminPage();
    $settings = makeReservationSettings();

    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['min_group_size' => 0, 'max_group_size' => 0, 'min_duration_minutes' => 5, 'max_duration_minutes' => 5])
        ->assertHasTableActionErrors(['min_group_size', 'max_group_size', 'min_duration_minutes', 'max_duration_minutes']);
});

test('reservation pricing refuses a closing time that is not after the opening time', function () {
    adminPage();
    $settings = makeReservationSettings();

    // Inverted (the park "opens" at 23:00 and "closes" at 08:00), equal, and a normal range.
    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['opening_time' => '23:00', 'closing_time' => '08:00'])
        ->assertHasTableActionErrors(['closing_time']);
    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['opening_time' => '10:00', 'closing_time' => '10:00'])
        ->assertHasTableActionErrors(['closing_time']);

    expect($settings->fresh()->opening_time)->toBe('08:00')
        ->and($settings->fresh()->closing_time)->toBe('23:00');

    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['opening_time' => '09:00', 'closing_time' => '22:30'])
        ->assertHasNoTableActionErrors();

    expect($settings->fresh()->closing_time)->toBe('22:30');
});

test('reservation pricing refuses hours that would cut into a paid reservation still to come', function () {
    adminPage();
    $settings = makeReservationSettings();
    $owner = User::factory()->create();

    // Tomorrow 10:00-12:00 and 21:00-23:00, both paid for under 08:00-23:00.
    makeReservation($owner, now()->addDay()->setTime(10, 0), now()->addDay()->setTime(12, 0));
    makeReservation($owner, now()->addDay()->setTime(21, 0), now()->addDay()->setTime(23, 0));

    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['closing_time' => '22:00'])
        ->assertHasTableActionErrors(['closing_time']);
    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['opening_time' => '11:00'])
        ->assertHasTableActionErrors(['opening_time']);

    expect($settings->fresh()->opening_time)->toBe('08:00')
        ->and($settings->fresh()->closing_time)->toBe('23:00');

    // Hours that still hold both of them go through.
    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['opening_time' => '10:00', 'closing_time' => '23:00'])
        ->assertHasNoTableActionErrors();

    expect($settings->fresh()->opening_time)->toBe('10:00');
});

test('reservation hours ignore reservations that are cancelled, unpaid or already over', function () {
    adminPage();
    $settings = makeReservationSettings();
    $owner = User::factory()->create();

    makeReservation($owner, now()->addDay()->setTime(21, 0), now()->addDay()->setTime(23, 0), ['status' => 'cancelled']);
    makeReservation($owner, now()->addDays(2)->setTime(21, 0), now()->addDays(2)->setTime(23, 0), ['status' => 'pending']);
    makeReservation($owner, now()->subDay()->setTime(21, 0), now()->subDay()->setTime(23, 0));

    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, ['closing_time' => '20:00'])
        ->assertHasNoTableActionErrors();

    expect($settings->fresh()->closing_time)->toBe('20:00');
});

test('reservation pricing caps values that could only be typos', function () {
    adminPage();
    $settings = makeReservationSettings();

    Livewire::test(ManageReservationSettings::class)
        ->callTableAction('edit', $settings, [
            'price_cents_per_person_per_hour' => 500,
            'min_group_size' => 600,
            'max_group_size' => 600,
            'min_duration_minutes' => 2000,
            'max_duration_minutes' => 2000,
            'cancellation_cutoff_hours' => 9999,
        ])
        ->assertHasTableActionErrors(['price_cents_per_person_per_hour', 'min_group_size', 'max_group_size', 'min_duration_minutes', 'max_duration_minutes', 'cancellation_cutoff_hours']);

    expect($settings->fresh()->price_cents_per_person_per_hour)->toBe(500)
        ->and($settings->fresh()->max_group_size)->toBe(50);
});

test('a plan cannot be priced above the maximum', function () {
    Livewire::actingAs(User::factory()->create(['role' => 'admin']));
    fakeStripe();

    Livewire::test(CreateSubscriptionType::class)
        ->fillForm(['name' => 'Too dear', 'price_cents' => 1000000000, 'billing_interval' => 'month'])
        ->call('create')
        ->assertHasFormErrors(['price_cents']);

    Livewire::test(CreateSubscriptionType::class)
        ->fillForm(['name' => 'Just right', 'price_cents' => 1000, 'billing_interval' => 'month'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(SubscriptionType::where('name', 'Too dear')->exists())->toBeFalse()
        ->and(SubscriptionType::where('name', 'Just right')->value('price_cents'))->toBe(100000);
});
