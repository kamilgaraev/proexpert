<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractFormulaTypeChecker
{
    public function validate(array $expression, array $definitions, array $target): void
    {
        (new ContractFormulaExpression)->references($expression);
        $actual = $this->node($expression, $definitions);
        $this->compatible($actual, $this->descriptor($target));
    }

    private function node(array $node, array $definitions): array
    {
        if ($node['kind'] === 'literal') {
            return ['type' => $node['type'], 'currency' => $node['type'] === 'money' ? $node['value']['currency'] : null];
        }
        if ($node['kind'] === 'reference' || $node['kind'] === 'sum') {
            $definition = $definitions[$node['variable_id']] ?? null;
            if (!is_array($definition)) {
                $this->invalid('missing');
            }
            if ($node['kind'] === 'sum') {
                if (($definition['type'] ?? null) !== 'table') {
                    $this->invalid();
                }
                $definition = array_column($definition['columns'], 'definition', 'id')[$node['column_id']] ?? null;
                if (!is_array($definition)) {
                    $this->invalid();
                }
                $result = $this->descriptor($definition);
                $this->numeric($result);

                return $result;
            }

            return $this->descriptor($definition);
        }
        $arguments = array_map(fn (array $argument): array => $this->node($argument, $definitions), $node['args']);
        $left = $arguments[0];
        $right = $arguments[1] ?? null;
        $operator = $node['operator'];
        if ($operator === 'if') {
            $this->require($left, 'boolean');
            $this->compatible($right, $arguments[2]);

            return ['type' => $right['type'], 'currency' => $right['currency'] === $arguments[2]['currency'] ? $right['currency'] : null];
        }
        if (in_array($operator, ['not', 'and', 'or'], true)) {
            foreach ($arguments as $argument) {
                $this->require($argument, 'boolean');
            }

            return ['type' => 'boolean', 'currency' => null];
        }
        if ($operator === 'round') {
            $this->numeric($left);

            return $left;
        }
        if ($operator === 'add_days' || $operator === 'days_between') {
            $this->require($left, 'date');
            $this->require($right, $operator === 'add_days' ? 'number' : 'date');

            return ['type' => $operator === 'add_days' ? 'date' : 'number', 'currency' => null];
        }
        if (in_array($operator, ['add', 'subtract', 'multiply', 'divide'], true)) {
            $this->numeric($left);
            $this->numeric($right);
            $lm = $left['type'] === 'money';
            $rm = $right['type'] === 'money';
            if ($lm && $rm) {
                $this->compatible($left, $right);
            }
            if ((in_array($operator, ['add', 'subtract'], true) && $lm !== $rm)
                || ($operator === 'multiply' && $lm && $rm) || ($operator === 'divide' && !$lm && $rm)) {
                $this->invalid();
            }
            $money = ($lm || $rm) && !($operator === 'divide' && $lm && $rm);

            return ['type' => $money ? 'money' : 'number', 'currency' => $money ? ($left['currency'] ?? $right['currency']) : null];
        }
        $this->compatible($left, $right);

        return ['type' => 'boolean', 'currency' => null];
    }

    private function descriptor(array $definition): array
    {
        $type = $definition['type'] ?? null;
        if (!in_array($type, ['number', 'percentage', 'money', 'text', 'date', 'boolean'], true)) {
            $this->invalid();
        }

        return ['type' => $type === 'percentage' ? 'number' : $type, 'currency' => $type === 'money' ? ($definition['constraints']['currency'] ?? null) : null];
    }

    private function compatible(array $left, array $right): void
    {
        if ($left['type'] !== $right['type']) {
            $this->invalid();
        }
        if ($left['type'] === 'money' && $left['currency'] !== null && $right['currency'] !== null && $left['currency'] !== $right['currency']) {
            $this->invalid('currency');
        }
    }

    private function numeric(array $type): void
    {
        if (!in_array($type['type'], ['number', 'money'], true)) {
            $this->invalid();
        }
    }

    private function require(array $actual, string $expected): void
    {
        if ($actual['type'] !== $expected) {
            $this->invalid();
        }
    }

    private function invalid(string $reason = 'type'): never
    {
        throw new ContractBuilderException('contracts.formula_'.$reason, 422);
    }
}
