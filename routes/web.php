<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\Reservations\ReservationChatController;
use App\Http\Controllers\Reservations\ReservationController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Staff\ScanController;
use App\Http\Controllers\Subscriptions\SubscriptionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Cashier\Http\Controllers\WebhookController;

Route::post('stripe/webhook', [WebhookController::class, 'handleWebhook'])->name('cashier.webhook');

Route::middleware(['auth', 'customer-only'])->group(function () {
    Route::get('dashboard', function (Request $request) {
        return Inertia::render('dashboard', [
            'status' => $request->session()->get('status'),
        ]);
    })->name('dashboard');
});

Route::middleware('auth')->group(function () {
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');
});

// Open to every logged-in role (customer, employee, admin) — chat isn't
// customer-only like reservations/subscriptions — but still requires a
// verified email, same bar as subscriptions/reservations.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('chat', [ChatController::class, 'index'])->name('chat.index');
    Route::post('chat', [ChatController::class, 'store'])->name('chat.store');
    Route::delete('chat/{message}', [ChatController::class, 'destroy'])->name('chat.destroy');
    Route::post('chat/users/{user}/mute', [ChatController::class, 'mute'])->name('chat.mute');
    Route::post('chat/users/{user}/unmute', [ChatController::class, 'unmute'])->name('chat.unmute');
});

Route::middleware(['auth', 'customer-only', 'verified'])->group(function () {
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions/{subscriptionType}/checkout', [SubscriptionController::class, 'checkout'])->name('subscriptions.checkout');
    Route::get('subscriptions/success', [SubscriptionController::class, 'success'])->name('subscriptions.success');
    Route::get('subscriptions/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
    Route::delete('subscriptions/subscription', [SubscriptionController::class, 'cancelSubscription'])->name('subscriptions.cancel-subscription');
    Route::post('subscriptions/subscription/swap', [SubscriptionController::class, 'swapToCurrentPrice'])->name('subscriptions.swap-price');

    Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
    Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
    Route::get('reservations/success', [ReservationController::class, 'success'])->name('reservations.success');
    Route::get('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::post('reservations/{reservation}/resume', [ReservationController::class, 'resume'])->name('reservations.resume');
    Route::get('reservations/users/search', [ReservationController::class, 'searchUsers'])->name('reservations.users.search');
    Route::post('reservations/{reservation}/participants', [ReservationController::class, 'addParticipant'])->name('reservations.participants.add');
    Route::delete('reservations/{reservation}/participants/{participant}', [ReservationController::class, 'removeParticipant'])->name('reservations.participants.remove');

    Route::get('reservations/{reservation}/chat', [ReservationChatController::class, 'show'])->name('reservations.chat.show');
    Route::post('reservations/{reservation}/chat', [ReservationChatController::class, 'store'])->name('reservations.chat.store');
});

Route::middleware(['auth', 'can-scan'])->group(function () {
    Route::get('staff/scan', [ScanController::class, 'index'])->name('staff.scan');
    Route::post('staff/scan', [ScanController::class, 'store'])->name('staff.scan.store');
});

require __DIR__.'/auth.php';

Route::middleware('guest')->get('/', [AuthenticatedSessionController::class, 'create'])->name('home');
