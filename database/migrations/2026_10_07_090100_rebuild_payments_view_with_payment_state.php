<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Same ledger as before, now carrying the money state of each row
     * (`payment_status`, `refunded_cents`), and with the subscription amount
     * read from what was billed (`subscriptions.price_cents`) instead of
     * today's plan price. The plan join remains only for the display name.
     *
     * A subscription has no per-row refund bookkeeping here - its payments
     * live on Stripe invoices - so its `payment_status` is derived from the
     * Stripe subscription status: anything Stripe is still waiting on is
     * `unpaid`, the rest has been paid for.
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS payments');

        DB::statement(<<<'SQL'
            CREATE VIEW payments AS
            SELECT
                'purchase-' || purchases.id AS id,
                'purchase' AS type,
                purchases.user_id AS user_id,
                subscription_types.name AS description,
                purchases.price_cents AS amount_cents,
                purchases.status AS status,
                purchases.payment_status AS payment_status,
                purchases.refunded_cents AS refunded_cents,
                purchases.created_at AS created_at
            FROM purchases
            LEFT JOIN subscription_types ON subscription_types.id = purchases.subscription_type_id

            UNION ALL

            SELECT
                'reservation-' || reservations.id AS id,
                'reservation' AS type,
                reservations.user_id AS user_id,
                'Park reservation' AS description,
                reservations.price_cents AS amount_cents,
                reservations.status AS status,
                reservations.payment_status AS payment_status,
                reservations.refunded_cents AS refunded_cents,
                reservations.created_at AS created_at
            FROM reservations

            UNION ALL

            SELECT
                'subscription-' || subscriptions.id AS id,
                'subscription' AS type,
                subscriptions.user_id AS user_id,
                COALESCE(subscription_types.name, 'Subscription') AS description,
                COALESCE(subscriptions.price_cents, 0) AS amount_cents,
                subscriptions.stripe_status AS status,
                CASE
                    WHEN subscriptions.stripe_status IN ('incomplete', 'incomplete_expired', 'unpaid', 'past_due') THEN 'unpaid'
                    ELSE 'paid'
                END AS payment_status,
                0 AS refunded_cents,
                subscriptions.created_at AS created_at
            FROM subscriptions
            LEFT JOIN subscription_items ON subscription_items.subscription_id = subscriptions.id
            LEFT JOIN subscription_types ON subscription_types.stripe_product_id = subscription_items.stripe_product
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS payments');

        DB::statement(<<<'SQL'
            CREATE VIEW payments AS
            SELECT
                'purchase-' || purchases.id AS id,
                'purchase' AS type,
                purchases.user_id AS user_id,
                subscription_types.name AS description,
                purchases.price_cents AS amount_cents,
                purchases.status AS status,
                purchases.created_at AS created_at
            FROM purchases
            LEFT JOIN subscription_types ON subscription_types.id = purchases.subscription_type_id

            UNION ALL

            SELECT
                'reservation-' || reservations.id AS id,
                'reservation' AS type,
                reservations.user_id AS user_id,
                'Park reservation' AS description,
                reservations.price_cents AS amount_cents,
                reservations.status AS status,
                reservations.created_at AS created_at
            FROM reservations

            UNION ALL

            SELECT
                'subscription-' || subscriptions.id AS id,
                'subscription' AS type,
                subscriptions.user_id AS user_id,
                COALESCE(subscription_types.name, 'Subscription') AS description,
                COALESCE(subscription_types.price_cents, 0) AS amount_cents,
                subscriptions.stripe_status AS status,
                subscriptions.created_at AS created_at
            FROM subscriptions
            LEFT JOIN subscription_items ON subscription_items.subscription_id = subscriptions.id
            LEFT JOIN subscription_types ON subscription_types.stripe_product_id = subscription_items.stripe_product
        SQL);
    }
};
