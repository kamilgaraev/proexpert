<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

/** A bounded exact semantic match; ambiguity is retained for explicit user selection. */
final class AutomaticCommercialPriceMatcher
{
    public function name(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 500) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return $normalized === null || preg_match('/[\x00-\x1F\x7F]/u', $normalized) !== 0 ? null : mb_strtolower($normalized, 'UTF-8');
    }

    public function basis(array $item): array
    {
        return ['work_item_key' => $item['key'] ?? null, 'name' => $this->name($item['name'] ?? null), 'unit' => $item['unit'] ?? null,
            'item_type' => $item['metadata']['ordinary_item_type'] ?? 'work', 'technology_package_id' => $item['metadata']['technology_package_id'] ?? null];
    }

    public function matches(array $price, array $item): bool
    {
        $basis = $this->basis($item);

        return $basis['name'] !== null && $this->name($price['name'] ?? null) === $basis['name']
            && is_string($basis['work_item_key']) && is_string($basis['unit']) && ($price['unit'] ?? null) === $basis['unit']
            && ($price['item_type'] ?? null) === $basis['item_type']
            && ($price['technology_package_id'] ?? null) === $basis['technology_package_id']
            && ($price['verified'] ?? null) === true && ($price['applicable'] ?? null) === true
            && ($price['stale'] ?? null) === false && ($price['conditions_missing'] ?? []) === [];
    }

    public function select(array $prices, array $item): ?array
    {
        $matches = array_values(array_filter($prices, fn (array $price): bool => $this->matches($price, $item)));
        if (count($matches) !== 1) {
            return null;
        }

        return [...$matches[0], 'selection_mode' => 'automatic_exact_name', 'automatic_selection_basis' => $this->basis($item)];
    }

    public function frozenBasisMatches(array $price, array $item): bool
    {
        return ($price['selection_mode'] ?? null) === 'automatic_exact_name' && is_array($price['automatic_selection_basis'] ?? null)
            && CanonicalPipelineJson::encode($price['automatic_selection_basis']) === CanonicalPipelineJson::encode($this->basis($item));
    }
}
