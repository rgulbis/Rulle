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
        // A requested name change sits here until an admin approves or
        // rejects it — `name` (what actually shows in chat, reservations,
        // everywhere) only ever changes on approval. Only one pending
        // request at a time: submitting again just overwrites it.
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_name')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pending_name');
        });
    }
};
