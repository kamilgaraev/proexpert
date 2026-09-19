<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Illuminate\Support\Str;

final class ContractFormulaExpression
{
    public function references(array $expression): array
    {
        $references = [];
        $count = 0;
        $this->node($expression, 0, $count, $references);

        return array_keys($references);
    }

    private function node(array $node, int $depth, int &$count, array &$references): void
    {
        if ($depth > 32 || ++$count > 256) {
            $this->invalid();
        }
        $kind = $node['kind'] ?? null;
        $keys = match ($kind) {
            'literal' => ['kind', 'type', 'value'],
            'reference' => ['kind', 'variable_id'],
            'sum' => ['kind', 'variable_id', 'column_id'],
            'operation' => ['kind', 'operator', 'args', 'scale'],
            default => [],
        };
        if ($keys === [] || array_diff(array_keys($node), $keys) !== []) {
            $this->invalid();
        }
        if ($kind === 'literal') {
            if (!in_array($node['type'] ?? null, ['text', 'number', 'money', 'date', 'boolean'], true)
                || !array_key_exists('value', $node) || $node['value'] === null) {
                $this->invalid();
            }
            (new ContractVariableValueValidator)->validate(['type' => $node['type']], $node['value']);

            return;
        }
        if ($kind === 'reference' || $kind === 'sum') {
            if (!is_string($node['variable_id'] ?? null) || !Str::isUuid($node['variable_id'])) {
                $this->invalid();
            }
            $references[$node['variable_id']] = true;
            if ($kind === 'sum' && !(new ContractVariableDefinitionValidator)->identifier($node['column_id'] ?? null)) {
                $this->invalid();
            }

            return;
        }
        $operator = $node['operator'] ?? null;
        $arity = match ($operator) {
            'add', 'subtract', 'multiply', 'divide', 'equal', 'not_equal', 'greater', 'greater_equal', 'less', 'less_equal', 'and', 'or', 'add_days', 'days_between' => 2,
            'not', 'round' => 1,
            'if' => 3,
            default => 0,
        };
        if ($arity === 0 || !is_array($node['args'] ?? null) || !array_is_list($node['args']) || count($node['args']) !== $arity) {
            $this->invalid();
        }
        if (in_array($operator, ['round', 'divide'], true)) {
            if (!is_int($node['scale'] ?? null) || $node['scale'] < 0 || $node['scale'] > 12) {
                $this->invalid();
            }
        } elseif (array_key_exists('scale', $node)) {
            $this->invalid();
        }
        foreach ($node['args'] as $argument) {
            if (!is_array($argument)) {
                $this->invalid();
            }
            $this->node($argument, $depth + 1, $count, $references);
        }
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.formula_invalid', 422);
    }
}
