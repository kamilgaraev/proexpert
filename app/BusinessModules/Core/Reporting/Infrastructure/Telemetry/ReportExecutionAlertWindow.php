<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Telemetry;

final class ReportExecutionAlertWindow
{
    private const MIN_RATIO_SAMPLE = 20;

    /** @var array<string, array{total:int,failed:int}> */
    private array $ratios = [];

    /** @var array<string, bool> */
    private array $ratioAlerts = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, array{count:int,mean:float}> */
    private array $durations = [];

    public function ratioExceeded(
        string $key,
        bool $failed,
        float $threshold,
    ): bool {
        $state = $this->ratios[$key] ?? ['total' => 0, 'failed' => 0];
        $state['total']++;
        $state['failed'] += $failed ? 1 : 0;
        if ($state['total'] > 1000) {
            $state = [
                'total' => intdiv($state['total'], 2),
                'failed' => intdiv($state['failed'], 2),
            ];
        }
        $this->ratios[$key] = $state;

        $exceeded = $state['total'] >= self::MIN_RATIO_SAMPLE
            && ($state['failed'] / $state['total']) >= $threshold;
        $wasExceeded = $this->ratioAlerts[$key] ?? false;
        $this->ratioAlerts[$key] = $exceeded;

        return $exceeded && ! $wasExceeded;
    }

    public function countReached(string $key, int $threshold): bool
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        return $this->counts[$key] % $threshold === 0;
    }

    public function durationRegressed(
        string $key,
        float $seconds,
        float $threshold,
    ): bool {
        $state = $this->durations[$key] ?? ['count' => 0, 'mean' => 0.0];
        $regressed = $state['count'] >= 5
            && $seconds > $state['mean'] * $threshold;
        $count = min(1000, $state['count'] + 1);
        $weight = 1 / min($count, 100);
        $this->durations[$key] = [
            'count' => $count,
            'mean' => $state['count'] === 0
                ? $seconds
                : $state['mean'] + (($seconds - $state['mean']) * $weight),
        ];

        return $regressed;
    }
}
