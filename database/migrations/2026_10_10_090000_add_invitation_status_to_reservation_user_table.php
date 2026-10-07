<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A row in reservation_user is now an invitation first: only an
        // 'accepted' one counts towards the paid group size and opens the
        // group chat. The column's default is the state a new row starts in.
        Schema::table('reservation_user', function (Blueprint $table) {
            $table->string('status')->default('invited');
            $table->timestamp('responded_at')->nullable();
        });

        // Everyone who is already on a reservation was added directly, with
        // no invitation step, so they are already in.
        DB::table('reservation_user')->update([
            'status' => 'accepted',
            'responded_at' => DB::raw('created_at'),
        ]);

        // SQLite can't add a CHECK to an existing column, so the legal values
        // are enforced the same way as the other status columns (see
        // harden_data_integrity): a pair of triggers.
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE OF status'] as $name => $event) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER reservation_user_status_enum_{$name}
                BEFORE {$event} ON reservation_user
                WHEN NEW.status NOT IN ('invited', 'accepted', 'declined')
                BEGIN
                    SELECT RAISE(ABORT, 'invalid_reservation_user_status');
                END
            SQL);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS reservation_user_status_enum_insert');
        DB::statement('DROP TRIGGER IF EXISTS reservation_user_status_enum_update');

        Schema::table('reservation_user', function (Blueprint $table) {
            $table->dropColumn(['status', 'responded_at']);
        });
    }
};
