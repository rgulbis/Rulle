<?php

namespace App\Console\Commands;

use App\Models\CheckInEvent;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('checkins:time-skip {--days=30 : How many days of history to generate} {--users=15 : Minimum pool of customer accounts to spread visits across}')]
#[Description('Backfill fake check-in history so the admin stats charts have something to show, without waiting for real usage. Local/dev only.')]
class CheckInsTimeSkip extends Command
{
    /**
     * Visits are weighted toward these hours (skatepark is quiet in the
     * morning, busiest after school lets out).
     *
     * @var array<int, int>
     */
    private const HOUR_WEIGHTS = [
        8 => 1, 9 => 1, 10 => 2, 11 => 2, 12 => 3, 13 => 3,
        14 => 5, 15 => 8, 16 => 9, 17 => 9, 18 => 7, 19 => 5,
        20 => 3, 21 => 1,
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->components->error('This command backfills fake data and is for the local/dev environment only.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $minUsers = max(1, (int) $this->option('users'));

        $customers = User::where('role', 'user')->get();

        if ($customers->count() < $minUsers) {
            $needed = $minUsers - $customers->count();
            $this->components->info("Creating {$needed} extra demo customer accounts...");
            $customers = $customers->merge(User::factory()->count($needed)->create());
        }

        $this->components->warn('Clearing existing check-in history...');
        CheckInEvent::truncate();

        $rows = [];
        $now = Carbon::now();

        $this->components->info("Generating {$days} days of fake check-in activity for {$customers->count()} customers...");

        $this->withProgressBar(range(0, $days - 1), function (int $dayOffset) use (&$rows, $now, $customers) {
            $day = $now->copy()->subDays($dayOffset)->startOfDay();
            $isWeekend = $day->isWeekend();
            $visitCount = random_int($isWeekend ? 15 : 5, $isWeekend ? 35 : 20);

            for ($i = 0; $i < $visitCount; $i++) {
                $hour = $this->weightedHour();
                $checkIn = $day->copy()->setTime($hour, random_int(0, 59), random_int(0, 59));

                if ($checkIn->greaterThan($now)) {
                    continue;
                }

                $user = $customers->random();
                $checkOut = $checkIn->copy()->addMinutes(random_int(20, 120));

                $rows[] = [
                    'user_id' => $user->id,
                    'checked_in' => true,
                    'created_at' => $checkIn,
                    'updated_at' => $checkIn,
                ];

                $rows[] = [
                    'user_id' => $user->id,
                    'checked_in' => false,
                    'created_at' => $checkOut->lessThan($now) ? $checkOut : $now,
                    'updated_at' => $checkOut->lessThan($now) ? $checkOut : $now,
                ];
            }
        });

        $this->newLine(2);

        foreach (array_chunk($rows, 500) as $chunk) {
            CheckInEvent::insert($chunk);
        }

        $this->components->info(count($rows).' check-in events created. This does not affect anyone\'s live checked-in status.');

        return self::SUCCESS;
    }

    private function weightedHour(): int
    {
        $total = array_sum(self::HOUR_WEIGHTS);
        $pick = random_int(1, $total);

        foreach (self::HOUR_WEIGHTS as $hour => $weight) {
            if ($pick <= $weight) {
                return $hour;
            }

            $pick -= $weight;
        }

        return array_key_first(self::HOUR_WEIGHTS);
    }
}
