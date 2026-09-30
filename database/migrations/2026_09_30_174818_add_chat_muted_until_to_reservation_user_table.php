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
        // A mute scoped to this one reservation's group chat, set by its
        // owner — separate from users.chat_muted_until, which only affects
        // the global room. Living on the pivot means muting someone never
        // reaches into their other reservations, and it's cleared for free
        // if they're ever removed and re-added as a participant.
        Schema::table('reservation_user', function (Blueprint $table) {
            $table->timestamp('chat_muted_until')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservation_user', function (Blueprint $table) {
            $table->dropColumn('chat_muted_until');
        });
    }
};
