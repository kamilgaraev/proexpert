<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

final readonly class ApplyGeneratedEstimateResult
{
    public function __construct(
        public int $estimateId,
        public bool $created,
    ) {}

    /** @return array{estimate_id: int, created: bool} */
    public function toArray(): array
    {
        return [
            'estimate_id' => $this->estimateId,
            'created' => $this->created,
        ];
    }
}
