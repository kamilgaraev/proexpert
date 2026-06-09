<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class WipForecastManualAdjustment
{
    public function __construct(
        public ?string $periodMonth,
        public ?int $projectId,
        public ?int $stageId,
        public ?int $contractId,
        public ?int $estimateItemId,
        public string $currency,
        public string $formulaComponent,
        public float $amount,
        public ?string $reason,
        public string $status,
    ) {
    }

    public function isApplicable(): bool
    {
        return in_array($this->status, ['approved', 'active'], true)
            && is_string($this->reason)
            && trim($this->reason) !== '';
    }
}
