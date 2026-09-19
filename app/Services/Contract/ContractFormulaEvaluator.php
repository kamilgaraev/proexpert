<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\DivisionByZeroException;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Closure;
use DateTimeImmutable;
use DateTimeZone;

final class ContractFormulaEvaluator
{
    public function evaluate(array $expression, Closure $resolve, ?int &$budget = null): array
    {
        (new ContractFormulaExpression)->references($expression);
        $budget ??= 100000;
        try {
            return $this->node($expression, $resolve, $budget);
        } catch (DivisionByZeroException) {
            $this->invalid('division_zero');
        } catch (MathException) {
            $this->invalid('number');
        }
    }

    private function node(array $node, Closure $resolve, int &$budget): array
    {
        if (--$budget < 0) {
            $this->invalid('limit');
        }
        if ($node['kind'] === 'literal') {
            return ['type' => $node['type'], 'value' => $node['value']];
        }
        if ($node['kind'] === 'reference') {
            return $this->reference($node['variable_id'], $resolve);
        }
        if ($node['kind'] === 'sum') {
            $table = $resolve($node['variable_id']);
            if (!is_array($table) || ($table['definition']['type'] ?? null) !== 'table' || !is_array($table['value'] ?? null)) {
                $this->invalid('type');
            }
            $columns = array_column($table['definition']['columns'], 'definition', 'id');
            $column = $columns[$node['column_id']] ?? null;
            if ($column === null || !in_array($column['type'], ['number', 'percentage', 'money'], true)) {
                $this->invalid('type');
            }
            $total = null;
            foreach ($table['value'] as $row) {
                if (--$budget < 0) {
                    $this->invalid('limit');
                }
                $value = $this->typed($column, $row['values'][$node['column_id']] ?? null);
                $total = $total === null ? $value : $this->arithmetic('add', $total, $value, 0);
            }
            if ($total !== null) {
                return $total;
            }
            if ($column['type'] === 'money') {
                $currency = $column['constraints']['currency'] ?? null;
                if ($currency === null) {
                    $this->invalid('currency');
                }

                return ['type' => 'money', 'value' => ['amount' => '0', 'currency' => $currency]];
            }

            return ['type' => 'number', 'value' => '0'];
        }
        $operator = $node['operator'];
        $first = $this->node($node['args'][0], $resolve, $budget);
        if ($operator === 'if') {
            $this->requireType($first, 'boolean');

            return $this->node($node['args'][$first['value'] ? 1 : 2], $resolve, $budget);
        }
        if ($operator === 'not') {
            $this->requireType($first, 'boolean');

            return ['type' => 'boolean', 'value' => !$first['value']];
        }
        if ($operator === 'round') {
            $this->numeric($first);

            return $this->numberResult($first['type'], $this->decimal($first)->toScale($node['scale'], RoundingMode::HalfUp), $first['value']['currency'] ?? null);
        }
        if ($operator === 'and' || $operator === 'or') {
            $this->requireType($first, 'boolean');
            if (($operator === 'and' && !$first['value']) || ($operator === 'or' && $first['value'])) {
                return $first;
            }
            $second = $this->node($node['args'][1], $resolve, $budget);
            $this->requireType($second, 'boolean');

            return $second;
        }
        $second = $this->node($node['args'][1], $resolve, $budget);
        if (in_array($operator, ['add', 'subtract', 'multiply', 'divide'], true)) {
            return $this->arithmetic($operator, $first, $second, $node['scale'] ?? 0);
        }
        if ($operator === 'add_days') {
            $this->requireType($first, 'date');
            $this->requireType($second, 'number');
            $days = $this->decimal($second)->toScale(0)->toInt();
            if (abs($days) > 3650000) {
                $this->invalid('limit');
            }
            $date = (new DateTimeImmutable($first['value'], new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
            if (!(new ContractVariableDefinitionValidator)->date($date) || strlen($date) !== 10) {
                $this->invalid('type');
            }

            return ['type' => 'date', 'value' => $date];
        }
        if ($operator === 'days_between') {
            $this->requireType($first, 'date');
            $this->requireType($second, 'date');
            $zone = new DateTimeZone('UTC');

            return ['type' => 'number', 'value' => (new DateTimeImmutable($first['value'], $zone))->diff(new DateTimeImmutable($second['value'], $zone))->format('%r%a')];
        }
        if ($first['type'] !== $second['type'] || !in_array($first['type'], ['number', 'money', 'text', 'date', 'boolean'], true)) {
            $this->invalid('type');
        }
        if ($first['type'] === 'money' && $first['value']['currency'] !== $second['value']['currency']) {
            $this->invalid('currency');
        }
        $comparison = in_array($first['type'], ['money', 'number'], true)
            ? $this->decimal($first)->compareTo($this->decimal($second))
            : ($first['type'] === 'boolean' ? ($first['value'] <=> $second['value']) : strcmp($first['value'], $second['value']));
        $value = match ($operator) {
            'equal' => $comparison === 0, 'not_equal' => $comparison !== 0,
            'greater' => $comparison > 0, 'greater_equal' => $comparison >= 0,
            'less' => $comparison < 0, 'less_equal' => $comparison <= 0,
            default => throw new ContractBuilderException('contracts.formula_invalid', 422),
        };

        return ['type' => 'boolean', 'value' => $value];
    }

    private function reference(string $id, Closure $resolve): array
    {
        $field = $resolve($id);
        if (!is_array($field) || !is_array($field['definition'] ?? null)) {
            $this->invalid('missing');
        }

        return $this->typed($field['definition'], $field['value'] ?? null);
    }

    private function typed(array $definition, mixed $value): array
    {
        if ($value === null) {
            $this->invalid('missing');
        }
        $value = (new ContractVariableValueValidator)->validate($definition, $value);

        return ['type' => $definition['type'] === 'percentage' ? 'number' : $definition['type'], 'value' => $value];
    }

    private function arithmetic(string $operator, array $left, array $right, int $scale): array
    {
        $this->numeric($left);
        $this->numeric($right);
        $lm = $left['type'] === 'money';
        $rm = $right['type'] === 'money';
        if ($lm && $rm && $left['value']['currency'] !== $right['value']['currency']) {
            $this->invalid('currency');
        }
        if ((in_array($operator, ['add', 'subtract'], true) && $lm !== $rm)
            || ($operator === 'multiply' && $lm && $rm) || ($operator === 'divide' && !$lm && $rm)) {
            $this->invalid('type');
        }
        $type = ($lm || $rm) && !($operator === 'divide' && $lm && $rm) ? 'money' : 'number';
        $a = $this->decimal($left);
        $b = $this->decimal($right);
        $value = match ($operator) {
            'add' => $a->plus($b), 'subtract' => $a->minus($b), 'multiply' => $a->multipliedBy($b),
            'divide' => $a->dividedBy($b, $scale, RoundingMode::HalfUp),
        };

        return $this->numberResult($type, $value, $lm ? $left['value']['currency'] : ($rm ? $right['value']['currency'] : null));
    }

    private function numberResult(string $type, BigDecimal $value, ?string $currency): array
    {
        $text = (string) $value->stripTrailingZeros();
        if (strlen($text) > 128) {
            $this->invalid('limit');
        }

        return ['type' => $type, 'value' => $type === 'money' ? ['amount' => $text, 'currency' => $currency] : $text];
    }

    private function decimal(array $value): BigDecimal
    {
        return BigDecimal::of((string) ($value['type'] === 'money' ? $value['value']['amount'] : $value['value']));
    }

    private function numeric(array $value): void
    {
        if (!in_array($value['type'], ['number', 'money'], true)) {
            $this->invalid('type');
        }
    }

    private function requireType(array $value, string $type): void
    {
        if ($value['type'] !== $type) {
            $this->invalid('type');
        }
    }

    private function invalid(string $reason): never
    {
        throw new ContractBuilderException('contracts.formula_'.$reason, 422);
    }
}
