<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class FakeTime extends Command
{
    protected $signature = 'time:fake {when? : A date/time string, e.g. "2026-09-17 22:30:00", "+3 hours", or just "+3" for hours} {--clear : Stop faking and go back to the real time}';

    protected $description = 'Make the app think it is a different time, for testing time-dependent features locally';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refusing to fake the time in production.');

            return self::FAILURE;
        }

        $path = storage_path('framework/testing/fake-now.txt');

        if ($this->option('clear')) {
            @unlink($path);
            $this->info('Fake time cleared — the app is back to the real time.');

            return self::SUCCESS;
        }

        $when = $this->argument('when');

        if (! $when) {
            if (is_file($path)) {
                $this->info('Currently faking: '.trim(file_get_contents($path) ?: ''));
            } else {
                $this->info('Not faking the time — the app is using the real time.');
            }

            return self::SUCCESS;
        }

        // A bare signed/unsigned integer (e.g. "+3") is ambiguous to Carbon's
        // parser — it reads as a Unix timestamp rather than an offset. Treat
        // it as hours, which is what anyone typing "+3" actually means.
        if (preg_match('/^[+-]?\d+$/', $when)) {
            $when .= ' hours';
        }

        $fakeNow = CarbonImmutable::parse($when);
        file_put_contents($path, $fakeNow->toDateTimeString());

        $this->info("The app will now think it's {$fakeNow->toDateTimeString()}, until you run --clear.");
        $this->comment('This only affects requests served by this app (web + artisan) — reload the page to see it take effect.');

        return self::SUCCESS;
    }
}
