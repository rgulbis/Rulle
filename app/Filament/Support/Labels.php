<?php

namespace App\Filament\Support;

/**
 * Translated labels for the status-like values the admin tables show. Each
 * is keyed by its English text (the project's lang/lv.json convention), so a
 * value without a Latvian entry still reads sensibly.
 */
class Labels
{
    /** "partially_refunded" => "Partially refunded", in the admin's language. */
    public static function humanise(string $state): string
    {
        return __(str($state)->replace('_', ' ')->ucfirst()->toString());
    }

    /**
     * @return array<string, string>
     */
    public static function paymentStatuses(): array
    {
        return [
            'unpaid' => __('Unpaid'),
            'paid' => __('Paid'),
            'refunded' => __('Refunded'),
            'partially_refunded' => __('Partially refunded'),
            'refund_failed' => __('Refund failed'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function roles(): array
    {
        return [
            'admin' => __('Admin'),
            'employee' => __('Employee'),
            'user' => __('User'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function billingIntervals(): array
    {
        return [
            'one_time' => __('One-time'),
            'month' => __('Monthly'),
            'year' => __('Yearly'),
        ];
    }
}
