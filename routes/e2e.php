<?php

use App\Support\Payments\CheckoutFulfillment;
use App\Support\Payments\E2eStripeGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
| A fake Stripe-hosted payment page and a couple of inspection endpoints for
| the browser tests. Registered only in E2E mode (never in production) — see
| AppServiceProvider::registerE2eMode().
*/

Route::get('__e2e/pay/{session}', function (string $session) {
    abort_unless(Cache::has("e2e-stripe:session:{$session}"), 404);

    $csrf = csrf_field();

    return <<<HTML
        <!doctype html><title>Fake Stripe Checkout</title>
        <h1>Fake Stripe Checkout</h1>
        <form method="post" action="/__e2e/pay/{$session}">
            {$csrf}
            <button type="submit" name="outcome" value="return">Pay and return to the site</button>
            <button type="submit" name="outcome" value="close-tab">Pay, then close the tab (no redirect)</button>
        </form>
    HTML;
})->name('e2e.pay');

// What Stripe does after a successful payment: the webhook (always) and the
// browser redirect to success_url (unless the customer closed the tab first).
Route::post('__e2e/pay/{session}', function (Request $request, string $session) {
    $paid = E2eStripeGateway::markPaid($session) ?? abort(404);

    app(CheckoutFulfillment::class)->fulfill($session, $paid['payment_intent']);

    if ($request->input('outcome') === 'close-tab') {
        return '<!doctype html><title>Paid</title><p id="paid">Paid — tab closed before the redirect.</p>';
    }

    return redirect(str_replace('{CHECKOUT_SESSION_ID}', $session, $paid['success_url']));
});

Route::get('__e2e/refunds', fn () => response()->json(Cache::get('e2e-stripe:refunds', [])));
