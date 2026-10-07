<?php

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Livewire\Livewire;

function adminForForm(): User
{
    $admin = User::factory()->create(['role' => 'admin']);
    test()->actingAs($admin);

    return $admin;
}

test('the admin form rejects a name or email that is already taken', function () {
    adminForForm();
    $other = User::factory()->create(['name' => 'Taken Name', 'email' => 'taken@example.com']);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Taken Name', 'email' => 'taken@example.com', 'role' => 'user', 'password' => 'C0rrect!Horse42'])
        ->call('create')
        ->assertHasFormErrors(['name', 'email']);

    expect(User::count())->toBe(2);
});

test('the admin form rejects a name somebody else has asked for', function () {
    adminForForm();
    User::factory()->create(['pending_name' => 'Wanted Name']);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Wanted Name', 'email' => 'new@example.com', 'role' => 'user', 'password' => 'C0rrect!Horse42'])
        ->call('create')
        ->assertHasFormErrors(['name']);
});

test('the admin form applies the public profile content rules to the name', function () {
    adminForForm();

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'fuck this', 'email' => 'new@example.com', 'role' => 'user', 'password' => 'C0rrect!Horse42'])
        ->call('create')
        ->assertHasFormErrors(['name']);
});

test('the admin form stores a Unicode email domain in its ASCII form and checks uniqueness against it', function () {
    adminForForm();
    User::factory()->create(['email' => 'admin@xn--rull-eva.lv']);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Dup', 'email' => 'admin@rullē.lv', 'role' => 'user', 'password' => 'C0rrect!Horse42'])
        ->call('create')
        ->assertHasFormErrors(['email']);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Fresh', 'email' => 'fresh@rullē.lv', 'role' => 'user', 'password' => 'C0rrect!Horse42'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'fresh@xn--rull-eva.lv')->exists())->toBeTrue();
});

test('the admin form enforces the password policy', function () {
    adminForForm();

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Weak', 'email' => 'weak@example.com', 'role' => 'user', 'password' => 'password'])
        ->call('create')
        ->assertHasFormErrors(['password']);

    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['password' => 'short'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    // Blank still means "keep the current password".
    Livewire::test(EditUser::class, ['record' => $user->id])
        ->fillForm(['password' => ''])
        ->call('save')
        ->assertHasNoFormErrors();
});

test('an admin cannot demote themselves', function () {
    $admin = adminForForm();
    User::factory()->create(['role' => 'admin']);

    Livewire::test(EditUser::class, ['record' => $admin->id])
        ->fillForm(['role' => 'employee'])
        ->call('save')
        ->assertHasFormErrors(['role']);

    expect($admin->fresh()->role)->toBe('admin');
});

test('the last admin cannot be demoted, but another admin can be while one remains', function () {
    $admin = adminForForm();
    $second = User::factory()->create(['role' => 'admin']);

    Livewire::test(EditUser::class, ['record' => $second->id])
        ->fillForm(['role' => 'employee'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($second->fresh()->role)->toBe('employee');

    // $second is no longer an admin, so $admin is the last one: the model
    // refuses regardless of who asks.
    expect($admin->roleChangeBlocker('user', $second))->toContain('last admin');
});

test('the role is checked again when saving, so a stale form cannot demote the last admin', function () {
    $admin = adminForForm();
    $second = User::factory()->create(['role' => 'admin']);

    $page = Livewire::test(EditUser::class, ['record' => $second->id])
        ->fillForm(['role' => 'employee']);

    // The other admin is demoted by someone else after the form was open.
    User::where('id', $admin->id)->update(['role' => 'employee']);
    $this->actingAs($second);

    $page->call('save');

    expect($second->fresh()->role)->toBe('admin');
});

test('registration stores a Unicode email domain as its ASCII form and rejects its duplicate', function () {
    User::factory()->create(['email' => 'rider@xn--rull-eva.lv']);

    $payload = fn (string $name, string $email) => [
        'name' => $name,
        'email' => $email,
        'password' => 'C0rrect!Horse42',
        'password_confirmation' => 'C0rrect!Horse42',
    ];

    $this->post('/register', $payload('Dup', 'rider@rullē.lv'))->assertSessionHasErrors('email');

    $this->post('/register', $payload('Fresh', 'fresh@rullē.lv'));

    expect(User::where('email', 'fresh@xn--rull-eva.lv')->exists())->toBeTrue();
});
