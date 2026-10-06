<?php

namespace App\Support;

use App\Models\CheckInEvent;
use App\Models\ReservationSetting;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
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
     * How busy each hour of the day (0-23) typically is, collapsed across
     * all historical dates — used as a "peak hours" reference line behind
     * the reservation timeline, not tied to any specific date. Returns raw
     * check-in counts, not normalized; callers scale for display.
     *
     * @return array<int, int>
     */
    public static function typicalCheckInsByHour(): array
    {
        $countsByHour = DB::table('check_in_events')
            ->where('checked_in', true)
            ->selectRaw("CAST(strftime('%H', created_at) AS INTEGER) as hour, count(*) as total")
            ->groupBy('hour')
            ->pluck('total', 'hour');

        return collect(range(0, 23))
            ->mapWithKeys(fn (int $hour) => [$hour => (int) ($countsByHour[$hour] ?? 0)])
            ->all();
    }

    /**
     * How many riders are inside the park right now — the source of truth
     * for the live headcount, derived from everyone's latest event rather
     * than a cached column on `users`.
     */
    public static function currentlyCheckedInCount(): int
    {
        return self::latestEventPerUser()->where('checked_in', true)->count();
    }

    /**
     * The last time the park closed (today's closing time, or yesterday's
     * if it hasn't closed yet today).
     */
    public static function lastClosing(): CarbonInterface
    {
        $minutes = ReservationSetting::current()->closingMinutes();
        $closing = now()->startOfDay()->addMinutes($minutes);

        return $closing->isFuture() ? $closing->subDay() : $closing;
    }

    /**
     * Someone who was scanned in but never scanned out is still "inside" in
     * the log forever — the live headcount is wrong, and they're refused
     * "already checked in" the next day. Anyone whose latest event is an
     * entry from before the park last closed has left: they're checked out
     * at the closing time, so the occupancy history is right too.
     *
     * @return int how many people were checked out
     */
    public static function closeStaleCheckIns(): int
    {
        $closing = self::lastClosing();

        $stale = self::latestEventPerUser()
            ->where('checked_in', true)
            ->pluck('user_id')
            ->filter(function (int $userId) use ($closing) {
                $entered = CheckInEvent::where('user_id', $userId)->orderByDesc('id')->first();

                return $entered && $entered->created_at->lt($closing);
            });

        foreach ($stale as $userId) {
            $event = new CheckInEvent(['user_id' => $userId, 'checked_in' => false]);
            $event->created_at = $closing;
            $event->save();
        }

        return $stale->count();
    }

    /**
     * How many riders were already inside, right before $moment — the
     * starting balance the hourly running total needs to build from.
     */
    private static function occupancyBefore(Carbon $moment): int
    {
        return self::latestEventPerUser($moment)->where('checked_in', true)->count();
    }

    /**
     * Each user's most recent check_in_events row (as of $before, if given),
     * one row per user_id.
     */
    private static function latestEventPerUser(?Carbon $before = null): Builder
    {
        return DB::table('check_in_events')
            ->fromSub(function ($query) use ($before) {
                $query->from('check_in_events')
                    ->when($before, fn ($q) => $q->where('created_at', '<', $before->toDateTimeString()))
                    ->selectRaw('user_id, checked_in, ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY created_at DESC, id DESC) as rn');
            }, 'latest_per_user')
            ->where('rn', 1);
    }
}
