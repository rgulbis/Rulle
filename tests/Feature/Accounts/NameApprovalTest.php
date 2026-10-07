<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('approving a requested name applies it', function () {
    $user = User::factory()->create(['name' => 'Old', 'pending_name' => 'New']);

    expect($user->approvePendingName())->toBeTrue()
        ->and($user->fresh()->name)->toBe('New')
        ->and($user->fresh()->pending_name)->toBeNull();
});

test('a name someone else has taken since the request is not approved, and the request is dropped', function () {
    $user = User::factory()->create(['name' => 'Old', 'pending_name' => 'Popular']);
    // Registered after the request was made.
    User::factory()->create(['name' => 'Popular']);

    expect($user->approvePendingName())->toBeFalse()
        ->and($user->fresh()->name)->toBe('Old')
        ->and($user->fresh()->pending_name)->toBeNull();
});

test('a name held by a closed account cannot be approved either', function () {
    $user = User::factory()->create(['name' => 'Old', 'pending_name' => 'Ghost']);
    User::factory()->create(['name' => 'Ghost'])->delete();

    expect($user->approvePendingName())->toBeFalse()
        ->and($user->fresh()->name)->toBe('Old');
});

test('approving when nothing is pending changes nothing', function () {
    $user = User::factory()->create(['name' => 'Same']);

    expect($user->approvePendingName())->toBeFalse()
        ->and($user->fresh()->name)->toBe('Same');
});

test('a stale approval does not apply a request that was withdrawn or replaced meanwhile', function () {
    $user = User::factory()->create(['name' => 'Old', 'pending_name' => 'First']);
    $stale = User::find($user->id);

    // The person changed their request after the admin's page loaded.
    $user->update(['pending_name' => 'Second']);

    expect($stale->approvePendingName())->toBeTrue()
        ->and($user->fresh()->name)->toBe('Second');
});

test('the admin panel says why a name was not approved', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['name' => 'Old', 'pending_name' => 'Popular']);
    User::factory()->create(['name' => 'Popular']);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->callTableAction('approveName', $user)
        ->assertNotified('Name not approved');

    expect($user->fresh()->pending_name)->toBeNull();
});

test('registering is refused a name somebody has already asked for', function () {
    User::factory()->create(['pending_name' => 'Reserved']);

    $this->post('/register', [
        'name' => 'Reserved',
        'email' => 'new@example.com',
        'password' => 'Str0ng!Passw0rd#2026',
        'password_confirmation' => 'Str0ng!Passw0rd#2026',
    ])->assertSessionHasErrors('name');
});

test('the profile page reports a lost race for a name as a validation error, not a crash', function () {
    $user = User::factory()->create(['name' => 'Mine']);
    $armed = true;

    // Another request gets the name in between this one passing validation
    // and writing: only the unique index is left to say no.
    DB::beforeExecuting(function (string $query) use (&$armed) {
        if ($armed && str_starts_with($query, 'update "users" set "pending_name"')) {
            $armed = false;
            DB::table('users')->insert([
                'name' => 'Rival', 'pending_name' => 'Contested', 'email' => 'rival@example.com',
                'password' => 'x', 'role' => 'user', 'qr_code' => 'rival-qr', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $this->actingAs($user)
        ->patch('/settings/profile', ['name' => 'Contested'])
        ->assertSessionHasErrors('name');

    expect($user->fresh()->pending_name)->toBeNull();

    $armed = false;
});
