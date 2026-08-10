<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentRepresentationMeasurement
{
    public function __construct(
        public mixed $result,
        public int $durationMs,
        public int $incrementalPeakMemoryBytes,
        public array $limitations,
        public string $memoryMetric = 'incremental_process_peak_delta',
    ) {}
}
