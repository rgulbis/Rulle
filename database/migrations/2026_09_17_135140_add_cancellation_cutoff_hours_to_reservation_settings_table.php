<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_settings', function (Blueprint $table) {
            // How close to the start time a paid reservation can still be
            // cancelled and refunded; cancelling inside this window still
            // frees the slot but forfeits the payment.
            $table->unsignedInteger('cancellation_cutoff_hours')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('reservation_settings', function (Blueprint $table) {
            $table->dropColumn('cancellation_cutoff_hours');
        });
    }
};
