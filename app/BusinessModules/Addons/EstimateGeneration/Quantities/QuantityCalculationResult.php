<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final readonly class QuantityCalculationResult
{
    /** @param array<string, QuantityData> $quantities @param array<int, array{code: string, severity: string, path: string}> $diagnostics */
    /** @param array<string, int> $metrics */
    public function __construct(private array $quantities, public array $diagnostics, public array $metrics = []) {}

    public function get(string $key): ?QuantityData
    {
        return $this->quantities[$key] ?? null;
    }

    /** @return array<string, QuantityData> */
    public function all(): array
    {
        return $this->quantities;
    }

    /** @return array{quantities: array<int, array<string, mixed>>, diagnostics: array<int, array{code: string, severity: string, path: string}>, metrics: array<string, int>} */
    public function toArray(): array
    {
        return [
            'quantities' => array_values(array_map(static fn (QuantityData $quantity): array => $quantity->toArray(), $this->quantities)),
            'diagnostics' => $this->diagnostics,
            'metrics' => $this->metrics,
        ];
    }
}
