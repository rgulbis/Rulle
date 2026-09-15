<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@xn--rull-eva.lv'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'role' => 'admin',
                'email_verified_at' => now(),
            ],
        );

        User::firstOrCreate(
            ['email' => 'employee@xn--rull-eva.lv'],
            [
                'name' => 'Employee',
                'password' => 'password',
                'role' => 'employee',
                'email_verified_at' => now(),
            ],
        );
    }
}
