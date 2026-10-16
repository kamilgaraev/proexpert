<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class NormativePriceEvidenceProjector
{
    public function resources(array $snapshot): ?array
    {
        $coefficients = $snapshot['coefficients'] ?? [];
        $resources = $coefficients['resource_evidence'] ?? null;
        if (! is_array($resources) || $resources === []) {
            return null;
        }
        if (! in_array($coefficients['pricing_formula_version'] ?? null, ['semantic_project_resource:v9', 'supplementary_project_material:v5'], true)) {
            return $resources;
        }
        if (($coefficients['rounding_policy'] ?? null) !== 'resource_half_up_2') {
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
                foreach ($entries as $entry) {
                    if (! is_array($entry) || ! is_string($entry['final_amount'] ?? null)
                        || ! ctype_digit((string) ($entry['resource_price_id'] ?? '')) || (int) $entry['resource_price_id'] <= 0) {
                        return null;
                    }
                    $amount = BigDecimal::of($entry['final_amount'])->toScale(2, RoundingMode::Unnecessary);
                    if ($amount->isLessThan(0)) {
                        return null;
                    }
                    $sum = $sum->plus($amount);
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
                    $base = in_array($source, ['fsnb_base', 'fsnb_2022', 'fsbc'], true);
                    $result[] = [...$entry,
                        'source_reference' => 'estimate_resource_prices:'.$entry['resource_price_id'],
                        'source_type' => $source === 'fsnb_base' ? 'fsnb_2022' : $source,
                        'final_amount' => (string) $amount,
                        'coefficients' => ['price_kind' => $base ? 'base_catalog' : $source],
                    ];
                }
            }
            if (! $sum->isEqualTo(BigDecimal::of($snapshot['final_amount'] ?? '-1'))) {
                return null;
            }
        } catch (\Brick\Math\Exception\MathException) {
            return null;
        }

        return $result;
    }
}
