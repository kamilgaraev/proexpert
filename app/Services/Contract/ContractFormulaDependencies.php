<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Illuminate\Support\Str;

final class ContractFormulaDependencies
{
    public function order(array $definitions): array
    {
        if (count($definitions) > 500) {
            throw new ContractBuilderException('contracts.formula_limit', 422);
        }
        $incoming = [];
        $dependents = [];
        $expressions = new ContractFormulaExpression;
        foreach ($definitions as $id => $definition) {
            if (!is_string($id) || !Str::isUuid($id) || !is_array($definition)) {
                throw new ContractBuilderException('contracts.formula_invalid', 422);
            }
            $source = $definition['source'] ?? ['kind' => 'manual'];
            if (!is_array($source) || !in_array($source['kind'] ?? null, ['manual', 'formula', 'entity_field', 'contract_context'], true)) {
                throw new ContractBuilderException('contracts.formula_invalid', 422);
            }
            $references = [];
            if ($source['kind'] === 'formula') {
                if (!is_array($source['expression'] ?? null)) {
                    throw new ContractBuilderException('contracts.formula_invalid', 422);
                }
                $references = $expressions->references($source['expression']);
            } elseif ($source['kind'] === 'entity_field') {
                $reference = $source['variable_id'] ?? null;
                if (!is_string($reference) || !isset($definitions[$reference])
                    || ($definitions[$reference]['type'] ?? null) !== 'entity'
                    || ($definitions[$reference]['entity_type'] ?? null) !== ($source['entity_type'] ?? null)
                    || ($definitions[$reference]['source']['kind'] ?? 'manual') !== 'manual') {
                    throw new ContractBuilderException('contracts.formula_missing', 422);
                }
                $references = [$reference];
            }
            $incoming[$id] = count($references);
            foreach ($references as $reference) {
                if (!array_key_exists($reference, $definitions)) {
                    throw new ContractBuilderException('contracts.formula_missing', 422);
                }
                $dependents[$reference][] = $id;
            }
        }
        $ready = [];
        foreach ($incoming as $id => $count) {
            if ($count === 0) {
                $ready[] = $id;
            }
        }
        for ($position = 0; $position < count($ready); $position++) {
            foreach ($dependents[$ready[$position]] ?? [] as $dependent) {
                if (--$incoming[$dependent] === 0) {
                    $ready[] = $dependent;
                }
            }
        }
        if (count($ready) !== count($definitions)) {
            throw new ContractBuilderException('contracts.formula_cycle', 422);
        }

        return $ready;
    }
}
