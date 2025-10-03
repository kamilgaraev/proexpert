<?php

namespace App\Services\Report\Builders;

use Illuminate\Database\Eloquent\Builder;
use App\Services\Report\ReportDataSourceRegistry;
use Illuminate\Support\Facades\DB;

class ReportAggregationBuilder
{
    protected array $allowedFunctions;

    public function __construct(
        protected ReportDataSourceRegistry $registry
    ) {
        $this->allowedFunctions = array_keys(config('custom-reports.aggregation_functions', []));
    }

    public function applyAggregations(Builder $query, array $config, string $primarySource): Builder
    {
        if (empty($config)) {
            return $query;
        }

        if (isset($config['group_by']) && !empty($config['group_by'])) {
            $this->applyGroupBy($query, $config['group_by']);
        }

        if (isset($config['aggregations']) && !empty($config['aggregations'])) {
            $this->applyAggregationFunctions($query, $config['aggregations']);
        }

        if (isset($config['having']) && !empty($config['having'])) {
            $this->applyHavingConditions($query, $config['having']);
        }

        return $query;
    }

    protected function applyGroupBy(Builder $query, array $groupByFields): Builder
    {
        foreach ($groupByFields as $field) {
            $query->groupBy($field);
        }
        
        return $query;
    }

    protected function applyAggregationFunctions(Builder $query, array $aggregations): Builder
    {
        foreach ($aggregations as $aggregation) {
            $field = $aggregation['field'] ?? null;
            $function = $aggregation['function'] ?? null;
            $alias = $aggregation['alias'] ?? null;

            if (!$field || !$function || !$alias) {
                continue;
            }

            if (!$this->isFunctionAllowed($function)) {
                continue;
            }

            $selectRaw = $this->buildAggregationRaw($function, $field, $alias);
            $query->addSelect(DB::raw($selectRaw));
        }

        return $query;
    }

    protected function buildAggregationRaw(string $function, string $field, string $alias): string
    {
        return match($function) {
            'sum' => "SUM({$field}) as {$alias}",
            'avg' => "AVG({$field}) as {$alias}",
            'count' => "COUNT({$field}) as {$alias}",
            'min' => "MIN({$field}) as {$alias}",
            'max' => "MAX({$field}) as {$alias}",
            'count_distinct' => "COUNT(DISTINCT {$field}) as {$alias}",
            default => "{$field} as {$alias}",
        };
    }

    protected function applyHavingConditions(Builder $query, array $havingConditions): Builder
    {
        foreach ($havingConditions as $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? '=';
            $value = $condition['value'] ?? null;

            if (!$field) {
                continue;
            }

            $query->havingRaw("{$field} {$operator} ?", [$value]);
        }

        return $query;
    }

    public function validateAggregations(array $config, string $dataSource): array
    {
        $errors = [];

        $maxAggregations = config('custom-reports.limits.max_aggregations', 10);

        if (isset($config['aggregations']) && count($config['aggregations']) > $maxAggregations) {
            $errors[] = "Превышено максимальное количество агрегаций ({$maxAggregations})";
        }

        if (isset($config['aggregations'])) {
            foreach ($config['aggregations'] as $index => $aggregation) {
                $field = $aggregation['field'] ?? null;
                $function = $aggregation['function'] ?? null;

                if (!$field || !$function) {
                    $errors[] = "Агрегация #{$index}: отсутствует поле или функция";
                    continue;
                }

                if (!$this->isFunctionAllowed($function)) {
                    $errors[] = "Агрегация #{$index}: недопустимая функция '{$function}'";
                }

                $parsed = $this->registry->parseFieldName($field);
                $fieldSource = $parsed['source'] ?? $dataSource;
                $fieldName = $parsed['field'];

                if (!$this->registry->isFieldAggregatable($fieldSource, $fieldName)) {
                    $errors[] = "Агрегация #{$index}: поле '{$field}' не поддерживает агрегацию";
                }
            }
        }

        if (isset($config['group_by']) && empty($config['group_by'])) {
            if (isset($config['aggregations']) && !empty($config['aggregations'])) {
                $errors[] = "При использовании агрегаций необходимо указать GROUP BY";
            }
        }

        return $errors;
    }

    protected function isFunctionAllowed(string $function): bool
    {
        return in_array($function, $this->allowedFunctions);
    }

    public function getAllowedFunctions(): array
    {
        return config('custom-reports.aggregation_functions', []);
    }
}

