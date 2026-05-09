<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs;

final readonly class PackagePlanData
{
    /**
     * @param array<int, array<string, mixed>> $packages
     * @param array<int, string> $assumptions
     */
    public function __construct(
        public array $packages,
        public array $assumptions = [],
    ) {
    }

    public function targetItemsMinTotal(): int
    {
        return array_sum(array_map(
            static fn (array $package): int => (int) ($package['target_items_min'] ?? 0),
            $this->packages
        ));
    }

    public function targetItemsMaxTotal(): int
    {
        return array_sum(array_map(
            static fn (array $package): int => (int) ($package['target_items_max'] ?? 0),
            $this->packages
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'packages' => $this->packages,
            'assumptions' => $this->assumptions,
            'target_items_min_total' => $this->targetItemsMinTotal(),
            'target_items_max_total' => $this->targetItemsMaxTotal(),
        ];
    }
}
