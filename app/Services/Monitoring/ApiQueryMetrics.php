<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Http\Request;

final class ApiQueryMetrics
{
    public const REQUEST_ATTRIBUTE = 'most.api_query_metrics';

    private int $count = 0;
    private float $total = 0;
    private float $maximum = 0;

    public static function record(Request $request, float $milliseconds): void
    {
        $metrics = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (! $metrics instanceof self || ! is_finite($milliseconds) || $milliseconds < 0) {
            return;
        }

        $metrics->count++;
        $metrics->total += $milliseconds;
        $metrics->maximum = max($metrics->maximum, $milliseconds);
    }

    public function summary(): array
    {
        return [
            'sql_count' => $this->count,
            'sql_total_ms' => round($this->total, 2),
            'sql_max_ms' => round($this->maximum, 2),
        ];
    }
}
