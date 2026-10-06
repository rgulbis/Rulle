<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People used to be put on someone else's reservation — and into its
     * group chat — without being asked. Adding someone now creates an
     * invitation ("invited"); only once they accept ("accepted") do they
     * count as part of the group. Everyone already on a reservation is
     * grandfathered in as accepted. An invitation still takes up a seat, so
     * the paid group size can't be overbooked by pending invitations.
     */
    public function up(): void
    {
        Schema::table('reservation_user', function (Blueprint $table) {
            $table->string('status')->default('accepted');
            $table->timestamp('responded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_user', function (Blueprint $table) {
            $table->dropColumn(['status', 'responded_at']);
        });
    }
};
