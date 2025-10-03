<?php

namespace App\Services\Report\Builders;

use Illuminate\Database\Eloquent\Builder;
use App\Services\Report\ReportDataSourceRegistry;

class ReportFilterBuilder
{
    protected array $allowedOperators;

    public function __construct(
        protected ReportDataSourceRegistry $registry
    ) {
        $this->allowedOperators = array_keys(config('custom-reports.allowed_operators', []));
    }

    public function applyFilter(Builder $query, array $filter, string $dataSource): Builder
    {
        $field = $filter['field'] ?? null;
        $operator = $filter['operator'] ?? '=';
        $value = $filter['value'] ?? null;

        if (!$field || !$this->isOperatorAllowed($operator)) {
            return $query;
        }

        if (!$this->validateFilter($filter, $dataSource)) {
            return $query;
        }

        return match($operator) {
            '=' => $this->applyEqualsFilter($query, $field, $value),
            '!=' => $this->applyNotEqualsFilter($query, $field, $value),
            '>' => $this->applyGreaterThanFilter($query, $field, $value),
            '<' => $this->applyLessThanFilter($query, $field, $value),
            '>=' => $this->applyGreaterOrEqualFilter($query, $field, $value),
            '<=' => $this->applyLessOrEqualFilter($query, $field, $value),
            'like' => $this->applyLikeFilter($query, $field, $value),
            'not_like' => $this->applyNotLikeFilter($query, $field, $value),
            'in' => $this->applyInFilter($query, $field, $value),
            'not_in' => $this->applyNotInFilter($query, $field, $value),
            'between' => $this->applyBetweenFilter($query, $field, $value),
            'is_null' => $this->applyNullFilter($query, $field, true),
            'is_not_null' => $this->applyNullFilter($query, $field, false),
            default => $query,
        };
    }

    public function applyFilters(Builder $query, array $filters, string $dataSource, string $logic = 'and'): Builder
    {
        if (empty($filters)) {
            return $query;
        }

        if ($logic === 'or') {
            return $query->where(function ($q) use ($filters, $dataSource) {
                foreach ($filters as $filter) {
                    $q->orWhere(function ($subQuery) use ($filter, $dataSource) {
                        $this->applyFilter($subQuery, $filter, $dataSource);
                    });
                }
            });
        }

        foreach ($filters as $filter) {
            $this->applyFilter($query, $filter, $dataSource);
        }

        return $query;
    }

    protected function applyEqualsFilter(Builder $query, string $field, $value): Builder
    {
        return $query->where($field, '=', $value);
    }

    protected function applyNotEqualsFilter(Builder $query, string $field, $value): Builder
    {
        return $query->where($field, '!=', $value);
    }

    protected function applyGreaterThanFilter(Builder $query, string $field, $value): Builder
    {
        return $query->where($field, '>', $value);
    }

    protected function applyLessThanFilter(Builder $query, string $field, $value): Builder
    {
        return $query->where($field, '<', $value);
    }

    protected function applyGreaterOrEqualFilter(Builder $query, string $field, $value): Builder
    {
        return $query->where($field, '>=', $value);
    }

    protected function applyLessOrEqualFilter(Builder $query, string $field, $value): Builder
    {
        return $query->where($field, '<=', $value);
    }

    protected function applyLikeFilter(Builder $query, string $field, string $value): Builder
    {
        return $query->where($field, 'LIKE', "%{$value}%");
    }

    protected function applyNotLikeFilter(Builder $query, string $field, string $value): Builder
    {
        return $query->where($field, 'NOT LIKE', "%{$value}%");
    }

    protected function applyInFilter(Builder $query, string $field, array $values): Builder
    {
        if (empty($values)) {
            return $query;
        }
        return $query->whereIn($field, $values);
    }

    protected function applyNotInFilter(Builder $query, string $field, array $values): Builder
    {
        if (empty($values)) {
            return $query;
        }
        return $query->whereNotIn($field, $values);
    }

    protected function applyBetweenFilter(Builder $query, string $field, array $range): Builder
    {
        if (count($range) !== 2) {
            return $query;
        }
        return $query->whereBetween($field, [$range[0], $range[1]]);
    }

    protected function applyNullFilter(Builder $query, string $field, bool $isNull): Builder
    {
        return $isNull 
            ? $query->whereNull($field)
            : $query->whereNotNull($field);
    }

    public function validateFilter(array $filter, string $dataSource): bool
    {
        $field = $filter['field'] ?? null;
        $operator = $filter['operator'] ?? '=';

        if (!$field || !$this->isOperatorAllowed($operator)) {
            return false;
        }

        $parsed = $this->registry->parseFieldName($field);
        $fieldSource = $parsed['source'] ?? $dataSource;
        $fieldName = $parsed['field'];

        if (!$this->registry->validateDataSource($fieldSource)) {
            return false;
        }

        if (!$this->registry->validateField($fieldSource, $fieldName)) {
            return false;
        }

        return true;
    }

    protected function isOperatorAllowed(string $operator): bool
    {
        return in_array($operator, $this->allowedOperators);
    }

    public function getAllowedOperators(): array
    {
        return config('custom-reports.allowed_operators', []);
    }

    public function getOperatorsForFieldType(string $fieldType): array
    {
        return match($fieldType) {
            'string' => ['=', '!=', 'like', 'not_like', 'in', 'not_in', 'is_null', 'is_not_null'],
            'integer', 'decimal' => ['=', '!=', '>', '<', '>=', '<=', 'in', 'not_in', 'between', 'is_null', 'is_not_null'],
            'date', 'datetime' => ['=', '!=', '>', '<', '>=', '<=', 'between', 'is_null', 'is_not_null'],
            'boolean' => ['=', '!=', 'is_null', 'is_not_null'],
            default => ['=', '!=', 'is_null', 'is_not_null'],
        };
    }
}

