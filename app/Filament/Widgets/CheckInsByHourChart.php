<?php

namespace App\Filament\Widgets;

use App\Support\CheckInOccupancy;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class CheckInsByHourChart extends ChartWidget
{
    protected ?string $heading = 'Occupancy by Hour';

    protected ?string $pollingInterval = '30s';

    public ?string $filter = '7d';

    /**
     * @var array{labels: array<int, string>, values: array<int, int>}|null
     */
    private ?array $series = null;

    protected function getFilters(): ?array
    {
        return [
            '24h' => 'Last 24 hours',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
        ];
    }

    public function getDescription(): ?string
    {
        $series = $this->series();

        if (empty($series['values']) || max($series['values']) === 0) {
            return null;
        }

        $peak = max($series['values']);
        $peakIndex = array_search($peak, $series['values'], true);

        return "Busiest: {$series['labels'][$peakIndex]} ({$peak} riders)";
    }

    protected function getData(): array
    {
        $series = $this->series();

        return [
            'datasets' => [
                [
                    'label' => 'Riders inside',
                    'data' => $series['values'],
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                ],
            ],
            'labels' => $series['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    // A floor for the axis range, not a cap — real data
                    // above this still grows the scale normally. Without
                    // it, a quiet chart (nothing above 1 rider) looks like
                    // a nearly-empty box with a single gridline.
                    'suggestedMax' => 10,
                    'ticks' => [
                        // Occupancy is always a whole number of riders —
                        // without this, Chart.js picks fractional steps
                        // (0.2, 0.4, ...) for small ranges, which reads as
                        // if half a rider is inside.
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function series(): array
    {
        if ($this->series !== null) {
            return $this->series;
        }

        $hours = match ($this->filter) {
            '24h' => 24,
            '30d' => 24 * 30,
            default => 24 * 7,
        };

        $start = Carbon::now()->subHours($hours - 1)->startOfHour();

        return $this->series = CheckInOccupancy::hourly($start, $hours);
    }
}
