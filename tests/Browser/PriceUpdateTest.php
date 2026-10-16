<?php

use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Tests\Support\CannedStripeApi;

afterEach(fn () => ApiRequestor::setHttpClient(CurlClient::instance()));

test('a customer moves to the current plan price', function () {
    $stripe = CannedStripeApi::install('price_old', now()->addDays(12)->getTimestamp(), [
        'price_old' => 2000,
        'price_new' => 2500,
    ]);
    makeMonthlyPlan('price_new', 2500);
    $customer = makeSubscriber('price_old');

    $this->actingAs($customer);

    visit('/subscriptions')
        ->assertSee('Šī plāna cena ir mainījusies')
        ->click('Pāriet uz jauno cenu')
        ->assertSee('Jūsu abonements tagad izmanto jauno cenu.')
        ->assertNoJavaScriptErrors();

    expect($customer->subscription('default')->fresh()->price_cents)->toBe(2500);
    expect($stripe->writes())->not->toBeEmpty();
});
