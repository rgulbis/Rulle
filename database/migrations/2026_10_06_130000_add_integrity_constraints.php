<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Statuses and roles were free-form strings: anything that wrote to the
     * database outside the intended forms could leave a row in a state the
     * app has no code path for (a reservation "activ", a user with role
     * "superadmin"). SQLite can't add CHECK constraints to an existing table
     * without rebuilding it, so triggers enforce the allowed values instead.
     *
     * Display names also become unique at the database level — the app only
     * checked at request time, so two admins (or two requests) could create
     * duplicates. Existing duplicates get a numeric suffix first.
     */
    private const ENUMS = [
        'users' => ['role' => ['admin', 'employee', 'user']],
        'reservations' => [
            'status' => ['pending', 'active', 'cancelled'],
            'payment_status' => ['unpaid', 'paid', 'refund_pending', 'refunded', 'refund_failed'],
        ],
        'purchases' => [
            'status' => ['pending', 'active', 'used_up', 'refunded', 'abandoned'],
            'payment_status' => ['unpaid', 'paid', 'refund_pending', 'refunded', 'refund_failed'],
        ],
        'reservation_user' => ['status' => ['invited', 'accepted']],
    ];

    public function up(): void
    {
        $this->renameDuplicateNames();

        Schema::table('users', function ($table) {
            $table->unique('name');
        });

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::ENUMS as $table => $columns) {
            foreach ($columns as $column => $allowed) {
                $list = implode(', ', array_map(fn (string $value) => "'{$value}'", $allowed));

                foreach (['INSERT', 'UPDATE OF '.$column] as $event) {
                    $name = "{$table}_{$column}_check_".strtolower(explode(' ', $event)[0]);

                    DB::statement(<<<SQL
                        CREATE TRIGGER IF NOT EXISTS {$name}
                        BEFORE {$event} ON {$table}
                        WHEN NEW.{$column} IS NULL OR NEW.{$column} NOT IN ({$list})
                        BEGIN
                            SELECT RAISE(ABORT, 'invalid {$table}.{$column}');
                        END
                    SQL);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::ENUMS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_{$column}_check_insert");
                DB::statement("DROP TRIGGER IF EXISTS {$table}_{$column}_check_update");
            }
        }

        Schema::table('users', function ($table) {
            $table->dropUnique(['name']);
        });
    }

    private function renameDuplicateNames(): void
    {
        $taken = array_flip(DB::table('users')->pluck('name')->all());

        $duplicates = DB::table('users')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('count(*) > 1')
            ->pluck('name');

        foreach ($duplicates as $name) {
            // Everyone but the oldest keeps their name with a suffix.
            $ids = array_slice(DB::table('users')->where('name', $name)->orderBy('id')->pluck('id')->all(), 1);
            $suffix = 2;

            foreach ($ids as $id) {
                do {
                    $candidate = "{$name} ({$suffix})";
                    $suffix++;
                } while (isset($taken[$candidate]));

                DB::table('users')->where('id', $id)->update(['name' => $candidate]);
                $taken[$candidate] = true;
            }
        }
    }
};
