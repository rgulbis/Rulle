<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Locally the test accounts use the well-known password "password".
        // That must never reach a public site, so production has to be given
        // one (SEED_PASSWORD).
        $password = config('app.seed_password') ?: (app()->isProduction() ? null : 'password');

        if ($password === null) {
            throw new RuntimeException('Refusing to seed staff accounts in production without SEED_PASSWORD.');
        }

        User::firstOrCreate(
            ['email' => 'admin@xn--rull-eva.lv'],
            [
                'name' => 'Admin',
                'password' => $password,
                'role' => 'admin',
                // WithoutModelEvents skips User's `creating` hook that
                // normally assigns this, so it's set here explicitly.
                'qr_code' => (string) Str::uuid(),
                'email_verified_at' => now(),
            ],
        );

        User::firstOrCreate(
            ['email' => 'employee@xn--rull-eva.lv'],
            [
                'name' => 'Employee',
                'password' => $password,
                'role' => 'employee',
                'qr_code' => (string) Str::uuid(),
                'email_verified_at' => now(),
            ],
        );

        $this->call(SubscriptionTypeSeeder::class);
    }
}
