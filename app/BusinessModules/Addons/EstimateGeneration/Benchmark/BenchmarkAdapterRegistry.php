<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use InvalidArgumentException;

final readonly class BenchmarkAdapterRegistry
{
    /** @var array<string, BenchmarkPipelineAdapter> */
    private array $adapters;

    /** @param iterable<BenchmarkPipelineAdapter> $adapters */
    public function __construct(iterable $adapters)
    {
        $indexed = [];
        foreach ($adapters as $adapter) {
            if (isset($indexed[$adapter->id()]) || ! preg_match('/^[a-z][a-z0-9-]{2,63}$/', $adapter->id())) {
                throw new InvalidArgumentException('benchmark_adapter_invalid');
            }
            $indexed[$adapter->id()] = $adapter;
        }
        $this->adapters = $indexed;
    }

    public function get(string $id): BenchmarkPipelineAdapter
    {
        return $this->adapters[$id] ?? throw new InvalidArgumentException('benchmark_adapter_unknown');
    }

    public function has(string $id): bool
    {
        return isset($this->adapters[$id]);
    }
}
