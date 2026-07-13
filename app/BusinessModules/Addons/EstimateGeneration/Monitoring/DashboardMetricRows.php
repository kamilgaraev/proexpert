<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Monitoring;

final readonly class DashboardMetricRows
{
    /**
     * @param  array<string, mixed>  $sessions
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $queue
     */
    public function __construct(
        public array $sessions,
        public array $usage,
        public array $queue,
    ) {}
}
