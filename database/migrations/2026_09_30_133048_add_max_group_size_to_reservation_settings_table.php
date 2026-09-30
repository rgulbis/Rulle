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
        // Previously unbounded — group_size only had a `min` validation
        // rule, so a reservation could be submitted for any size at all
        // (e.g. a group of three million), which is nonsensical for a
        // single physical park and inflates its price calculation just as
        // absurdly.
        Schema::table('reservation_settings', function (Blueprint $table) {
            $table->unsignedInteger('max_group_size')->default(50)->after('min_group_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservation_settings', function (Blueprint $table) {
            $table->dropColumn('max_group_size');
        });
    }
};
