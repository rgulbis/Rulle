<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What Stripe was last told about a plan. A Stripe Price is immutable and
     * a plan's name lives on the Product, so whether a Price has to be made
     * can't be read off "something changed": it is whether the amount or the
     * interval differs from what the current Price was made with. Keeping the
     * synced values next to the plan also makes a half-finished sync (the
     * Product made, the Price not) visible and retryable.
     */
    public function up(): void
    {
        Schema::table('subscription_types', function (Blueprint $table) {
            $table->string('stripe_synced_name')->nullable();
            $table->unsignedInteger('stripe_synced_price_cents')->nullable();
            $table->string('stripe_synced_interval')->nullable();
        });

        // Plans that already have a Price were synced with their current values.
        DB::table('subscription_types')
            ->whereNotNull('stripe_product_id')
            ->whereNotNull('stripe_price_id')
            ->update([
                'stripe_synced_name' => DB::raw('name'),
                'stripe_synced_price_cents' => DB::raw('price_cents'),
                'stripe_synced_interval' => DB::raw('billing_interval'),
            ]);
    }

    public function down(): void
    {
        Schema::table('subscription_types', function (Blueprint $table) {
            $table->dropColumn(['stripe_synced_name', 'stripe_synced_price_cents', 'stripe_synced_interval']);
        });
    }
};
