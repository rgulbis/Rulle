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
        // Nullable, not a required parallel field: a plan with no Latvian
        // name/description yet just falls back to the English one on the
        // customer-facing site (see subscriptions/index.tsx) rather than
        // showing blank.
        Schema::table('subscription_types', function (Blueprint $table) {
            $table->string('name_lv')->nullable()->after('name');
            $table->text('description_lv')->nullable()->after('description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_types', function (Blueprint $table) {
            $table->dropColumn(['name_lv', 'description_lv']);
        });
    }
};
