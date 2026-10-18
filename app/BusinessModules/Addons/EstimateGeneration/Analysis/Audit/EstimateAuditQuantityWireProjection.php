<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;

final class EstimateAuditQuantityWireProjection
{
    public static function project(array $quantities): array
    {
        foreach ($quantities as $quantityIndex => &$quantity) {
            $basis = $quantity['quantity_basis'] ?? null;
            $inputs = is_array($basis) ? ($basis['formula_inputs'] ?? null) : null;
            if (! is_array($basis) || ! is_array($inputs) || ! is_array($inputs['operands'] ?? null)
                || ! array_is_list($inputs['operands'])) {
                continue;
            }
            $root = 'audit_input.derived_quantities['.$quantityIndex.'].quantity_basis';
            $known = [];
            foreach (['formula_identity' => [$basis, 'formula_key', $root],
                'formula_version' => [$basis, 'formula_version', $root],
                'rounding_policy' => [$inputs, 'rounding_policy', $root.'.formula_inputs'],
                'snapshot_identity' => [$inputs, 'snapshot_identity', $root.'.formula_inputs'],
                'technology_decision_id' => [$inputs, 'technology_decision_id', $root.'.formula_inputs']] as $field => [$source, $key, $path]) {
                if (array_key_exists($key, $source)) {
                    $encoded = CanonicalPipelineJson::encode([$source[$key]]);
                    $known[$field][hash('sha256', $encoded)] = ['encoded' => $encoded, 'reference' => $path.'.'.$key];
                }
            }
            foreach ($inputs['operands'] as $operandIndex => &$operand) {
                if (! is_array($operand)) {
                    continue;
                }
                foreach (['formula_identity', 'formula_version', 'rounding_policy', 'snapshot_identity',
                    'technology_decision_id', 'geometry_relations', 'quantity_binding'] as $field) {
                    if (! array_key_exists($field, $operand) || array_key_exists($field.'_ref', $operand)) {
                        continue;
                    }
                    $encoded = CanonicalPipelineJson::encode([$operand[$field]]);
                    $hash = hash('sha256', $encoded);
                    $prior = $known[$field][$hash] ?? null;
                    if ($prior !== null && $prior['encoded'] === $encoded
                        && strlen(CanonicalPipelineJson::encode([$field.'_ref' => $prior['reference']]))
                        < strlen(CanonicalPipelineJson::encode([$field => $operand[$field]]))) {
                        unset($operand[$field]);
                        $operand[$field.'_ref'] = $prior['reference'];
                    } else {
                        $known[$field][$hash] ??= ['encoded' => $encoded,
                            'reference' => $root.'.formula_inputs.operands['.$operandIndex.'].'.$field];
                    }
                }
            }
            unset($operand);
            $quantity['quantity_basis']['formula_inputs']['operands'] = $inputs['operands'];
        }
        unset($quantity);

        return $quantities;
    }
}
