<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row settings table (id=1), edited via a Filament settings
        // page rather than a per-plan resource, since there's only ever
        // one reservation pricing rate.
        Schema::create('reservation_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('price_cents_per_person_per_hour');
            $table->unsignedInteger('min_group_size');
            $table->unsignedInteger('min_duration_minutes');
            $table->unsignedInteger('max_duration_minutes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_settings');
    }
};
