<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class SelectedRegionalPriceContext
{
    public function __construct(private EstimateGenerationRegionalContextResolver $resolver) {}

    public function replace(array $input, int $versionId): array
    {
        $resolved = $this->resolver->resolve([
            'estimate_regional_price_version_id' => $versionId,
        ]);
        if (
            (int) ($resolved['estimate_regional_price_version_id'] ?? 0) !== $versionId
            || ($resolved['status'] ?? null) !== 'active'
        ) {
            throw new \InvalidArgumentException('Selected regional price version is unavailable.');
        }
        $regionalContext = is_array($input['regional_context'] ?? null)
            ? $input['regional_context']
            : [];

        return [
            ...$input,
            'estimate_regional_price_version_id' => $resolved['estimate_regional_price_version_id'] ?? null,
            'region_id' => $resolved['region_id'] ?? null,
            'region' => $resolved['region_name'] ?? null,
            'price_zone_id' => $resolved['price_zone_id'] ?? null,
            'period_id' => $resolved['period_id'] ?? null,
            'regional_context' => [
                ...$regionalContext,
                ...$resolved,
            ],
        ];
    }
}
