<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entry QR codes are single-use: the nonce of every token that got a
     * rider in (or out) is recorded here, and the unique index is what makes
     * two scanners reading the same token at once let exactly one through.
     * A row is only needed until its token would have expired anyway.
     */
    public function up(): void
    {
        Schema::create('used_qr_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('nonce', 32)->unique();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_qr_tokens');
    }
};
