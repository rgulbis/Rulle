<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LivestreamController;
use App\Http\Controllers\Reservations\ReservationChatController;
use App\Http\Controllers\Reservations\ReservationController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Staff\ScanController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\Subscriptions\SubscriptionController;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Route;

// `{globalMessage}` only ever resolves a message from the global room. A
// reservation's private group chat message id 404s on every global-chat route
// by construction, so no controller method has to remember to check.
Route::bind('globalMessage', fn (string $value) => ChatMessage::whereNull('reservation_id')->findOrFail($value));

Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])->name('cashier.webhook');

// Public, no auth — guests get general info and the livestream per the
// project spec.
Route::get('livestream', [LivestreamController::class, 'index'])->name('livestream.index');

Route::middleware(['auth', 'customer-only'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('dashboard/entry-token', [DashboardController::class, 'entryToken'])->middleware('throttle:30,1,entry-token')->name('dashboard.entry-token');
});

Route::middleware('auth')->group(function () {
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');
});

// Open to every logged-in role (customer, employee, admin) — chat isn't
// customer-only like reservations/subscriptions — but still requires a
// verified email, same bar as subscriptions/reservations.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('chat', [ChatController::class, 'store'])->middleware('throttle:20,1,global-chat')->name('chat.store');
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
    Route::get('subscriptions/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
    Route::delete('subscriptions/subscription', [SubscriptionController::class, 'cancelSubscription'])->name('subscriptions.cancel-subscription');
    Route::post('subscriptions/subscription/swap', [SubscriptionController::class, 'swapToCurrentPrice'])->name('subscriptions.swap-price');

    Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
    Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
    Route::get('reservations/success', [ReservationController::class, 'success'])->name('reservations.success');
    Route::delete('reservations/{reservation}', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::post('reservations/{reservation}/resume', [ReservationController::class, 'resume'])->name('reservations.resume');
    Route::get('reservations/users/search', [ReservationController::class, 'searchUsers'])->middleware('throttle:20,1,user-search')->name('reservations.users.search');
    Route::post('reservations/{reservation}/participants', [ReservationController::class, 'addParticipant'])->name('reservations.participants.add');
    Route::delete('reservations/{reservation}/participants/{participant}', [ReservationController::class, 'removeParticipant'])->name('reservations.participants.remove');
    Route::post('reservations/{reservation}/invitation', [ReservationController::class, 'acceptInvitation'])->name('reservations.invitation.accept');
    Route::delete('reservations/{reservation}/invitation', [ReservationController::class, 'declineInvitation'])->name('reservations.invitation.decline');
    Route::post('reservations/{reservation}/leave', [ReservationController::class, 'leave'])->name('reservations.leave');

    Route::get('reservations/{reservation}/chat', [ReservationChatController::class, 'show'])->name('reservations.chat.show');
    Route::post('reservations/{reservation}/chat', [ReservationChatController::class, 'store'])->middleware('throttle:20,1,reservation-chat')->name('reservations.chat.store');
    Route::delete('reservations/{reservation}/chat/{message}', [ReservationChatController::class, 'destroy'])->name('reservations.chat.destroy');
    Route::post('reservations/{reservation}/chat/{message}/pin', [ReservationChatController::class, 'pin'])->name('reservations.chat.pin');
    Route::delete('reservations/{reservation}/chat/{message}/pin', [ReservationChatController::class, 'unpin'])->name('reservations.chat.unpin');
    Route::post('reservations/{reservation}/chat/users/{user}/mute', [ReservationChatController::class, 'mute'])->name('reservations.chat.mute');
    Route::post('reservations/{reservation}/chat/users/{user}/unmute', [ReservationChatController::class, 'unmute'])->name('reservations.chat.unmute');
});

Route::middleware(['auth', 'can-scan'])->group(function () {
    Route::get('staff/scan', [ScanController::class, 'index'])->name('staff.scan');
    Route::post('staff/scan', [ScanController::class, 'store'])->name('staff.scan.store');
});

require __DIR__.'/auth.php';

// The site's default landing page is public — the live camera, headcount,
// passes and group booking info — not a login wall. Logging in is still one
// click away via its own nav.
Route::get('/', [HomeController::class, 'index'])->name('home');
