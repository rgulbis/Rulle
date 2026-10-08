<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LivestreamController;
use App\Http\Controllers\Reservations\ReservationChatController;
use App\Http\Controllers\Reservations\ReservationController;
use App\Http\Controllers\Reservations\ReservationInvitationController;
use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Staff\ScanController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\Subscriptions\SubscriptionController;
use App\Http\Middleware\SetLocale;
use App\Models\ChatMessage;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// `{globalMessage}` only ever resolves a message from the global room. A
// reservation's private group chat message id 404s on every global-chat route
// by construction, so no controller method has to remember to check.
Route::bind('globalMessage', fn (string $value) => ChatMessage::whereNull('reservation_id')->findOrFail($value));

// Language switch for pages that aren't React (the Filament admin): sets the
// cookie the public site's toggle also writes, then returns to where the
// visitor came from.
Route::get('locale/{locale}', function (Request $request, string $locale) {
    abort_unless(in_array($locale, SetLocale::SUPPORTED, true), 404);

    return redirect()->back(fallback: '/')->withCookie(cookie('locale', $locale, 60 * 24 * 365, '/', null, null, false, false, 'lax'));
})->name('locale.set');

// Served from here (not a static file) so the sitemap line carries the real
// site address, and so anything that isn't production tells crawlers to stay
// away.
Route::get('robots.txt', function () {
    $lines = app()->isProduction()
        ? [
            'User-agent: *',
            'Disallow: /admin',
            'Disallow: /dashboard',
            'Disallow: /settings',
            'Disallow: /staff',
            'Disallow: /chat',
            'Disallow: /subscriptions',
            'Disallow: /reservations',
            'Disallow: /locale',
            '',
            'Sitemap: '.Seo::absoluteUrl('/sitemap.xml'),
        ]
        : ['User-agent: *', 'Disallow: /'];

    return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
})->name('robots');

Route::get('sitemap.xml', function () {
    $urls = collect(Seo::SITEMAP_PATHS)
        ->map(fn (string $path) => '<url><loc>'.e(Seo::absoluteUrl($path)).'</loc></url>')
        ->implode('');

    return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
})->name('sitemap');

Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->name('cashier.webhook');

// Public, no auth — guests get general info and the livestream per the
// project spec.
Route::get('livestream', [LivestreamController::class, 'index'])->name('livestream.index');

Route::middleware(['auth', 'customer-only'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

// Polled by the dashboard about every 40 seconds per open tab, hence the
// ceiling: a few tabs fit comfortably, a script hammering it doesn't.
Route::middleware(['auth', 'customer-only', 'verified', 'throttle:20,1'])->group(function () {
    Route::get('dashboard/qr-token', [DashboardController::class, 'qrToken'])->name('dashboard.qr-token');
});

Route::middleware('auth')->group(function () {
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');
    Route::get('settings/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::get('settings/account/export', [AccountController::class, 'export'])->middleware('throttle:5,1')->name('account.export');
    Route::delete('settings/account', [AccountController::class, 'destroy'])->middleware('throttle:5,1')->name('account.destroy');
});

// Open to every logged-in role (customer, employee, admin) — chat isn't
// customer-only like reservations/subscriptions — but still requires a
// verified email, same bar as subscriptions/reservations.
Route::middleware(['auth', 'verified', 'throttle:chat'])->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('chat', [ChatController::class, 'store'])->middleware('throttle:chat-send')->name('chat.store');
    Route::delete('chat/{globalMessage}', [ChatController::class, 'destroy'])->name('chat.destroy');
    Route::post('chat/{globalMessage}/pin', [ChatController::class, 'pin'])->name('chat.pin');
    Route::delete('chat/{globalMessage}/pin', [ChatController::class, 'unpin'])->name('chat.unpin');
    Route::post('chat/users/{user}/mute', [ChatController::class, 'mute'])->name('chat.mute');
    Route::post('chat/users/{user}/unmute', [ChatController::class, 'unmute'])->name('chat.unmute');
});

// Every route in here can end up calling Stripe's API synchronously
// (subscription/price lookups, checkout sessions, refunds) — throttled as a
// backstop against someone spam-clicking (or scripting) their way into
// hammering Stripe, since production has no queue workers to absorb that
// load and a burst of slow, network-bound requests can stall the single
// `php artisan serve` process for everyone, not just the one doing it.
Route::middleware(['auth', 'customer-only', 'verified', 'throttle:30,1'])->group(function () {
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions/{subscriptionType}/checkout', [SubscriptionController::class, 'checkout'])->name('subscriptions.checkout');
    Route::get('subscriptions/success', [SubscriptionController::class, 'success'])->name('subscriptions.success');
    Route::get('subscriptions/checkout-cancelled', [SubscriptionController::class, 'checkoutCancelled'])->name('subscriptions.checkout-cancelled');
    Route::delete('subscriptions/subscription', [SubscriptionController::class, 'cancelSubscription'])->name('subscriptions.cancel-subscription');
    Route::post('subscriptions/subscription/resume', [SubscriptionController::class, 'resumeSubscription'])->name('subscriptions.resume-subscription');
    Route::post('subscriptions/subscription/swap', [SubscriptionController::class, 'swapToCurrentPrice'])->name('subscriptions.swap-price');

    Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
    Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
    Route::get('reservations/success', [ReservationController::class, 'success'])->name('reservations.success');
    Route::get('reservations/checkout-cancelled', [ReservationController::class, 'checkoutCancelled'])->name('reservations.checkout-cancelled');
    Route::delete('reservations/{reservation}', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::post('reservations/{reservation}/resume', [ReservationController::class, 'resume'])->name('reservations.resume');
    Route::get('reservations/users/search', [ReservationController::class, 'searchUsers'])->middleware('throttle:user-search')->name('reservations.users.search');
    Route::post('reservations/{reservation}/participants', [ReservationController::class, 'addParticipant'])->name('reservations.participants.add');
    Route::delete('reservations/{reservation}/participants/{participant}', [ReservationController::class, 'removeParticipant'])->name('reservations.participants.remove');
    Route::post('reservations/{reservation}/leave', [ReservationController::class, 'leave'])->name('reservations.leave');
    Route::post('reservations/{reservation}/invitation/accept', [ReservationInvitationController::class, 'accept'])->name('reservations.invitation.accept');
    Route::post('reservations/{reservation}/invitation/decline', [ReservationInvitationController::class, 'decline'])->name('reservations.invitation.decline');

    Route::get('reservations/{reservation}/chat', [ReservationChatController::class, 'show'])->name('reservations.chat.show');
    Route::post('reservations/{reservation}/chat', [ReservationChatController::class, 'store'])->middleware('throttle:chat-send')->name('reservations.chat.store');
    Route::delete('reservations/{reservation}/chat/{message}', [ReservationChatController::class, 'destroy'])->name('reservations.chat.destroy');
    Route::post('reservations/{reservation}/chat/{message}/pin', [ReservationChatController::class, 'pin'])->name('reservations.chat.pin');
    Route::delete('reservations/{reservation}/chat/{message}/pin', [ReservationChatController::class, 'unpin'])->name('reservations.chat.unpin');
    Route::post('reservations/{reservation}/chat/users/{user}/mute', [ReservationChatController::class, 'mute'])->name('reservations.chat.mute');
    Route::post('reservations/{reservation}/chat/users/{user}/unmute', [ReservationChatController::class, 'unmute'])->name('reservations.chat.unmute');
});

Route::middleware(['auth', 'can-scan'])->group(function () {
    Route::get('staff/scan', [ScanController::class, 'index'])->name('staff.scan');
    Route::post('staff/scan', [ScanController::class, 'store'])->middleware('throttle:scan')->name('staff.scan.store');
});

require __DIR__.'/auth.php';

// The site's default landing page is public — the live camera, headcount,
// passes and group booking info — not a login wall. Logging in is still one
// click away via its own nav.
Route::get('/', [HomeController::class, 'index'])->name('home');
