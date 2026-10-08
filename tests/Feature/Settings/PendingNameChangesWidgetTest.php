<?php

use App\Filament\Widgets\PendingNameChanges;
use App\Models\User;
use Livewire\Livewire;

test('the widget stays on the dashboard even when nothing is pending', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    User::factory()->create();

    $this->actingAs($admin);

    Livewire::test(PendingNameChanges::class)->assertSee('Nothing pending');
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
    Livewire::test(PendingNameChanges::class)->callTableAction('approveName', $user)->assertNotified('Name approved');

    expect($user->fresh()->name)->toBe('New Name');
    expect($user->fresh()->pending_name)->toBeNull();
});

test('rejecting from the widget discards the request', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['name' => 'Old Name', 'pending_name' => 'New Name']);

    $this->actingAs($admin);
    Livewire::test(PendingNameChanges::class)->callTableAction('rejectName', $user)->assertNotified('Name request rejected');

    expect($user->fresh()->name)->toBe('Old Name');
    expect($user->fresh()->pending_name)->toBeNull();
});
