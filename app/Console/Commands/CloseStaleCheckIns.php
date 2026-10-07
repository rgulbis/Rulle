<?php

namespace App\Console\Commands;

use App\Events\OccupancyUpdated;
use App\Events\UserCheckInStatusUpdated;
use App\Models\CheckInEvent;
use App\Models\ReservationSetting;
use App\Models\User;
use App\Support\CheckInOccupancy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class CloseStaleCheckIns extends Command
{
    protected $signature = 'checkins:close-stale';

    protected $description = 'Check out riders still marked as inside after the park closed or the maximum visit length passed';

    public function handle(): int
    {
        $settings = ReservationSetting::current();
        $maxVisit = (int) config('checkin.max_visit_minutes');
        $closed = [];

        foreach (CheckInOccupancy::openCheckIns() as $open) {
            $checkedInAt = Carbon::parse($open->created_at);

            // Whichever ends the visit first: the park closing, or the
            // longest a visit can last.
            $endedAt = $this->nextClosing($checkedInAt, $settings)
                ->min($checkedInAt->copy()->addMinutes($maxVisit));

            if ($endedAt->isFuture()) {
                continue;
            }

            if ($this->checkOut($open->user_id, $open->id, $endedAt)) {
                $closed[] = $open->user_id;
            }
        }

        $this->info('Stale check-ins closed: '.count($closed).'.');

        $this->broadcast($closed);

        return self::SUCCESS;
    }

    /**
     * The first moment after $after that the park closes. Works for a park
     * that closes after midnight too: today's closing is behind a late
     * check-in, so it rolls to the next day's.
     */
    private function nextClosing(Carbon $after, ReservationSetting $settings): Carbon
    {
        $minutes = $settings->closingMinutes();
        $closing = $after->copy()->setTime(intdiv($minutes, 60), $minutes % 60);

        return $closing->gt($after) ? $closing : $closing->addDay();
    }

    /**
     * Writes the missing check-out, dated when the visit actually ended.
     * Re-checked under the transaction's write lock: a scan that got there
     * first (the rider scanned out, or in again) is left alone.
     */
    private function checkOut(int $userId, int $checkInEventId, Carbon $endedAt): bool
    {
        return DB::transaction(function () use ($userId, $checkInEventId, $endedAt) {
            $latestId = CheckInEvent::where('user_id', $userId)->max('id');

            if ($latestId !== $checkInEventId) {
                return false;
            }

            (new CheckInEvent)->forceFill([
                'user_id' => $userId,
                'checked_in' => false,
                'created_at' => $endedAt,
                'updated_at' => $endedAt,
            ])->save();

            return true;
        });
    }

    /**
     * Tells open dashboards and the live headcount. After the commit, and
     * never a reason to fail the job: the check-outs are already recorded.
     *
     * @param  array<int, int>  $userIds
     */
    private function broadcast(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        try {
            User::whereIn('id', $userIds)->each(fn (User $user) => UserCheckInStatusUpdated::dispatch($user));
            OccupancyUpdated::dispatch();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
