<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money gets its own state, separate from the lifecycle `status` column:
     * a reservation that was cancelled too late for a refund is `cancelled`
     * but still `paid`, and revenue has to be able to tell that apart from
     * one that was cancelled and refunded.
     *
     *   payment_status          unpaid | paid | refunded | partially_refunded | refund_failed
     *   refund_requested_cents  the refund we have promised (recorded *before*
     *                           Stripe is called, so a crash or a Stripe outage
     *                           can be retried instead of forgotten)
     *   refunded_cents          what Stripe confirmed as refunded
     *
     * `subscriptions.price_cents` records what the customer is billed, so the
     * Payments ledger no longer has to read today's plan price.
     */
    public function up(): void
    {
        foreach (['purchases', 'reservations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('payment_status')->default('unpaid');
                $table->unsignedInteger('refund_requested_cents')->nullable();
                $table->unsignedInteger('refunded_cents')->default(0);
                $table->timestamp('refunded_at')->nullable();
            });
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->nullable();
        });

        // Backfill. Anything that was ever confirmed as paid is `paid`.
        // Cancelled reservations from before this migration stay `unpaid`: the
        // old schema can't say whether they were refunded or forfeited.
        DB::table('reservations')->where('status', 'active')->update(['payment_status' => 'paid']);
        DB::table('purchases')->whereIn('status', ['active', 'used_up'])->update(['payment_status' => 'paid']);

        // `refunded` used to be a purchase *status*; it is a payment state now,
        // and the purchase itself is just cancelled.
        DB::table('purchases')->where('status', 'refunded')->update([
            'status' => 'cancelled',
            'payment_status' => 'refunded',
            'refunded_cents' => DB::raw('COALESCE(price_cents, 0)'),
            'refund_requested_cents' => DB::raw('COALESCE(price_cents, 0)'),
            'refunded_at' => DB::raw('updated_at'),
        ]);

        // Best available figure for existing subscribers: the plan's price
        // today, found the same way the old view did (by Stripe product).
        DB::statement(<<<'SQL'
            UPDATE subscriptions SET price_cents = (
                SELECT subscription_types.price_cents
                FROM subscription_items
                JOIN subscription_types ON subscription_types.stripe_product_id = subscription_items.stripe_product
                WHERE subscription_items.subscription_id = subscriptions.id
                LIMIT 1
            )
        SQL);
    }

    public function down(): void
    {
        DB::table('purchases')
            ->where('status', 'cancelled')
            ->where('payment_status', 'refunded')
            ->update(['status' => 'refunded']);

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('price_cents');
        });

        foreach (['purchases', 'reservations'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['payment_status', 'refund_requested_cents', 'refunded_cents', 'refunded_at']);
            });
        }
    }
};
