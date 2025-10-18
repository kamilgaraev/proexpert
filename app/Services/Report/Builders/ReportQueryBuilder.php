<?php

namespace App\Services\Report\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Services\Report\ReportDataSourceRegistry;

class ReportQueryBuilder
{
    public function __construct(
        protected ReportDataSourceRegistry $registry,
        protected ReportFilterBuilder $filterBuilder,
        protected ReportAggregationBuilder $aggregationBuilder
    ) {}

    public function buildFromConfig(array $config, int $organizationId): Builder
    {
        $primarySource = $config['data_sources']['primary'] ?? null;
        
        if (!$primarySource || !$this->registry->validateDataSource($primarySource)) {
            throw new \InvalidArgumentException("Некорректный источник данных: {$primarySource}");
        }

        $query = $this->buildBaseQuery($primarySource, $organizationId);

        if ($this->shouldApplyMultiOrgScope($organizationId)) {
            $adapter = app(\App\BusinessModules\Core\MultiOrganization\Services\HoldingReportAdapter::class);
            $query = $adapter->applyReportScope($query, $organizationId, $config);
        }

        if (isset($config['data_sources']['joins']) && !empty($config['data_sources']['joins'])) {
            $this->applyJoins($query, $config['data_sources']['joins'], $primarySource);
        }

        if (isset($config['query_config']['where'])) {
            $logic = $config['query_config']['where_logic'] ?? 'and';
            $this->filterBuilder->applyFilters(
                $query, 
                $config['query_config']['where'], 
                $primarySource,
                $logic
            );
        }

        if (isset($config['columns_config']) && !empty($config['columns_config'])) {
            $this->selectColumns($query, $config['columns_config'], $primarySource);
        }

        if (isset($config['aggregations_config']) && !empty($config['aggregations_config'])) {
            $this->aggregationBuilder->applyAggregations(
                $query, 
                $config['aggregations_config'],
                $primarySource
            );
        }

        if (isset($config['sorting_config']) && !empty($config['sorting_config'])) {
            $this->applySorting($query, $config['sorting_config']);
        }

        return $query;
    }

    protected function buildBaseQuery(string $primarySource, int $organizationId): Builder
    {
        $modelClass = $this->registry->getModelClass($primarySource);
        
        if (!$modelClass || !class_exists($modelClass)) {
            throw new \InvalidArgumentException("Модель не найдена для источника: {$primarySource}");
        }

        $query = $modelClass::query();

        $defaultFilters = $this->registry->getDefaultFilters($primarySource);
        $this->applyDefaultFilters($query, $defaultFilters, $organizationId);

        return $query;
    }

    protected function applyDefaultFilters(Builder $query, array $filters, int $organizationId): Builder
    {
        foreach ($filters as $filter) {
            $field = $filter['field'] ?? null;
            $operator = $filter['operator'] ?? '=';
            $value = $filter['value'] ?? null;

            if (!$field) {
                continue;
            }

            if ($value === ':current_organization_id') {
                $value = $organizationId;
            }

            $query->where($field, $operator, $value);
        }

        return $query;
    }

    protected function selectColumns(Builder $query, array $columnsConfig, string $primarySource): Builder
    {
        $selects = [];
        $hasAggregations = false;

        foreach ($columnsConfig as $column) {
            $field = $column['field'] ?? null;
            
            if (!$field) {
                continue;
            }

            if (isset($column['aggregation'])) {
                $hasAggregations = true;
                continue;
            }

            $selects[] = $field;
        }

        if (!$hasAggregations && !empty($selects)) {
            $query->select($selects);
        }

        return $query;
    }

    protected function applyJoins(Builder $query, array $joins, string $primarySource): Builder
    {
        $maxJoins = config('custom-reports.limits.max_joins', 7);
        
        if (count($joins) > $maxJoins) {
            throw new \InvalidArgumentException("Превышено максимальное количество JOIN'ов ({$maxJoins})");
        }

        foreach ($joins as $join) {
            $table = $join['table'] ?? null;
            $type = strtolower($join['type'] ?? 'inner');
            $on = $join['on'] ?? null;

            if (!$table || !$on || !is_array($on) || count($on) < 2) {
                continue;
            }

            if (!$this->registry->validateDataSource($table)) {
                throw new \InvalidArgumentException("Некорректная таблица для JOIN: {$table}");
            }

            $tableName = $this->registry->getTableName($table);
            $leftKey = $on[0];
            $rightKey = $on[1];
            $operator = $on[2] ?? '=';

            switch ($type) {
                case 'left':
                    $query->leftJoin($tableName, $leftKey, $operator, $rightKey);
                    break;
                case 'right':
                    $query->rightJoin($tableName, $leftKey, $operator, $rightKey);
                    break;
                case 'inner':
                default:
                    $query->join($tableName, $leftKey, $operator, $rightKey);
                    break;
            }
        }

        return $query;
    }

    protected function applySorting(Builder $query, array $sortingConfig): Builder
    {
        foreach ($sortingConfig as $sort) {
            $field = $sort['field'] ?? null;
            $direction = $sort['direction'] ?? 'asc';

            if (!$field) {
                continue;
            }

            if (!in_array(strtolower($direction), ['asc', 'desc'])) {
                $direction = 'asc';
            }

            $query->orderBy($field, $direction);
        }

        return $query;
    }

    public function validateQueryConfig(array $config): array
    {
        $errors = [];

        if (!isset($config['data_sources']['primary'])) {
            $errors[] = 'Не указан основной источник данных';
            return $errors;
        }

        $primarySource = $config['data_sources']['primary'];
        
        if (!$this->registry->validateDataSource($primarySource)) {
            $errors[] = "Некорректный источник данных: {$primarySource}";
            return $errors;
        }

        if (isset($config['data_sources']['joins'])) {
            $maxJoins = config('custom-reports.limits.max_joins', 7);
            if (count($config['data_sources']['joins']) > $maxJoins) {
                $errors[] = "Превышено максимальное количество JOIN'ов ({$maxJoins})";
            }

            foreach ($config['data_sources']['joins'] as $index => $join) {
                $table = $join['table'] ?? null;
                
                if (!$table) {
                    $errors[] = "JOIN #{$index}: не указана таблица";
                    continue;
                }

                if (!$this->registry->validateDataSource($table)) {
                    $errors[] = "JOIN #{$index}: некорректная таблица '{$table}'";
                }

                if (!isset($join['on']) || !is_array($join['on']) || count($join['on']) < 2) {
                    $errors[] = "JOIN #{$index}: некорректное условие связи";
                }
            }
        }

        if (isset($config['query_config']['where'])) {
            foreach ($config['query_config']['where'] as $index => $filter) {
                if (!$this->filterBuilder->validateFilter($filter, $primarySource)) {
                    $errors[] = "Фильтр #{$index}: некорректная конфигурация";
                }
            }
        }

        if (isset($config['aggregations_config'])) {
            $aggErrors = $this->aggregationBuilder->validateAggregations(
                $config['aggregations_config'],
                $primarySource
            );
            $errors = array_merge($errors, $aggErrors);
        }

        if (isset($config['columns_config'])) {
            $maxColumns = config('custom-reports.limits.max_columns', 50);
            if (count($config['columns_config']) > $maxColumns) {
                $errors[] = "Превышено максимальное количество колонок ({$maxColumns})";
            }
        }

        return $errors;
    }

    public function estimateQueryComplexity(array $config): int
    {
        $complexity = 0;

        $complexity += count($config['data_sources']['joins'] ?? []) * 10;
        
        $complexity += count($config['query_config']['where'] ?? []);
        
        if (isset($config['aggregations_config']['aggregations'])) {
            $complexity += count($config['aggregations_config']['aggregations']) * 5;
        }
        
        if (isset($config['aggregations_config']['group_by'])) {
            $complexity += count($config['aggregations_config']['group_by']) * 3;
        }

        $complexity += count($config['columns_config'] ?? []);

        return $complexity;
    }

    protected function shouldApplyMultiOrgScope(int $organizationId): bool
    {
        try {
            if (!app()->bound(\App\Modules\Core\AccessController::class)) {
                return false;
            }

            $accessController = app(\App\Modules\Core\AccessController::class);
            if (!$accessController->hasModuleAccess($organizationId, 'multi-organization')) {
                return false;
            }

            $org = \App\Models\Organization::find($organizationId);
            return $org && $org->is_holding;
        } catch (\Exception $e) {
            return false;
        }
    }
}

