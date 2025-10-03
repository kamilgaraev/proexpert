<?php

namespace App\Services\Report;

use Illuminate\Database\Eloquent\Builder;
use App\Services\Report\ReportDataSourceRegistry;

class ReportQueryOptimizer
{
    public function __construct(
        protected ReportDataSourceRegistry $registry
    ) {}

    public function optimizeQuery(Builder $query, array $config): Builder
    {
        $this->addEagerLoading($query, $config);
        
        $this->addIndexHints($query, $config);

        return $query;
    }

    protected function addEagerLoading(Builder $query, array $config): Builder
    {
        $primarySource = $config['data_sources']['primary'] ?? null;
        
        if (!$primarySource) {
            return $query;
        }

        $relations = $this->detectUsedRelations($config, $primarySource);
        
        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query;
    }

    protected function detectUsedRelations(array $config, string $primarySource): array
    {
        $usedRelations = [];
        $availableRelations = $this->registry->getAvailableRelations($primarySource);
        
        $relationNames = array_column($availableRelations, 'key');

        foreach ($config['columns_config'] ?? [] as $column) {
            $field = $column['field'] ?? '';
            
            foreach ($relationNames as $relationName) {
                if (str_starts_with($field, $primarySource . '.' . $relationName)) {
                    $usedRelations[] = $relationName;
                }
            }
        }

        return array_unique($usedRelations);
    }

    protected function addIndexHints(Builder $query, array $config): Builder
    {
        return $query;
    }

    public function estimateQueryCost(array $config): int
    {
        $cost = 0;

        $cost += count($config['data_sources']['joins'] ?? []) * 100;

        $cost += count($config['query_config']['where'] ?? []) * 10;

        if (isset($config['aggregations_config']['aggregations'])) {
            $cost += count($config['aggregations_config']['aggregations']) * 50;
        }

        if (isset($config['aggregations_config']['group_by'])) {
            $cost += count($config['aggregations_config']['group_by']) * 30;
        }

        $cost += count($config['columns_config'] ?? []) * 5;

        return $cost;
    }

    public function validateQueryPerformance(array $config): array
    {
        $warnings = [];
        $cost = $this->estimateQueryCost($config);

        if ($cost > 500) {
            $warnings[] = [
                'level' => 'high',
                'message' => 'Очень высокая сложность запроса. Выполнение может занять много времени.',
            ];
        } elseif ($cost > 300) {
            $warnings[] = [
                'level' => 'medium',
                'message' => 'Высокая сложность запроса. Рекомендуется упростить.',
            ];
        }

        $joins = $config['data_sources']['joins'] ?? [];
        if (count($joins) > 5) {
            $warnings[] = [
                'level' => 'medium',
                'message' => 'Большое количество JOIN\'ов может замедлить выполнение.',
            ];
        }

        $filters = $config['query_config']['where'] ?? [];
        if (count($filters) < 1) {
            $warnings[] = [
                'level' => 'low',
                'message' => 'Отсутствуют фильтры. Отчет может вернуть слишком много данных.',
            ];
        }

        if (empty($config['sorting_config'])) {
            $warnings[] = [
                'level' => 'info',
                'message' => 'Не указана сортировка. Порядок результатов может быть непредсказуемым.',
            ];
        }

        return $warnings;
    }

    public function suggestIndexes(array $config): array
    {
        $suggestions = [];
        $primarySource = $config['data_sources']['primary'] ?? null;

        if (!$primarySource) {
            return $suggestions;
        }

        $tableName = $this->registry->getTableName($primarySource);

        foreach ($config['query_config']['where'] ?? [] as $filter) {
            $field = $filter['field'] ?? null;
            
            if ($field) {
                $suggestions[] = [
                    'table' => $tableName,
                    'field' => $field,
                    'type' => 'index',
                    'reason' => 'Используется в WHERE условии',
                ];
            }
        }

        if (isset($config['aggregations_config']['group_by'])) {
            foreach ($config['aggregations_config']['group_by'] as $field) {
                $suggestions[] = [
                    'table' => $tableName,
                    'field' => $field,
                    'type' => 'index',
                    'reason' => 'Используется в GROUP BY',
                ];
            }
        }

        foreach ($config['sorting_config'] ?? [] as $sort) {
            $field = $sort['field'] ?? null;
            
            if ($field) {
                $suggestions[] = [
                    'table' => $tableName,
                    'field' => $field,
                    'type' => 'index',
                    'reason' => 'Используется для сортировки',
                ];
            }
        }

        return $suggestions;
    }
}

