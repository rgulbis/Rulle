<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Staff\ScanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::get('dashboard', function (Request $request) {
        return Inertia::render('dashboard', [
            'status' => $request->session()->get('status'),
        ]);
    })->name('dashboard');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware(['auth', 'staff'])->group(function () {
    Route::get('staff/scan', [ScanController::class, 'index'])->name('staff.scan');
    Route::post('staff/scan', [ScanController::class, 'store'])->name('staff.scan.store');
});

require __DIR__.'/auth.php';

Route::middleware('guest')->get('/', [AuthenticatedSessionController::class, 'create'])->name('home');
