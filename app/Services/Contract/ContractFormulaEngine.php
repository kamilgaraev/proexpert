<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Closure;

final class ContractFormulaEngine
{
    public function __construct(private readonly ContractVariableValueValidator $values = new ContractVariableValueValidator) {}

    public function validate(array $definitions): array
    {
        $order = (new ContractFormulaDependencies)->order($definitions);
        if (strlen(json_encode($definitions, JSON_THROW_ON_ERROR)) > 10 * 1024 * 1024) {
            throw new ContractBuilderException('contracts.formula_limit', 422);
        }
        $validator = new ContractVariableDefinitionValidator;
        foreach ($definitions as $id => $definition) {
            try {
                $validator->validate($definition);
            } catch (ContractBuilderException $exception) {
                throw $exception->atField('values.'.$id);
            }
        }
        $types = new ContractFormulaTypeChecker;
        foreach ($order as $id) {
            $definition = $definitions[$id];
            if (($definition['source']['kind'] ?? 'manual') === 'formula') {
                try {
                    $types->validate($definition['source']['expression'], $definitions, $definition);
                } catch (ContractBuilderException $exception) {
                    throw $exception->atField('values.'.$id);
                }
            }
        }

        return $order;
    }

    public function calculate(array $definitions, array $input, ?Closure $entityAccessible = null, ?Closure $sourceValue = null): array
    {
        $order = $this->validate($definitions);
        if (array_diff(array_keys($input), array_keys($definitions)) !== []) {
            throw new ContractBuilderException('contracts.builder_input_invalid', 422);
        }
        $values = [];
        foreach ($definitions as $id => $definition) {
            if (in_array($definition['source']['kind'] ?? 'manual', ['formula', 'entity_field'], true)) {
                if (array_key_exists($id, $input)) {
                    throw (new ContractBuilderException('contracts.formula_readonly', 422))->atField('values.'.$id);
                }
            } else {
                try {
                    $values[$id] = $this->values->validate($definition, $input[$id] ?? null, $entityAccessible);
                } catch (ContractBuilderException $exception) {
                    throw $exception->atField('values.'.$id);
                }
            }
        }
        $budget = 100000;
        $evaluator = new ContractFormulaEvaluator;
        foreach ($order as $id) {
            $definition = $definitions[$id];
            if (($definition['source']['kind'] ?? 'manual') === 'entity_field') {
                if ($sourceValue === null) {
                    throw (new ContractBuilderException('contracts.variable_value_invalid', 422))->atField('values.'.$id);
                }
                try {
                    $values[$id] = $this->values->validate($definition, $sourceValue($id), $entityAccessible);
                } catch (ContractBuilderException $exception) {
                    throw $exception->atField('values.'.$id);
                }
                continue;
            }
            if (($definition['source']['kind'] ?? 'manual') !== 'formula') {
                continue;
            }
            try {
                $result = $evaluator->evaluate($definition['source']['expression'], static function (string $reference) use ($definitions, &$values): array {
                    return ['definition' => $definitions[$reference], 'value' => $values[$reference] ?? null];
                }, $budget);
                $values[$id] = $this->values->validate($definition, $result['value'], $entityAccessible);
            } catch (ContractBuilderException $exception) {
                throw $exception->atField('values.'.$id);
            }
        }

        return $values;
    }
}
