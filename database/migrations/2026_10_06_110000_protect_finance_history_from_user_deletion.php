<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting a user used to cascade over their purchases, paid
     * reservations, check-in history and chat — silently rewriting revenue
     * and occupancy statistics after the fact — while Cashier's own tables
     * had no foreign keys at all, leaving orphan subscriptions (still billed
     * by Stripe) behind. Now:
     *
     *  - users are soft-deleted (and anonymised — see User::closeAccount()),
     *    so every row that points at them stays valid;
     *  - a real DELETE of a user that has any history is refused by the
     *    database (restrictOnDelete), whatever the application does;
     *  - Cashier's tables get the foreign keys they lacked;
     *  - each subscription records the amount it was actually billed at, so
     *    the payments ledger doesn't change when a plan's price does.
     *
     * The `payments` view reads these tables, and SQLite refuses to rebuild a
     * table a view depends on, so the view is dropped first and recreated
     * (with payment status and the historical subscription price) at the end.
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS payments');

        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('price_cents')->nullable();
        });

        // Best effort for existing subscriptions: the plan's current price
        // (the amount actually billed was never stored). Every subscription
        // from here on records its own, from Stripe's webhook payload.
        foreach (DB::table('subscription_items')->whereNotNull('stripe_product')->get() as $item) {
            $price = DB::table('subscription_types')->where('stripe_product_id', $item->stripe_product)->value('price_cents');

            if ($price !== null) {
                DB::table('subscriptions')->where('id', $item->subscription_id)->update(['price_cents' => $price]);
            }
        }

        // Subscriptions of users that no longer exist can't be given a
        // foreign key. They're already unreachable from the app.
        $orphans = DB::table('subscriptions')->whereNotIn('user_id', DB::table('users')->select('id'))->pluck('id');
        DB::table('subscription_items')->whereIn('subscription_id', $orphans)->delete();
        DB::table('subscriptions')->whereIn('id', $orphans)->delete();
        DB::table('subscription_items')->whereNotIn('subscription_id', DB::table('subscriptions')->select('id'))->delete();

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('subscription_items', function (Blueprint $table) {
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
        });

        foreach (['purchases', 'reservations', 'check_in_events', 'chat_messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        $this->createPaymentsView();
        $this->createOverlapTriggers();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS payments');

        foreach (['purchases', 'reservations', 'check_in_events', 'chat_messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        Schema::table('subscription_items', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('price_cents');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        $this->createPaymentsView(historical: false);
        $this->createOverlapTriggers();
    }

    /**
     * Rebuilding `reservations` (to change its foreign key) drops the
     * triggers attached to it — see the payment-tracking migration for what
     * they enforce.
     */
    private function createOverlapTriggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS reservations_no_overlapping_active_insert
            BEFORE INSERT ON reservations
            WHEN NEW.status = 'active' AND EXISTS (
                SELECT 1 FROM reservations
                WHERE status = 'active' AND starts_at < NEW.ends_at AND ends_at > NEW.starts_at
            )
            BEGIN
                SELECT RAISE(ABORT, 'overlapping active reservation');
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS reservations_no_overlapping_active_update
            BEFORE UPDATE OF status, starts_at, ends_at ON reservations
            WHEN NEW.status = 'active' AND EXISTS (
                SELECT 1 FROM reservations
                WHERE id != NEW.id AND status = 'active' AND starts_at < NEW.ends_at AND ends_at > NEW.starts_at
            )
            BEGIN
                SELECT RAISE(ABORT, 'overlapping active reservation');
            END
        SQL);
    }

    /**
     * One ledger across one-time passes, reservations and subscriptions.
     * `status` is the item's lifecycle status, `payment_status` the money.
     */
    private function createPaymentsView(bool $historical = true): void
    {
        // $historical = false rebuilds the view exactly as it was before this
        // migration (no payment columns — they don't exist once the earlier
        // migration is rolled back too).
        $subscriptionAmount = $historical
            ? 'COALESCE(subscriptions.price_cents, subscription_types.price_cents, 0)'
            : 'COALESCE(subscription_types.price_cents, 0)';

        $purchaseMoney = $historical ? "purchases.payment_status AS payment_status,\n                purchases.refunded_cents AS refunded_cents,\n                " : '';
        $reservationMoney = $historical ? "reservations.payment_status AS payment_status,\n                reservations.refunded_cents AS refunded_cents,\n                " : '';
        $subscriptionMoney = $historical
            ? "CASE WHEN subscriptions.stripe_status IN ('active', 'trialing', 'past_due', 'canceled') THEN 'paid' ELSE 'unpaid' END AS payment_status,\n                0 AS refunded_cents,\n                "
            : '';

        DB::statement(<<<SQL
            CREATE VIEW payments AS
            SELECT
                'purchase-' || purchases.id AS id,
                'purchase' AS type,
                purchases.user_id AS user_id,
                subscription_types.name AS description,
                purchases.price_cents AS amount_cents,
                purchases.status AS status,
                {$purchaseMoney}purchases.created_at AS created_at
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
                {$reservationMoney}reservations.created_at AS created_at
            FROM reservations

            UNION ALL

            SELECT
                'subscription-' || subscriptions.id AS id,
                'subscription' AS type,
                subscriptions.user_id AS user_id,
                COALESCE(subscription_types.name, 'Subscription') AS description,
                {$subscriptionAmount} AS amount_cents,
                subscriptions.stripe_status AS status,
                {$subscriptionMoney}subscriptions.created_at AS created_at
            FROM subscriptions
            LEFT JOIN subscription_items ON subscription_items.subscription_id = subscriptions.id
            LEFT JOIN subscription_types ON subscription_types.stripe_product_id = subscription_items.stripe_product
        SQL);
    }
};
