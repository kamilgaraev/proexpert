<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class WipForecastDimensions
{
    public function __construct(
        public array $projects = [],
        public array $stages = [],
        public array $contracts = [],
        public array $estimateItems = [],
    ) {
    }

    public function project(?int $id): ?array
    {
        return $id === null ? null : ($this->projects[$id] ?? null);
    }

    public function stage(?int $id): ?array
    {
        return $id === null ? null : ($this->stages[$id] ?? null);
    }

    public function contract(?int $id): ?array
    {
        return $id === null ? null : ($this->contracts[$id] ?? null);
    }

    public function estimateItem(?int $id): ?array
    {
        return $id === null ? null : ($this->estimateItems[$id] ?? null);
    }
}
