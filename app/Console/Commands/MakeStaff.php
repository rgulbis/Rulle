<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeStaff extends Command
{
    protected $signature = 'user:set-role {email} {role : admin, employee, or user}';

    protected $description = 'Set a user\'s role (admin, employee, or user)';

    public function handle(): int
    {
        $role = $this->argument('role');

        if (! in_array($role, ['admin', 'employee', 'user'], true)) {
            $this->error('Role must be one of: admin, employee, user.');

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user found with that email.');

            return self::FAILURE;
        }

        $user->role = $role;
        $user->save();

        $this->info("{$user->name} is now {$role}.");

        return self::SUCCESS;
    }
}
