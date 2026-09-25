<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Redundant with check_in_events: "currently checked in" is now
        // derived from each user's latest event there instead of cached
        // here — see User::isCurrentlyCheckedIn() and
        // App\Support\CheckInOccupancy::currentlyCheckedInCount().
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('checked_in');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restores the column's structure, not its historical values — the
        // per-user state isn't recorded anywhere but check_in_events once
        // this column is gone.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('checked_in')->default(false)->after('qr_code');
        });
    }
};
