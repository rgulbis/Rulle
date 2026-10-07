<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The last line of defence against two paid reservations for the same
     * time. The application checks for an overlap inside a transaction, but a
     * missed check (a new code path, a console command, an admin edit) must
     * still not be able to double-book the park, so the database refuses it.
     *
     * Only `active` (paid) reservations are guarded: `pending` ones may
     * legitimately overlap (two people mid-checkout for the same slot — the
     * first payment wins and the other is refunded), and cancelled ones are
     * history. The datetimes are stored in one fixed `Y-m-d H:i:s` format, so
     * plain string comparison orders them correctly.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $this->down();

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
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS reservations_no_active_overlap_on_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS reservations_no_active_overlap_on_update');
    }
};
