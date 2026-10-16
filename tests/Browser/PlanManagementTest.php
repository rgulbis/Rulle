<?php

use App\Models\SubscriptionType;
use App\Models\User;

test('an admin creates a subscription plan', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $form = visit('/login')
        ->fill('email', $admin->email)
        ->fill('password', 'password')
        ->click('button[type="submit"]')
        ->navigate('/admin/subscription-types/create');

    // Saving without a name is refused by the form and nothing is stored.
    $form->fill('[id="form.price_cents"]', '30')
        ->select('[id="form.billing_interval"]', 'month')
        ->click('button[type="submit"][x-data="filamentFormButton"]')
        ->assertPresent('[id="form.name"]:invalid');
    expect(SubscriptionType::count())->toBe(0);

    // With a name the plan is saved.
    $form->fill('[id="form.name"]', 'Monthly pass')
        ->click('button[type="submit"][x-data="filamentFormButton"]')
        ->assertSee('Izveidots')
        ->assertValue('[id="form.name"]', 'Monthly pass')
        ->assertNoJavaScriptErrors();

    $plan = SubscriptionType::firstWhere('name', 'Monthly pass');
    expect($plan->price_cents)->toBe(3000);
    expect($plan->billing_interval)->toBe('month');

    // The new plan is now offered to customers.
    $this->actingAs(User::factory()->create());
    visit('/subscriptions')->assertSee('Monthly pass');
});
