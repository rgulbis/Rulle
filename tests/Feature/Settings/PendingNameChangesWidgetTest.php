<?php

use App\Filament\Widgets\PendingNameChanges;
use App\Models\User;
use Livewire\Livewire;

test('the widget is hidden when nothing is pending review', function () {
    User::factory()->create();

    expect(PendingNameChanges::canView())->toBeFalse();
});

test('the widget appears as soon as something needs review', function () {
    User::factory()->create(['name' => 'Old Name', 'pending_name' => 'New Name']);

    expect(PendingNameChanges::canView())->toBeTrue();
});

test('the widget lists only users with a pending name change', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $pending = User::factory()->create(['name' => 'Old Name', 'pending_name' => 'New Name']);
    $notPending = User::factory()->create();

    $this->actingAs($admin);

    Livewire::test(PendingNameChanges::class)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$notPending]);
});

test('approving from the widget applies the name change', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['name' => 'Old Name', 'pending_name' => 'New Name']);

    $this->actingAs($admin);
    Livewire::test(PendingNameChanges::class)->callTableAction('approveName', $user);

    expect($user->fresh()->name)->toBe('New Name');
    expect($user->fresh()->pending_name)->toBeNull();
});

test('rejecting from the widget discards the request', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['name' => 'Old Name', 'pending_name' => 'New Name']);

    $this->actingAs($admin);
    Livewire::test(PendingNameChanges::class)->callTableAction('rejectName', $user);

    expect($user->fresh()->name)->toBe('Old Name');
    expect($user->fresh()->pending_name)->toBeNull();
});
