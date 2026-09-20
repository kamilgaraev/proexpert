<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractVariableDefinitionValidator
{
    private const TYPES = ['text', 'number', 'money', 'percentage', 'date', 'boolean', 'choice', 'entity', 'table'];

    public function validate(array $definition, int $depth = 0): void
    {
        $type = $definition['type'] ?? null;
        if ($depth > 4 || !in_array($type, self::TYPES, true)
            || array_diff(array_keys($definition), ['type', 'required', 'constraints', 'options', 'entity_type', 'columns', 'source', 'display', 'assignment']) !== []
            || (array_key_exists('required', $definition) && !is_bool($definition['required']))) {
            $this->invalid();
        }
        $constraints = $definition['constraints'] ?? [];
        if (!is_array($constraints) || ($constraints !== [] && array_is_list($constraints))) {
            $this->invalid();
        }
        $allowed = match ($type) {
            'text' => ['max_length'],
            'number', 'percentage' => ['min', 'max', 'scale'],
            'money' => ['min', 'max', 'scale', 'currency'],
            'date' => ['min', 'max'],
            'table' => ['min_rows', 'max_rows'],
            default => [],
        };
        if (array_diff(array_keys($constraints), $allowed) !== []) {
            $this->invalid();
        }
        foreach ($constraints as $key => $value) {
            $valid = match ($key) {
                'max_length' => is_int($value) && $value > 0 && $value <= 100000,
                'min_rows', 'max_rows' => is_int($value) && $value >= 0 && $value <= 10000,
                'scale' => is_int($value) && $value >= 0 && $value <= 12,
                'currency' => is_string($value) && preg_match('/^[A-Z]{3}$/D', $value) === 1,
                'min', 'max' => $type === 'date' ? $this->date($value) : $this->decimal($value),
                default => false,
            };
            if (!$valid) {
                $this->invalid();
            }
        }
        if (isset($constraints['min_rows'], $constraints['max_rows']) && $constraints['min_rows'] > $constraints['max_rows']) {
            $this->invalid();
        }
        if (isset($constraints['min'], $constraints['max'])
            && ($type === 'date' ? strcmp($constraints['min'], $constraints['max']) : $this->compareDecimals((string) $constraints['min'], (string) $constraints['max'])) > 0) {
            $this->invalid();
        }
        if ($type === 'choice') {
            $options = $definition['options'] ?? null;
            if (!is_array($options) || !array_is_list($options) || count($options) < 1 || count($options) > 500) {
                $this->invalid();
            }
            $ids = [];
            foreach ($options as $option) {
                if (!is_array($option) || !$this->identifier($option['id'] ?? null)
                    || !is_string($option['label'] ?? null) || trim($option['label']) === '' || mb_strlen($option['label']) > 255
                    || isset($ids[$option['id']]) || array_diff(array_keys($option), ['id', 'label']) !== []) {
                    $this->invalid();
                }
                $ids[$option['id']] = true;
            }
        } elseif (array_key_exists('options', $definition)) {
            $this->invalid();
        }
        if ($type === 'entity') {
            if (!in_array($definition['entity_type'] ?? null, ['organization', 'project', 'contract', 'counterparty', 'estimate'], true)) {
                $this->invalid();
            }
        } elseif (array_key_exists('entity_type', $definition)) {
            $this->invalid();
        }
        if ($type === 'table') {
            $columns = $definition['columns'] ?? null;
            if (!is_array($columns) || !array_is_list($columns) || count($columns) < 1 || count($columns) > 100) {
                $this->invalid();
            }
            $ids = [];
            foreach ($columns as $column) {
                if (!is_array($column) || !$this->identifier($column['id'] ?? null)
                    || !is_string($column['label'] ?? null) || trim($column['label']) === '' || mb_strlen($column['label']) > 255
                    || isset($ids[$column['id']]) || !is_array($column['definition'] ?? null)
                    || array_diff(array_keys($column), ['id', 'label', 'definition']) !== []) {
                    $this->invalid();
                }
                $ids[$column['id']] = true;
                $this->validate($column['definition'], $depth + 1);
            }
        } elseif (array_key_exists('columns', $definition)) {
            $this->invalid();
        }
        foreach (['source', 'display', 'assignment'] as $key) {
            if (isset($definition[$key]) && (!is_array($definition[$key]) || array_is_list($definition[$key]))) {
                $this->invalid();
            }
        }
        if (isset($definition['source'])) {
            $source = $definition['source'];
            if (($source['kind'] ?? null) === 'formula') {
                if ($depth !== 0 || !in_array($type, ['text', 'number', 'percentage', 'money', 'date', 'boolean'], true)
                    || !is_array($source['expression'] ?? null)
                    || array_diff(array_keys($source), ['kind', 'expression']) !== []) {
                    $this->invalid();
                }
                (new ContractFormulaExpression)->references($source['expression']);
            } elseif (($source['kind'] ?? null) === 'contract_context') {
                if ($depth !== 0 || !is_string($source['field'] ?? null)
                    || ContractContextSourceFields::type($source['field']) !== $type
                    || array_diff(array_keys($source), ['kind', 'field']) !== []) {
                    $this->invalid();
                }
            } elseif (($source['kind'] ?? null) === 'entity_field') {
                if ($depth !== 0 || !is_string($source['variable_id'] ?? null) || !\Illuminate\Support\Str::isUuid($source['variable_id'])
                    || !is_string($source['entity_type'] ?? null) || !is_string($source['field'] ?? null)
                    || ContractSourceFields::type($source['entity_type'], $source['field']) !== $type
                    || array_diff(array_keys($source), ['kind', 'variable_id', 'entity_type', 'field']) !== []) {
                    $this->invalid();
                }
            } elseif (($source['kind'] ?? null) !== 'manual' || array_diff(array_keys($source), ['kind']) !== []) {
                $this->invalid();
            }
        }
        if (isset($definition['assignment'])) {
            $assignment = $definition['assignment'];
            if (!in_array($assignment['target'] ?? null, ['works', 'price', 'schedule', 'advance', 'retention'], true)
                || array_diff(array_keys($assignment), ['target', 'field', 'columns']) !== []) {
                $this->invalid();
            }
            if (isset($assignment['field']) && ($assignment['target'] !== 'schedule'
                || $type !== 'date' || !in_array($assignment['field'], ['start_date', 'end_date'], true))) {
                $this->invalid();
            }
            if (isset($assignment['columns'])) {
                $mapping = $assignment['columns'];
                $columns = array_column($definition['columns'] ?? [], 'definition', 'id');
                if ($assignment['target'] !== 'works' || $type !== 'table' || !is_array($mapping)
                    || array_diff(array_keys($mapping), ['name', 'unit', 'quantity', 'price']) !== []
                    || count($mapping) !== 4) {
                    $this->invalid();
                }
                foreach (['name' => 'text', 'unit' => 'text', 'quantity' => 'number', 'price' => 'money'] as $field => $columnType) {
                    if (!is_string($mapping[$field] ?? null) || ($columns[$mapping[$field]]['type'] ?? null) !== $columnType) {
                        $this->invalid();
                    }
                }
                if (count(array_unique($mapping)) !== 4) {
                    $this->invalid();
                }
            }
        }
        if (isset($definition['display'])) {
            $display = $definition['display'];
            if (array_diff(array_keys($display), ['group', 'hint']) !== []) {
                $this->invalid();
            }
            foreach ($display as $value) {
                if (!is_string($value) || mb_strlen($value) > 1000) {
                    $this->invalid();
                }
            }
        }
    }

    public function identifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value) === 1;
    }

    public function decimal(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && preg_match('/^-?\d{1,24}(\.\d{1,12})?$/D', (string) $value) === 1;
    }

    public function date(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    public function compareDecimals(string $left, string $right): int
    {
        $parts = static function (string $value): array {
            [$integer, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
            $integer = ltrim($integer, '0') ?: '0';
            $fraction = str_pad($fraction, 12, '0');
            $negative = str_starts_with($value, '-') && ($integer !== '0' || trim($fraction, '0') !== '');

            return [$integer, $fraction, $negative];
        };
        [$li, $lf, $ln] = $parts($left);
        [$ri, $rf, $rn] = $parts($right);
        if ($ln !== $rn) {
            return $ln ? -1 : 1;
        }
        $comparison = (strlen($li) <=> strlen($ri)) ?: strcmp($li, $ri) ?: strcmp($lf, $rf);

        return $ln ? -$comparison : $comparison;
    }

    private function invalid(): never
    {
        throw new ContractBuilderException('contracts.variable_definition_invalid', 422);
    }
}
