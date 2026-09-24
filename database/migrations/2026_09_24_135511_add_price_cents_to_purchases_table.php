<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->nullable()->after('subscription_type_id');
        });

        // Backfill existing rows from their plan's current price — the exact
        // price actually paid at the time wasn't recorded before this
        // column existed, so this is a best-effort estimate for past
        // purchases only; every purchase from here on stores its own.
        // Done row-by-row (not a single UPDATE...JOIN) since that syntax
        // isn't portable between the SQLite used locally/in tests and the
        // MySQL used in production.
        DB::table('purchases')->orderBy('id')->chunkById(200, function ($purchases) {
            foreach ($purchases as $purchase) {
                $priceCents = DB::table('subscription_types')
                    ->where('id', $purchase->subscription_type_id)
                    ->value('price_cents');

                if ($priceCents !== null) {
                    DB::table('purchases')->where('id', $purchase->id)->update(['price_cents' => $priceCents]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('price_cents');
        });
    }
};
