<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class PlanFactDrillDownItem
{
    public function __construct(
        public string $sourceType,
        public int|string|null $sourceId,
        public ?string $number,
        public ?string $title,
        public string $date,
        public float $amount,
        public string $currency,
        public string $status,
        public array $routeHint,
        public float $varianceContribution,
    ) {
    }

    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'number' => $this->number,
            'title' => $this->title,
            'date' => $this->date,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'route_hint' => $this->routeHint,
            'variance_contribution' => $this->varianceContribution,
        ];
    }
}
