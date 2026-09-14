<?php

namespace App\Filament\Widgets;

use App\Models\CheckInEvent;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class CheckInsByHourChart extends ChartWidget
{
    protected ?string $heading = 'Check-ins by Hour';

    protected ?string $pollingInterval = '30s';

    public ?string $filter = '7d';

    protected function getFilters(): ?array
    {
        return [
            '24h' => 'Last 24 hours',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
        ];
    }

    protected function getData(): array
    {
        $hours = match ($this->filter) {
            '24h' => 24,
            '30d' => 24 * 30,
            default => 24 * 7,
        };

        $start = Carbon::now()->subHours($hours - 1)->startOfHour();

        // SQLite-specific bucketing: the app only ever runs on sqlite (see
        // config/database.php), so this doesn't need to be driver-agnostic.
        $counts = CheckInEvent::query()
            ->where('checked_in', true)
            ->where('created_at', '>=', $start->toDateTimeString())
            ->selectRaw("strftime('%Y-%m-%d %H:00:00', created_at) as hour, count(*) as total")
            ->groupBy('hour')
            ->pluck('total', 'hour');

        $labels = [];
        $data = [];

        for ($i = 0; $i < $hours; $i++) {
            $bucket = $start->copy()->addHours($i);

            $labels[] = $hours <= 24
                ? $bucket->format('H:00')
                : $bucket->format('M j, H:00');

            $data[] = (int) ($counts[$bucket->format('Y-m-d H:00:00')] ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Check-ins',
                    'data' => $data,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
