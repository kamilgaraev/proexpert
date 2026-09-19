<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use Closure;

final class ContractVariableValueValidator
{
    public function __construct(private readonly ContractVariableDefinitionValidator $definitions = new ContractVariableDefinitionValidator) {}

    public function validate(array $definition, mixed $value, ?Closure $entityAccessible = null): mixed
    {
        $this->definitions->validate($definition);

        return $this->value($definition, $value, $entityAccessible, 'value');
    }

    private function value(array $definition, mixed $value, ?Closure $entityAccessible, string $path): mixed
    {
        if ($value === null) {
            if ($definition['required'] ?? false) {
                $this->invalid($path);
            }

            return null;
        }
        $constraints = $definition['constraints'] ?? [];
        $type = $definition['type'];
        if ($type === 'text') {
            if (!is_string($value) || mb_strlen($value) > ($constraints['max_length'] ?? 100000)
                || (($definition['required'] ?? false) && trim($value) === '')) {
                $this->invalid($path);
            }
        } elseif (in_array($type, ['number', 'percentage'], true)) {
            $this->number($value, $constraints, $path);
        } elseif ($type === 'money') {
            if (!is_array($value) || array_diff(array_keys($value), ['amount', 'currency']) !== []
                || !is_string($value['currency'] ?? null) || preg_match('/^[A-Z]{3}$/D', $value['currency']) !== 1
                || (isset($constraints['currency']) && $constraints['currency'] !== $value['currency'])) {
                $this->invalid($path);
            }
            $this->number($value['amount'] ?? null, $constraints, $path.'.amount');
        } elseif ($type === 'date') {
            if (!$this->definitions->date($value)
                || (isset($constraints['min']) && strcmp($value, $constraints['min']) < 0)
                || (isset($constraints['max']) && strcmp($value, $constraints['max']) > 0)) {
                $this->invalid($path);
            }
        } elseif ($type === 'boolean') {
            if (!is_bool($value)) {
                $this->invalid($path);
            }
        } elseif ($type === 'choice') {
            if (!is_string($value) || !in_array($value, array_column($definition['options'], 'id'), true)) {
                $this->invalid($path);
            }
        } elseif ($type === 'entity') {
            if (!is_array($value) || array_diff(array_keys($value), ['type', 'id']) !== []
                || ($value['type'] ?? null) !== $definition['entity_type'] || !is_int($value['id'] ?? null) || $value['id'] < 1
                || $entityAccessible === null || $entityAccessible($value['type'], $value['id']) !== true) {
                $this->invalid($path);
            }
        } elseif ($type === 'table') {
            if (!is_array($value) || !array_is_list($value)
                || count($value) < ($constraints['min_rows'] ?? 0) || count($value) > ($constraints['max_rows'] ?? 10000)) {
                $this->invalid($path);
            }
            $ids = [];
            $columns = array_column($definition['columns'], 'definition', 'id');
            foreach ($value as $index => $row) {
                if (!is_array($row) || array_diff(array_keys($row), ['id', 'values']) !== []
                    || !$this->definitions->identifier($row['id'] ?? null) || isset($ids[$row['id']])
                    || !is_array($row['values'] ?? null) || array_diff(array_keys($row['values']), array_keys($columns)) !== []) {
                    $this->invalid($path.'.'.$index);
                }
                $ids[$row['id']] = true;
                foreach ($columns as $id => $column) {
                    $value[$index]['values'][$id] = $this->value($column, $row['values'][$id] ?? null, $entityAccessible, $path.'.'.$index.'.'.$id);
                }
            }
        }

        return $value;
    }

    private function number(mixed $value, array $constraints, string $path): void
    {
        if (!$this->definitions->decimal($value)) {
            $this->invalid($path);
        }
        $text = (string) $value;
        $fraction = explode('.', $text, 2)[1] ?? '';
        if (strlen($fraction) > ($constraints['scale'] ?? 12)
            || (isset($constraints['min']) && $this->definitions->compareDecimals($text, (string) $constraints['min']) < 0)
            || (isset($constraints['max']) && $this->definitions->compareDecimals($text, (string) $constraints['max']) > 0)) {
            $this->invalid($path);
        }
    }

    private function invalid(string $path): never
    {
        throw new ContractBuilderException('contracts.variable_value_invalid', 422, ['field' => $path]);
    }
}
