<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $price_cents_per_person_per_hour
 * @property int $min_group_size
 * @property int $min_duration_minutes
 * @property int $max_duration_minutes
 * @property string $opening_time
 * @property string $closing_time
 */
#[Fillable([
    'price_cents_per_person_per_hour',
    'min_group_size',
    'min_duration_minutes',
    'max_duration_minutes',
    'opening_time',
    'closing_time',
])]
class ReservationSetting extends Model
{
    protected function casts(): array
    {
        return [
            'price_cents_per_person_per_hour' => 'integer',
            'min_group_size' => 'integer',
            'min_duration_minutes' => 'integer',
            'max_duration_minutes' => 'integer',
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
            'min_duration_minutes' => 30,
            'max_duration_minutes' => 240,
            'opening_time' => '08:00',
            'closing_time' => '23:00',
        ]);
    }

    public function priceFor(int $minutes, int $groupSize): int
    {
        return (int) ceil($minutes / 60 * $this->price_cents_per_person_per_hour * $groupSize);
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
