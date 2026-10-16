<?php

use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Tests\Support\CannedStripeApi;

afterEach(fn () => ApiRequestor::setHttpClient(CurlClient::instance()));

test('a customer cancels a subscription', function () {
    $periodEnd = now()->addDays(12)->getTimestamp();
    $stripe = CannedStripeApi::install('price_test', $periodEnd);
    $customer = makeSubscriber('price_test');

    $this->actingAs($customer);

    $page = visit('/subscriptions')->assertSee('Aktīvs abonements (active)');

    // The page asks "Are you sure?" in a browser dialog; answer yes.
    $page->script('window.confirm = () => true');

    $page->click('Atcelt abonementu')
        ->assertSee('Jūsu abonements ir atcelts.')
        ->assertSee('Atjaunot abonementu')
        ->assertNoJavaScriptErrors();

    $subscription = $customer->subscription('default')->fresh();
    expect($subscription->canceled())->toBeTrue();
    expect($subscription->ends_at->getTimestamp())->toBe($periodEnd);
    expect($stripe->writes()[0]['params'])->toMatchArray(['cancel_at_period_end' => 'true']);
});
