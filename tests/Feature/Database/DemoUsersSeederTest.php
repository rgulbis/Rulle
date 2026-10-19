<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

test('the seeder creates four verified demo customers without any pass', function () {
    config(['cashier.secret' => null]);

    (new DatabaseSeeder)->run();

    $users = User::where('email', 'like', 'user%@xn--rull-eva.lv')->orderBy('email')->get();

    expect($users->pluck('name')->all())->toBe(['user1', 'user2', 'user3', 'user4'])
        ->and($users->every(fn (User $user) => $user->isCustomer() && $user->hasVerifiedEmail() && filled($user->qr_code)))->toBeTrue()
        ->and($users->every(fn (User $user) => Hash::check('password123@', $user->password)))->toBeTrue()
        ->and($users->contains(fn (User $user) => $user->hasActiveAccess()))->toBeFalse();
});

test('seeding again keeps changes made to the demo customers', function () {
    config(['cashier.secret' => null]);

    (new DatabaseSeeder)->run();
    User::where('email', 'user1@xn--rull-eva.lv')->update(['name' => 'Renamed']);

    (new DatabaseSeeder)->run();

    expect(User::where('email', 'like', 'user%@xn--rull-eva.lv')->count())->toBe(4)
        ->and(User::where('email', 'user1@xn--rull-eva.lv')->value('name'))->toBe('Renamed');
});
