<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reconstructs "how many riders were actually inside the park" at each hour
 * from the check_in_events log, rather than just counting check-ins. A
 * check-in without a matching check-out still counts as occupied for every
 * later hour, and a check-out drops the count — this is a running balance,
 * not a per-hour tally.
 */
class CheckInOccupancy
{
    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    public static function hourly(Carbon $start, int $hours): array
    {
        $baseline = self::occupancyBefore($start);

        $netChangeByHour = DB::table('check_in_events')
            ->where('created_at', '>=', $start->toDateTimeString())
            ->selectRaw("strftime('%Y-%m-%d %H:00:00', created_at) as hour, SUM(CASE WHEN checked_in THEN 1 ELSE -1 END) as net")
            ->groupBy('hour')
            ->pluck('net', 'hour');

        $labels = [];
        $values = [];
        $running = $baseline;

        for ($i = 0; $i < $hours; $i++) {
            $bucket = $start->copy()->addHours($i);

            $running += (int) ($netChangeByHour[$bucket->format('Y-m-d H:00:00')] ?? 0);
            // Clamp defensively: a user checked in before check_in_events
            // existed (or before this feature shipped) has no logged entry
            // event, so a later checkout for them can otherwise dip the
            // running balance below zero.
            $running = max(0, $running);

            $labels[] = $hours <= 24
                ? $bucket->format('H:00')
                : $bucket->format('D H:00');
            $values[] = $running;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * How many riders were already inside, right before $moment — the
     * starting balance the hourly running total needs to build from.
     */
    private static function occupancyBefore(Carbon $moment): int
    {
        return (int) DB::table('check_in_events')
            ->fromSub(function ($query) use ($moment) {
                $query->from('check_in_events')
                    ->where('created_at', '<', $moment->toDateTimeString())
                    ->selectRaw('user_id, checked_in, ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY created_at DESC, id DESC) as rn');
            }, 'latest_per_user')
            ->where('rn', 1)
            ->where('checked_in', true)
            ->count();
    }
}
