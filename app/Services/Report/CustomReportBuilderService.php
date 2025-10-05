<?php

namespace App\Services\Report;

use App\Models\CustomReport;
use App\Services\Report\ReportDataSourceRegistry;
use App\Services\Report\Builders\ReportQueryBuilder;
use App\Services\Logging\LoggingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CustomReportBuilderService
{
    public function __construct(
        protected ReportDataSourceRegistry $registry,
        protected ReportQueryBuilder $queryBuilder,
        protected LoggingService $logging
    ) {}

    public function validateReportConfig(array $config, bool $requireFullConfig = true): array
    {
        $errors = [];

        try {
            $this->logging->technical('report_builder.service_validation_started', [
                'require_full_config' => $requireFullConfig,
                'config_keys' => array_keys($config),
                'has_name' => !empty($config['name']),
                'has_category' => !empty($config['report_category']),
                'has_data_sources' => !empty($config['data_sources']),
                'has_columns' => !empty($config['columns_config'])
            ], 'info');

            if ($requireFullConfig) {
                $this->logging->technical('report_builder.full_config_validation', [
                    'checking_name' => empty($config['name']),
                    'checking_category' => empty($config['report_category'])
                ], 'info');
                
                if (empty($config['name'])) {
                    $errors[] = 'Название отчета обязательно';
                }

                if (empty($config['report_category'])) {
                    $errors[] = 'Категория отчета обязательна';
                }
            }

            $this->logging->technical('report_builder.before_structure_validation', [
                'current_errors_count' => count($errors)
            ], 'info');
            
            $structureErrors = $this->validateConfigStructure($config);
            
            $this->logging->technical('report_builder.after_structure_validation', [
                'structure_errors_count' => count($structureErrors),
                'structure_errors' => $structureErrors
            ], 'info');
            
            $errors = array_merge($errors, $structureErrors);

            $this->logging->technical('report_builder.validation_completed', [
                'errors_count' => count($errors),
                'has_errors' => !empty($errors)
            ], empty($errors) ? 'info' : 'warning');

            return $errors;
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.validation_exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');
            throw $e;
        }
    }

    protected function validateConfigStructure(array $config): array
    {
        $errors = [];

        $this->logging->technical('report_builder.structure_validation_start', [
            'config_keys' => array_keys($config)
        ], 'info');

        try {
            if (!empty($config['data_sources'])) {
                $this->logging->technical('report_builder.validating_data_sources', [
                    'data_sources_keys' => array_keys($config['data_sources'])
                ], 'info');
                
                $primarySource = $config['data_sources']['primary'] ?? null;
                
                $this->logging->technical('report_builder.checking_primary_source', [
                    'primary_source' => $primarySource
                ], 'info');
                
                if ($primarySource && !$this->registry->validateDataSource($primarySource)) {
                    $errors[] = "Некорректный источник данных: {$primarySource}";
                    $this->logging->technical('report_builder.invalid_primary_source', [
                        'primary_source' => $primarySource
                    ], 'warning');
                }

                if (!empty($config['data_sources']['joins'])) {
                    $this->logging->technical('report_builder.validating_joins', [
                        'joins_count' => count($config['data_sources']['joins'])
                    ], 'info');
                    
                    $maxJoins = config('custom-reports.limits.max_joins', 7);
                    if (count($config['data_sources']['joins']) > $maxJoins) {
                        $errors[] = "Превышено максимальное количество JOIN'ов ({$maxJoins})";
                    }

                    foreach ($config['data_sources']['joins'] as $index => $join) {
                        if (empty($join['table'])) {
                            $errors[] = "JOIN #{$index}: не указана таблица";
                        } elseif (!$this->registry->validateDataSource($join['table'])) {
                            $errors[] = "JOIN #{$index}: некорректная таблица {$join['table']}";
                        }

                        if (empty($join['on']) || !is_array($join['on']) || count($join['on']) !== 2) {
                            $errors[] = "JOIN #{$index}: некорректное условие связи";
                        }
                    }
                }
            }

            if (!empty($config['columns_config'])) {
                $this->logging->technical('report_builder.validating_columns', [
                    'columns_count' => count($config['columns_config'])
                ], 'info');
                
                $maxColumns = config('custom-reports.limits.max_columns', 50);
                if (count($config['columns_config']) > $maxColumns) {
                    $errors[] = "Превышено максимальное количество колонок ({$maxColumns})";
                }

                foreach ($config['columns_config'] as $index => $column) {
                    if (empty($column['field'])) {
                        $errors[] = "Колонка #{$index}: не указано поле";
                    }
                    if (empty($column['label'])) {
                        $errors[] = "Колонка #{$index}: не указано название";
                    }
                }
            }

            if (!empty($config['aggregations_config'])) {
                $this->logging->technical('report_builder.validating_aggregations', [], 'info');
                
                $maxAggregations = config('custom-reports.limits.max_aggregations', 10);
                $aggregations = $config['aggregations_config']['aggregations'] ?? [];
                
                if (count($aggregations) > $maxAggregations) {
                    $errors[] = "Превышено максимальное количество агрегаций ({$maxAggregations})";
                }

                if (!empty($config['aggregations_config']['group_by']) && empty($aggregations)) {
                    $errors[] = "При использовании GROUP BY необходимо указать агрегатные функции";
                }
            }

            if (!empty($config['query_config']['where'])) {
                $this->logging->technical('report_builder.validating_filters', [
                    'filters_count' => count($config['query_config']['where'])
                ], 'info');
                
                $maxFilters = config('custom-reports.limits.max_filters', 20);
                if (count($config['query_config']['where']) > $maxFilters) {
                    $errors[] = "Превышено максимальное количество фильтров ({$maxFilters})";
                }
            }

            $this->logging->technical('report_builder.structure_validation_completed', [
                'errors_count' => count($errors),
                'errors' => $errors
            ], count($errors) > 0 ? 'warning' : 'info');

            return $errors;
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.structure_validation_exception', [
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString()
            ], 'error');
            throw $e;
        }
    }

    public function buildQueryFromConfig(CustomReport $report, int $organizationId): Builder
    {
        try {
            $this->logging->technical('report_builder.build_query_started', [
                'report_id' => $report->id,
                'report_name' => $report->name,
                'organization_id' => $organizationId,
                'has_aggregations' => !empty($report->aggregations_config),
                'has_filters' => !empty($report->query_config),
            ], 'debug');

            $config = [
                'data_sources' => $report->data_sources,
                'query_config' => $report->query_config ?? [],
                'columns_config' => $report->columns_config,
                'aggregations_config' => $report->aggregations_config ?? [],
                'sorting_config' => $report->sorting_config ?? [],
            ];

            $query = $this->queryBuilder->buildFromConfig($config, $organizationId);

            $this->logging->technical('report_builder.build_query_completed', [
                'report_id' => $report->id,
                'sql' => $query->toSql()
            ], 'debug');

            return $query;
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.build_query_failed', [
                'report_id' => $report->id,
                'report_name' => $report->name,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');
            throw $e;
        }
    }

    public function testReportQuery(CustomReport $report, int $organizationId, array $userFilters = []): array
    {
        try {
            $this->logging->technical('report_builder.test_query_started', [
                'report_id' => $report->id,
                'report_name' => $report->name,
                'organization_id' => $organizationId,
                'has_user_filters' => !empty($userFilters)
            ], 'debug');

            $query = $this->buildQueryFromConfig($report, $organizationId);

            if (!empty($userFilters) && !empty($report->filters_config)) {
                $this->applyUserFilters($query, $userFilters, $report);
            }

            $startTime = microtime(true);
            
            $results = $query->limit(20)->get();
            
            $executionTime = (microtime(true) - $startTime) * 1000;

            $this->logging->business('report_builder.test_query_completed', [
                'report_id' => $report->id,
                'report_name' => $report->name,
                'rows_count' => $results->count(),
                'execution_time_ms' => round($executionTime, 2)
            ]);

            return [
                'success' => true,
                'rows_count' => $results->count(),
                'execution_time_ms' => round($executionTime, 2),
                'preview_data' => $results->take(10)->toArray(),
                'sql' => $query->toSql(),
            ];
        } catch (\Exception $e) {
            $this->logging->technical('report_builder.test_query_failed', [
                'report_id' => $report->id,
                'report_name' => $report->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'error');

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ];
        }
    }

    protected function applyUserFilters(Builder $query, array $userFilters, CustomReport $report): void
    {
        $primarySource = $report->data_sources['primary'] ?? null;
        $filterBuilder = app(\App\Services\Report\Builders\ReportFilterBuilder::class);

        $configuredFilters = collect($report->filters_config ?? [])
            ->keyBy('field')
            ->toArray();

        foreach ($userFilters as $field => $value) {
            if (!isset($configuredFilters[$field])) {
                continue;
            }

            $filterConfig = $configuredFilters[$field];
            $operator = $filterConfig['operator'] ?? '=';

            if ($value === null && !($filterConfig['required'] ?? false)) {
                continue;
            }

            $filterBuilder->applyFilter($query, [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ], $primarySource);
        }
    }

    public function estimateQueryComplexity(CustomReport $report): int
    {
        $config = [
            'data_sources' => $report->data_sources,
            'query_config' => $report->query_config ?? [],
            'columns_config' => $report->columns_config,
            'aggregations_config' => $report->aggregations_config ?? [],
        ];

        return $this->queryBuilder->estimateQueryComplexity($config);
    }

    public function suggestOptimizations(CustomReport $report): array
    {
        $suggestions = [];
        
        $complexity = $this->estimateQueryComplexity($report);
        
        if ($complexity > 100) {
            $suggestions[] = [
                'type' => 'warning',
                'message' => 'Отчет имеет высокую сложность. Рассмотрите возможность упрощения.',
            ];
        }

        $joins = $report->data_sources['joins'] ?? [];
        $maxJoins = config('custom-reports.limits.max_joins', 7);
        
        if (count($joins) > $maxJoins / 2) {
            $suggestions[] = [
                'type' => 'info',
                'message' => 'Большое количество связанных таблиц может замедлить выполнение отчета.',
            ];
        }

        $aggregations = $report->aggregations_config['aggregations'] ?? [];
        
        if (count($aggregations) > 5) {
            $suggestions[] = [
                'type' => 'info',
                'message' => 'Множество агрегаций может увеличить время выполнения.',
            ];
        }

        if (empty($report->sorting_config)) {
            $suggestions[] = [
                'type' => 'tip',
                'message' => 'Добавьте сортировку для более предсказуемого порядка результатов.',
            ];
        }

        return $suggestions;
    }

    public function cloneReport(CustomReport $report, int $userId, int $organizationId): CustomReport
    {
        $newReport = $report->replicate();
        $newReport->name = $report->name . ' (копия)';
        $newReport->user_id = $userId;
        $newReport->organization_id = $organizationId;
        $newReport->is_shared = false;
        $newReport->is_favorite = false;
        $newReport->execution_count = 0;
        $newReport->last_executed_at = null;
        $newReport->save();

        return $newReport;
    }

    public function getAvailableDataSources(): array
    {
        return $this->registry->getAllDataSources();
    }

    public function getDataSourceFields(string $dataSourceKey): array
    {
        return $this->registry->getAvailableFields($dataSourceKey);
    }

    public function getDataSourceRelations(string $dataSourceKey): array
    {
        return $this->registry->getAvailableRelations($dataSourceKey);
    }

    public function getAllowedOperators(): array
    {
        return config('custom-reports.allowed_operators', []);
    }

    public function getAggregationFunctions(): array
    {
        return config('custom-reports.aggregation_functions', []);
    }

    public function getExportFormats(): array
    {
        return config('custom-reports.export_formats', []);
    }

    public function getCategories(): array
    {
        return config('custom-reports.categories', []);
    }
}

