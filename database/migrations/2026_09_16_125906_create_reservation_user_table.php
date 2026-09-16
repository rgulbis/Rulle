<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Participants added to a reservation, in addition to its owner
        // (users.id via reservations.user_id).
        Schema::create('reservation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['reservation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_user');
    }
};
