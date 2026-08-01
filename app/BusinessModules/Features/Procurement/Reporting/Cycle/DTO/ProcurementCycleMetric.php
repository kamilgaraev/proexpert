<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

final readonly class ProcurementCycleMetric
{
    public int $numerator;

    public int $denominator;

    public function __construct(
        public ?string $startAt,
        public ?string $endAt,
        public ?int $durationSeconds,
        public int $slaSeconds,
        public bool $eligible,
        public ?bool $slaMet,
        public ?string $gapCode = null,
    ) {
        $this->numerator = $this->eligible && $this->slaMet === true ? 1 : 0;
        $this->denominator = $this->eligible ? 1 : 0;
    }

    public function toArray(): array
    {
        return [
            'start_at' => $this->startAt,
            'end_at' => $this->endAt,
            'duration_seconds' => $this->durationSeconds,
            'sla_seconds' => $this->slaSeconds,
            'eligible' => $this->eligible,
            'sla_met' => $this->slaMet,
            'numerator' => $this->numerator,
            'denominator' => $this->denominator,
            'gap_code' => $this->gapCode,
        ];
    }
}
