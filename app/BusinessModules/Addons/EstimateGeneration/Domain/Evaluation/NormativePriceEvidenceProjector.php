<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class NormativePriceEvidenceProjector
{
    public function resources(array $snapshot, ?string $quantity = null): ?array
    {
        $coefficients = $snapshot['coefficients'] ?? [];
        $resources = $coefficients['resource_evidence'] ?? null;
        if (! is_array($resources) || $resources === []) {
            return null;
        }
        $version = $coefficients['pricing_formula_version'] ?? null;
        $historical = in_array($version, ['project_resource:v3', 'semantic_project_resource:v8', 'supplementary_project_material:v4'], true);
        if (! $historical && ! in_array($version, ['semantic_project_resource:v9', 'supplementary_project_material:v5'], true)) {
            return $resources;
        }
        if (! $historical && ($coefficients['rounding_policy'] ?? null) !== 'resource_half_up_2') {
            return null;
        }
        $traces = [];
        foreach ($coefficients['provenance']['resources'] ?? [] as $trace) {
            if (! is_array($trace) || ! isset($trace['norm_resource_id']) || isset($traces[$trace['norm_resource_id']])) {
                return null;
            }
            $traces[$trace['norm_resource_id']] = $trace;
        }
        $result = [];
        try {
            $sum = BigDecimal::of($coefficients['work_cost'] ?? '0')->toScale(2, RoundingMode::Unnecessary);
            foreach (['resource_evidence', 'project_material_evidence'] as $group) {
                $entries = $coefficients[$group] ?? [];
                if (! is_array($entries)) {
                    return null;
                }
                $groupRows = [];
                $exactTotal = BigDecimal::zero();
                foreach ($entries as $entry) {
                    if (! is_array($entry) || (! $historical && ! is_string($entry['final_amount'] ?? null))
                        || ! ctype_digit((string) ($entry['resource_price_id'] ?? '')) || (int) $entry['resource_price_id'] <= 0) {
                        return null;
                    }
                    $exact = $historical ? $this->historicalAmount($entry, $group, $quantity) : null;
                    $amount = $historical ? $exact->toScale(2, RoundingMode::HalfUp)
                        : BigDecimal::of($entry['final_amount'])->toScale(2, RoundingMode::Unnecessary);
                    if ($amount->isLessThan(0)) {
                        return null;
                    }
                    $exactTotal = $exactTotal->plus($exact ?? $amount);
                    $source = $entry['price_source'] ?? null;
                    if ($group === 'resource_evidence') {
                        $trace = $traces[$entry['norm_resource_id'] ?? ''] ?? null;
                        if (! is_array($trace) || (int) ($trace['price_id'] ?? 0) !== (int) $entry['resource_price_id']) {
                            return null;
                        }
                        $source = (int) ($trace['regional_price_version_id'] ?? 0) === (int) ($snapshot['version_id'] ?? 0)
                            && (int) ($trace['regional_price_version_id'] ?? 0) > 0
                            ? 'regional_catalog' : ($trace['price_dataset']['source_type'] ?? 'unknown');
                    }
                    $base = in_array($source, ['fsnb_base', 'fsbc_base', 'fsnb_2022', 'fsbc'], true);
                    $source = match ($source) {
                        'fsnb_base' => 'fsnb_2022', 'fsbc_base' => 'fsbc', default => $source
                    };
                    $groupRows[] = [...$entry,
                        'source_reference' => 'estimate_resource_prices:'.$entry['resource_price_id'],
                        'source_type' => $source,
                        'final_amount' => (string) $amount,
                        'coefficients' => ['price_kind' => $base ? 'base_catalog' : $source,
                            ...$historical ? ['exact_amount' => (string) $exact, 'rounding_policy' => 'aggregate_half_up_2'] : []],
                    ];
                }
                $groupTotal = $exactTotal->toScale(2, RoundingMode::HalfUp);
                if ($group === 'project_material_evidence' && in_array($version, ['supplementary_project_material:v4', 'supplementary_project_material:v5'], true)
                    && ($entries === [] || ! $groupTotal->isEqualTo(BigDecimal::of($coefficients['project_material_amount'] ?? '-1')))) {
                    return null;
                }
                $sum = $sum->plus($groupTotal);
                array_push($result, ...($historical ? $this->allocateHistoricalRounding($groupRows, $groupTotal) : $groupRows));
            }
            if (! $sum->isEqualTo(BigDecimal::of($snapshot['final_amount'] ?? '-1'))) {
                return null;
            }
        } catch (\Brick\Math\Exception\MathException|\InvalidArgumentException) {
            return null;
        }

        return $result;
    }

    private function historicalAmount(array $entry, string $group, ?string $quantity): BigDecimal
    {
        $amount = BigDecimal::of($quantity ?? '-1');
        $factors = $group === 'resource_evidence'
            ? ['norm_quantity', 'work_to_norm_factor', 'conversion_factor', 'base_price']
            : ['quantity_per_work_unit', 'price_factor', 'base_price'];
        if ($amount->isLessThanOrEqualTo(0)) {
            throw new \InvalidArgumentException('historical_price_quantity_missing');
        }
        foreach ($factors as $factor) {
            $value = BigDecimal::of($entry[$factor] ?? '-1');
            if ($value->isLessThanOrEqualTo(0)) {
                throw new \InvalidArgumentException('historical_price_factor_missing');
            }
            $amount = $amount->multipliedBy($value);
        }

        return $amount;
    }

    private function allocateHistoricalRounding(array $rows, BigDecimal $total): array
    {
        $rounded = BigDecimal::zero();
        foreach ($rows as $row) {
            $rounded = $rounded->plus($row['final_amount']);
        }
        $difference = $total->minus($rounded);
        for ($index = count($rows) - 1; $index >= 0 && ! $difference->isZero(); $index--) {
            $amount = BigDecimal::of($rows[$index]['final_amount']);
            $adjustment = $difference->isLessThan($amount->negated()) ? $amount->negated() : $difference;
            $rows[$index]['final_amount'] = (string) $amount->plus($adjustment)->toScale(2);
            $rows[$index]['coefficients']['rounding_adjustment'] = (string) $adjustment->toScale(2);
            $difference = $difference->minus($adjustment);
        }
        if (! $difference->isZero()) {
            throw new \InvalidArgumentException('historical_price_allocation_invalid');
        }

        return $rows;
    }
}
