<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

final class GeneratedEstimateItemMetadataFactory
{
    /** @param array<string, mixed> $workItem @return array<string, mixed> */
    public function make(array $workItem): array
    {
        return [
            'source_refs' => $workItem['source_refs'] ?? [],
            'confidence' => $workItem['confidence'] ?? null,
            'validation_flags' => $workItem['validation_flags'] ?? [],
            'normative_dataset' => $workItem['normative_dataset'] ?? null,
            'normative_match' => $workItem['normative_match'] ?? null,
            'normative_candidates' => $workItem['normative_candidates'] ?? [],
            'price_source' => $workItem['price_source'] ?? null,
            'material_assumption' => is_array($workItem['metadata']['material_assumption'] ?? null)
                ? $workItem['metadata']['material_assumption']
                : null,
            'quantity_evidence' => is_array($workItem['quantity_evidence'] ?? null)
                ? $workItem['quantity_evidence']
                : null,
            'quantity_calculation' => $this->quantityCalculation($workItem),
            'applied_price' => $this->appliedPrice($workItem),
        ];
    }

    /** @param array<string, mixed> $workItem @return array<string, mixed> */
    public function quantityCalculation(array $workItem): array
    {
        $evidence = is_array($workItem['quantity_evidence'] ?? null)
            ? $workItem['quantity_evidence']
            : [];
        $inputs = is_array($evidence['formula_inputs'] ?? null)
            ? $evidence['formula_inputs']
            : [];

        return [
            'description' => $workItem['quantity_basis'] ?? null,
            'formula' => $workItem['quantity_formula'] ?? null,
            'formula_key' => $evidence['formula_key'] ?? null,
            'formula_version' => $evidence['formula_version'] ?? null,
            'formula_inputs' => $inputs,
            'scenario_id' => is_array($inputs['scenario'] ?? null)
                ? ($inputs['scenario']['id'] ?? $inputs['scenario_id'] ?? null)
                : ($inputs['scenario_id'] ?? null),
            'evidence_ids' => is_array($evidence['evidence_ids'] ?? null)
                ? array_values($evidence['evidence_ids'])
                : [],
            'assumptions' => is_array($evidence['assumptions'] ?? null)
                ? array_values($evidence['assumptions'])
                : [],
            'source' => $evidence['source'] ?? null,
            'model_version' => $evidence['model_version'] ?? null,
        ];
    }

    /** @param array<string, mixed> $workItem @return array<string, mixed> */
    public function appliedPrice(array $workItem): array
    {
        return [
            'source' => $workItem['price_source'] ?? null,
            'snapshot' => is_array($workItem['price_snapshot'] ?? null)
                ? $workItem['price_snapshot']
                : null,
        ];
    }
}
