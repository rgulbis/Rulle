<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeStaff extends Command
{
    protected $signature = 'user:make-staff {email}';

    protected $description = 'Promote a user to the staff role so they can use the QR scanner';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user found with that email.');

            return self::FAILURE;
        }

        $user->role = 'staff';
        $user->save();

        $this->info("{$user->name} is now staff.");

        return self::SUCCESS;
    }
}
