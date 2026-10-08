<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $price_cents_per_person_per_hour
 * @property int $min_group_size
 * @property int $max_group_size
 * @property int $min_duration_minutes
 * @property int $max_duration_minutes
 * @property string $opening_time
 * @property string $closing_time
 * @property int $cancellation_cutoff_hours
 */
#[Fillable([
    'price_cents_per_person_per_hour',
    'min_group_size',
    'max_group_size',
    'min_duration_minutes',
    'max_duration_minutes',
    'opening_time',
    'closing_time',
    'cancellation_cutoff_hours',
])]
class ReservationSetting extends Model
{
    // Upper limits the admin form enforces, so a typo can't ship an absurd
    // value to every booking.
    public const MAX_PRICE_CENTS_PER_PERSON_PER_HOUR = 10000;

    public const MAX_GROUP_SIZE_LIMIT = 500;

    public const MAX_DURATION_LIMIT_MINUTES = 1440;

    public const MAX_CANCELLATION_CUTOFF_HOURS = 720;

    protected function casts(): array
    {
        return [
            'price_cents_per_person_per_hour' => 'integer',
            'min_group_size' => 'integer',
            'max_group_size' => 'integer',
            'min_duration_minutes' => 'integer',
            'max_duration_minutes' => 'integer',
            'cancellation_cutoff_hours' => 'integer',
        ];
    }

    /**
     * Single-row settings — there's only ever one reservation pricing rate.
     */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'price_cents_per_person_per_hour' => 500,
            'min_group_size' => 3,
            'max_group_size' => 50,
            'min_duration_minutes' => 30,
            'max_duration_minutes' => 240,
            'opening_time' => '08:00',
            'closing_time' => '23:00',
            'cancellation_cutoff_hours' => 24,
        ]);
    }

    public function priceFor(int $minutes, int $groupSize): int
    {
        return (int) ceil($minutes / 60 * $this->price_cents_per_person_per_hour * $groupSize);
    }

    /**
     * Whether a reservation starting at $startsAt is still far enough away
     * to be cancelled for a full refund.
     */
    public function isEligibleForCancellationRefund(CarbonInterface $startsAt): bool
    {
        return now()->addHours($this->cancellation_cutoff_hours)->lte($startsAt);
    }

    /**
     * How many paid reservations still to come (or under way) would end up
     * outside the park's hours if it opened at $opening and closed at
     * $closing. A reservation is only checked against the hours when it is
     * booked, so changing them later would otherwise leave paid time the park
     * is shut for. Pass null for a bound that is not being changed.
     */
    public static function reservationsOutsideHours(?string $opening, ?string $closing): int
    {
        $isTime = fn (?string $time) => is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
        $minutesOfDay = fn (CarbonInterface $moment) => $moment->hour * 60 + $moment->minute;

        $opens = $isTime($opening) ? self::timeToMinutes($opening) : null;
        $closes = $isTime($closing) ? self::timeToMinutes($closing) : null;

        if ($opens === null && $closes === null) {
            return 0;
        }

        return Reservation::query()
            ->where('status', 'active')
            ->where('ends_at', '>', now())
            ->get(['id', 'starts_at', 'ends_at'])
            ->filter(fn (Reservation $reservation) => ($opens !== null && $minutesOfDay($reservation->starts_at) < $opens)
                || ($closes !== null && $minutesOfDay($reservation->ends_at) > $closes))
            ->count();
    }

    public function openingMinutes(): int
    {
        return self::timeToMinutes($this->opening_time);
    }

    public function closingMinutes(): int
    {
        return self::timeToMinutes($this->closing_time);
    }

    private static function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return ((int) $hours * 60) + (int) $minutes;
    }
}
