<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs;

final readonly class EstimateGenerationQualityReportData
{
    /**
     * @param array<int, string> $criticalFlags
     * @param array<int, string> $warningFlags
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public string $level,
        public array $criticalFlags,
        public array $warningFlags,
        public array $metrics,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'critical_flags' => $this->criticalFlags,
            'warning_flags' => $this->warningFlags,
            'metrics' => $this->metrics,
        ];
    }
}
