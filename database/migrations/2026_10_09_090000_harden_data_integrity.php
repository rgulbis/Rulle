<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQLite cannot change a foreign key in place: Laravel rebuilds the table
     * (create copy, copy rows, drop, rename), and dropping the old table with
     * foreign keys switched on would run the old `ON DELETE CASCADE` rules
     * against its children. The pragma can't be changed inside a
     * transaction, so this migration manages its own.
     */
    public $withinTransaction = false;

    /**
     * The legal values of every status-like column. A CHECK can't be added to
     * an existing SQLite table, so each one is enforced by a pair of triggers
     * (insert, and update of that column). Stripe's own `stripe_status` is
     * deliberately not listed: its vocabulary belongs to Stripe, and a status
     * it adds one day must not turn every webhook into a 500.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const ENUMS = [
        'users' => ['role' => ['user', 'employee', 'admin']],
        'purchases' => [
            'status' => ['pending', 'active', 'used_up', 'cancelled', 'abandoned'],
            'payment_status' => ['unpaid', 'paid', 'refunded', 'partially_refunded', 'refund_failed'],
        ],
        'reservations' => [
            'status' => ['pending', 'active', 'cancelled'],
            'payment_status' => ['unpaid', 'paid', 'refunded', 'partially_refunded', 'refund_failed'],
        ],
        'subscription_types' => ['billing_interval' => ['one_time', 'month', 'year']],
    ];

    /**
     * table => [column => [parent table, ON DELETE action after this
     * migration, ON DELETE action before it (null: there was no foreign key)]].
     *
     * Nothing here cascades any more: a customer, a plan or a reservation
     * with history can no longer be removed by deleting its parent row. The
     * application closes accounts by soft delete, and refuses to delete a
     * plan that has sales.
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: string|null}>>
     */
    private const FOREIGN_KEYS = [
        'purchases' => [
            'user_id' => ['users', 'restrict', 'cascade'],
            'subscription_type_id' => ['subscription_types', 'restrict', 'no action'],
        ],
        'check_in_events' => ['user_id' => ['users', 'restrict', 'cascade']],
        'reservations' => ['user_id' => ['users', 'restrict', 'cascade']],
        'reservation_user' => [
            'reservation_id' => ['reservations', 'restrict', 'cascade'],
            'user_id' => ['users', 'restrict', 'cascade'],
        ],
        'chat_messages' => [
            'user_id' => ['users', 'restrict', 'cascade'],
            'reservation_id' => ['reservations', 'restrict', 'cascade'],
        ],
        // Cashier's own tables shipped without any foreign keys.
        'subscriptions' => ['user_id' => ['users', 'restrict', null]],
        'subscription_items' => ['subscription_id' => ['subscriptions', 'restrict', null]],
    ];

    public function up(): void
    {
        $this->requireSqlite();

        $this->repairData();

        $this->rebuild(fn (string $table, string $column, array $spec) => [$spec[1], $table, $column, $spec[0]]);

        $this->createEnumTriggers();
    }

    public function down(): void
    {
        $this->requireSqlite();

        $this->dropEnumTriggers();

        $this->rebuild(fn (string $table, string $column, array $spec) => $spec[2] === null
            ? [null, $table, $column, $spec[0]]
            : [$spec[2], $table, $column, $spec[0]]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_name_unique');
            $table->dropUnique('users_pending_name_unique');
            $table->dropSoftDeletes();
        });
    }

    private function requireSqlite(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('This application is SQLite-only (triggers, partial indexes, strftime, WAL): see "Database" in the README.');
        }
    }

    /**
     * Rows that the new rules would reject have to be dealt with first, and
     * the cases a person can fix by hand are reported rather than guessed at.
     */
    private function repairData(): void
    {
        // Two accounts with one name: the later ones get their id appended,
        // which keeps them apart and recognisable.
        $duplicates = DB::select('SELECT name FROM users GROUP BY name HAVING COUNT(*) > 1');

        foreach ($duplicates as $duplicate) {
            $ids = DB::table('users')->where('name', $duplicate->name)->orderBy('id')->pluck('id')->all();

            foreach (array_slice($ids, 1) as $id) {
                DB::table('users')->where('id', $id)->update(['name' => $duplicate->name.' #'.$id]);
            }
        }

        // A requested name that is already someone's name, or is requested by
        // two people, can't be approved; the request is dropped.
        DB::table('users')->whereNotNull('pending_name')
            ->whereIn('pending_name', DB::table('users')->select('name'))
            ->update(['pending_name' => null]);

        foreach (DB::select('SELECT pending_name FROM users WHERE pending_name IS NOT NULL GROUP BY pending_name HAVING COUNT(*) > 1') as $duplicate) {
            $ids = DB::table('users')->where('pending_name', $duplicate->pending_name)->orderBy('id')->pluck('id')->all();

            DB::table('users')->whereIn('id', array_slice($ids, 1))->update(['pending_name' => null]);
        }

        $problems = [];

        foreach (self::ENUMS as $table => $columns) {
            foreach ($columns as $column => $allowed) {
                $bad = DB::table($table)->whereNotIn($column, $allowed)->distinct()->pluck($column)->all();

                if ($bad !== []) {
                    $problems[] = "{$table}.{$column} holds ".implode(', ', array_map(fn ($value) => var_export($value, true), $bad));
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException('Cannot add the status checks, these values are not allowed: '.implode('; ', $problems).'. Fix them first.');
        }
    }

    /**
     * @param  callable(string, string, array{0: string, 1: string, 2: string|null}): array{0: string|null, 1: string, 2: string, 3: string}  $target  [on delete action or null for no key, table, column, parent table]
     */
    private function rebuild(callable $target): void
    {
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::beginTransaction();

            $this->dropDependentObjects();

            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'deleted_at')) {
                    $table->softDeletes();
                }

                if (! $this->hasIndex('users', 'users_name_unique')) {
                    $table->unique('name');
                }

                if (! $this->hasIndex('users', 'users_pending_name_unique')) {
                    $table->unique('pending_name');
                }
            });

            foreach (self::FOREIGN_KEYS as $tableName => $columns) {
                $changes = [];

                foreach ($columns as $column => $spec) {
                    $changes[$column] = $target($tableName, $column, $spec);
                }

                Schema::table($tableName, function (Blueprint $table) use ($tableName, $changes) {
                    foreach ($changes as $column => [$onDelete, , , $parent]) {
                        if ($this->hasForeignKey($tableName, $column)) {
                            $table->dropForeign([$column]);
                        }

                        if ($onDelete !== null) {
                            $table->foreign($column)->references('id')->on($parent)->onDelete($onDelete);
                        }
                    }
                });
            }

            $this->createDependentObjects();

            $violations = DB::select('PRAGMA foreign_key_check');

            if ($violations !== []) {
                $summary = collect($violations)
                    ->groupBy(fn ($row) => $row->table.' -> '.$row->parent)
                    ->map(fn ($rows, $relation) => "{$relation}: {$rows->count()} row(s), e.g. rowid {$rows->first()->rowid}")
                    ->implode('; ');

                throw new RuntimeException("Rows point at records that no longer exist ({$summary}). Remove or repair them, then migrate again.");
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Everything SQLite would silently lose, or choke on, while a table is
     * rebuilt: the ledger view reads the tables, and the triggers and the
     * partial index are not carried over by Laravel's table copy. They are
     * all put back, with the same definitions, by createDependentObjects().
     */
    private function dropDependentObjects(): void
    {
        DB::statement('DROP VIEW IF EXISTS payments');
        DB::statement('DROP TRIGGER IF EXISTS reservations_no_active_overlap_on_insert');
        DB::statement('DROP TRIGGER IF EXISTS reservations_no_active_overlap_on_update');
        DB::statement('DROP INDEX IF EXISTS subscriptions_one_live_per_user_type');
        $this->dropEnumTriggers();
    }

    private function createDependentObjects(): void
    {
        // Same definitions as 2026_10_08_090000 and 2026_10_08_090100.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reservations_no_active_overlap_on_insert
            BEFORE INSERT ON reservations
            WHEN NEW.status = 'active'
            BEGIN
                SELECT RAISE(ABORT, 'reservation_overlap')
                WHERE EXISTS (
                    SELECT 1 FROM reservations
                    WHERE status = 'active'
                      AND starts_at < NEW.ends_at
                      AND ends_at > NEW.starts_at
                );
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER reservations_no_active_overlap_on_update
            BEFORE UPDATE OF status, starts_at, ends_at ON reservations
            WHEN NEW.status = 'active'
            BEGIN
                SELECT RAISE(ABORT, 'reservation_overlap')
                WHERE EXISTS (
                    SELECT 1 FROM reservations
                    WHERE status = 'active'
                      AND id != NEW.id
                      AND starts_at < NEW.ends_at
                      AND ends_at > NEW.starts_at
                );
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_live_per_user_type
            ON subscriptions (user_id, type)
            WHERE ends_at IS NULL AND stripe_status IN ('active', 'trialing', 'past_due')
        SQL);

        // Same definition as 2026_10_07_090100.
        DB::statement(<<<'SQL'
            CREATE VIEW payments AS
            SELECT
                'purchase-' || purchases.id AS id,
                'purchase' AS type,
                purchases.user_id AS user_id,
                subscription_types.name AS description,
                purchases.price_cents AS amount_cents,
                purchases.status AS status,
                purchases.payment_status AS payment_status,
                purchases.refunded_cents AS refunded_cents,
                purchases.created_at AS created_at
            FROM purchases
            LEFT JOIN subscription_types ON subscription_types.id = purchases.subscription_type_id

            UNION ALL

            SELECT
                'reservation-' || reservations.id AS id,
                'reservation' AS type,
                reservations.user_id AS user_id,
                'Park reservation' AS description,
                reservations.price_cents AS amount_cents,
                reservations.status AS status,
                reservations.payment_status AS payment_status,
                reservations.refunded_cents AS refunded_cents,
                reservations.created_at AS created_at
            FROM reservations

            UNION ALL

            SELECT
                'subscription-' || subscriptions.id AS id,
                'subscription' AS type,
                subscriptions.user_id AS user_id,
                COALESCE(subscription_types.name, 'Subscription') AS description,
                COALESCE(subscriptions.price_cents, 0) AS amount_cents,
                subscriptions.stripe_status AS status,
                CASE
                    WHEN subscriptions.stripe_status IN ('incomplete', 'incomplete_expired', 'unpaid', 'past_due') THEN 'unpaid'
                    ELSE 'paid'
                END AS payment_status,
                0 AS refunded_cents,
                subscriptions.created_at AS created_at
            FROM subscriptions
            LEFT JOIN subscription_items ON subscription_items.subscription_id = subscriptions.id
            LEFT JOIN subscription_types ON subscription_types.stripe_product_id = subscription_items.stripe_product
        SQL);
    }

    /**
     * Only called outside a rebuild on the way up, and inside one on the way
     * down (where the triggers are simply not wanted any more).
     */
    private function createEnumTriggers(): void
    {
        foreach (self::ENUMS as $table => $columns) {
            foreach ($columns as $column => $allowed) {
                $list = implode(', ', array_map(fn (string $value) => "'{$value}'", $allowed));
                $name = "{$table}_{$column}_enum";

                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$name}_insert
                    BEFORE INSERT ON {$table}
                    WHEN NEW.{$column} NOT IN ({$list})
                    BEGIN
                        SELECT RAISE(ABORT, 'invalid_{$table}_{$column}');
                    END
                SQL);

                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$name}_update
                    BEFORE UPDATE OF {$column} ON {$table}
                    WHEN NEW.{$column} NOT IN ({$list})
                    BEGIN
                        SELECT RAISE(ABORT, 'invalid_{$table}_{$column}');
                    END
                SQL);
            }
        }
    }

    private function dropEnumTriggers(): void
    {
        foreach (self::ENUMS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_{$column}_enum_insert");
                DB::statement("DROP TRIGGER IF EXISTS {$table}_{$column}_enum_update");
            }
        }
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }

    private function hasIndex(string $table, string $name): bool
    {
        return Schema::hasIndex($table, $name);
    }
};
