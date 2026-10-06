<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reservation's or pass's lifecycle status ("active", "cancelled"…)
     * said nothing about the money: "cancelled" meant both "refunded in
     * full" and "called off too late, payment kept", and revenue stats that
     * only counted "active" rows silently dropped the latter. Money now has
     * its own state, recorded when Stripe confirms it:
     *
     *   unpaid -> paid -> refund_pending -> refunded
     *                                    \-> refund_failed (retried later)
     *
     * `price_cents` stays the amount charged; `refunded_cents` is how much
     * of it went back to the customer.
     *
     * Also adds a database-level guard so two paid reservations can never
     * overlap, whatever the application code does (SQLite only — the
     * analytics/payments view already tie this project to SQLite).
     */
    public function up(): void
    {
        foreach (['reservations', 'purchases'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('payment_status')->default('unpaid');
                $table->string('stripe_payment_intent_id')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedInteger('refunded_cents')->default(0);
                $table->timestamp('refunded_at')->nullable();
            });
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
        });

        // Existing rows: an active reservation / usable pass was paid. A
        // refunded pass was refunded in full. Cancelled reservations can't
        // be told apart (refunded vs. forfeited vs. never paid) from local
        // data alone, so they stay "unpaid" and need a manual Stripe check.
        DB::table('reservations')->where('status', 'active')->update([
            'payment_status' => 'paid',
            'paid_at' => DB::raw('created_at'),
        ]);
        DB::table('purchases')->whereIn('status', ['active', 'used_up'])->update([
            'payment_status' => 'paid',
            'paid_at' => DB::raw('created_at'),
        ]);
        DB::table('purchases')->where('status', 'refunded')->update([
            'payment_status' => 'refunded',
            'paid_at' => DB::raw('created_at'),
            'refunded_cents' => DB::raw('COALESCE(price_cents, 0)'),
            'refunded_at' => DB::raw('updated_at'),
        ]);

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE TRIGGER reservations_no_overlapping_active_insert
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
                CREATE TRIGGER reservations_no_overlapping_active_update
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
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS reservations_no_overlapping_active_insert');
        DB::statement('DROP TRIGGER IF EXISTS reservations_no_overlapping_active_update');

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'stripe_payment_intent_id', 'paid_at', 'refunded_cents', 'refunded_at', 'cancelled_at']);
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'stripe_payment_intent_id', 'paid_at', 'refunded_cents', 'refunded_at']);
        });
    }
};
