<?php

namespace App\Services\Report;

use App\Models\CustomReport;
use App\Models\CustomReportExecution;
use App\Services\Report\CustomReportBuilderService;
use App\Services\Export\CsvExporterService;
use App\Services\Export\ExcelExporterService;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomReportExecutionService
{
    public function __construct(
        protected CustomReportBuilderService $builder,
        protected CsvExporterService $csvExporter,
        protected ExcelExporterService $excelExporter,
        protected LoggingService $logging
    ) {}

    public function executeReport(
        CustomReport $report,
        int $organizationId,
        array $filters = [],
        ?string $exportFormat = null,
        ?int $userId = null
    ): array|StreamedResponse {
        $execution = $this->createExecution($report, $filters, $userId ?? $report->user_id, $organizationId);

        try {
            $execution->markAsProcessing();

            $startTime = microtime(true);
            
            $query = $this->builder->buildQueryFromConfig($report, $organizationId);

            if (!empty($filters) && !empty($report->filters_config)) {
                $this->applyUserFilters($query, $filters, $report);
            }

            $limit = config('custom-reports.limits.max_result_rows', 10000);
            $results = $this->executeQueryWithTimeout($query, $limit);

            $executionTime = (microtime(true) - $startTime) * 1000;

            $report->incrementExecutionCount();

            if ($exportFormat) {
                $filePath = $this->exportResults($results, $report, $exportFormat);
                
                $execution->markAsCompleted(
                    (int) $executionTime,
                    $results->count(),
                    $filePath
                );

                $this->logging->business('custom_report.exported', [
                    'report_id' => $report->id,
                    'report_name' => $report->name,
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'execution_time_ms' => $executionTime,
                    'rows_count' => $results->count(),
                    'export_format' => $exportFormat,
                ]);

                return $this->streamExport($filePath, $report->name, $exportFormat);
            }

            $formattedResults = $this->formatResults($results, $report->columns_config);

            $execution->markAsCompleted((int) $executionTime, count($formattedResults));

            $this->logging->business('custom_report.executed', [
                'report_id' => $report->id,
                'report_name' => $report->name,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'execution_time_ms' => $executionTime,
                'rows_count' => count($formattedResults),
            ]);

            return [
                'success' => true,
                'data' => $formattedResults,
                'meta' => [
                    'rows_count' => count($formattedResults),
                    'execution_time_ms' => round($executionTime, 2),
                    'execution_id' => $execution->id,
                ],
            ];

        } catch (\Exception $e) {
            $execution->markAsFailed($e->getMessage());

            $this->logging->technical('custom_report.execution.failed', [
                'report_id' => $report->id,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ], 'error');

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_id' => $execution->id,
            ];
        }
    }

    protected function createExecution(
        CustomReport $report,
        array $filters,
        int $userId,
        int $organizationId
    ): CustomReportExecution {
        return CustomReportExecution::create([
            'custom_report_id' => $report->id,
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'applied_filters' => $filters,
            'status' => CustomReportExecution::STATUS_PENDING,
        ]);
    }

    protected function applyUserFilters($query, array $userFilters, CustomReport $report): void
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

            if ($value === null && !($filterConfig['required'] ?? false)) {
                continue;
            }

            $type = $filterConfig['type'] ?? 'text';
            $operator = $this->getOperatorForFilterType($type);

            if ($type === 'date_range' && is_array($value)) {
                $filterBuilder->applyFilter($query, [
                    'field' => $field,
                    'operator' => 'between',
                    'value' => [$value['from'] ?? null, $value['to'] ?? null],
                ], $primarySource);
            } elseif ($type === 'multiselect' && is_array($value)) {
                $filterBuilder->applyFilter($query, [
                    'field' => $field,
                    'operator' => 'in',
                    'value' => $value,
                ], $primarySource);
            } else {
                $filterBuilder->applyFilter($query, [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value,
                ], $primarySource);
            }
        }
    }

    protected function getOperatorForFilterType(string $type): string
    {
        return match($type) {
            'text' => 'like',
            'select', 'number', 'date' => '=',
            'date_range' => 'between',
            'multiselect' => 'in',
            default => '=',
        };
    }

    protected function executeQueryWithTimeout($query, int $limit): Collection
    {
        $timeout = config('custom-reports.limits.query_timeout_seconds', 30);
        $timeoutMs = $timeout * 1000;
        
        DB::statement("SET SESSION max_execution_time = ?", [$timeoutMs]);

        return $query->limit($limit)->get();
    }

    protected function formatResults(Collection $results, array $columnsConfig): array
    {
        if ($results->isEmpty()) {
            return [];
        }

        return $results->map(function ($row) use ($columnsConfig) {
            $formatted = [];
            
            foreach ($columnsConfig as $column) {
                $field = $column['field'] ?? null;
                $label = $column['label'] ?? $field;
                $format = $column['format'] ?? 'text';

                if (!$field) {
                    continue;
                }

                $fieldName = $this->extractFieldName($field);
                $value = $row->{$fieldName} ?? null;

                $formatted[$label] = $this->formatValue($value, $format);
            }

            return $formatted;
        })->toArray();
    }

    protected function extractFieldName(string $fullFieldName): string
    {
        $parts = explode('.', $fullFieldName);
        return end($parts);
    }

    protected function formatValue($value, string $format)
    {
        if ($value === null) {
            return null;
        }

        return match($format) {
            'currency' => number_format($value, 2, '.', ' ') . ' ₽',
            'number' => number_format($value, 2, '.', ' '),
            'percent' => number_format($value, 2) . '%',
            'date' => \Carbon\Carbon::parse($value)->format('d.m.Y'),
            'datetime' => \Carbon\Carbon::parse($value)->format('d.m.Y H:i'),
            default => $value,
        };
    }

    protected function exportResults(Collection $results, CustomReport $report, string $format): string
    {
        $fileName = $this->generateFileName($report, $format);
        $columns = array_column($report->columns_config, 'label');
        
        $data = $results->map(function ($row) use ($report) {
            $formatted = [];
            
            foreach ($report->columns_config as $column) {
                $fieldName = $this->extractFieldName($column['field']);
                $formatted[] = $row->{$fieldName} ?? null;
            }
            
            return $formatted;
        })->toArray();

        return match($format) {
            'csv' => $this->csvExporter->export($columns, $data, $fileName),
            'excel' => $this->excelExporter->export($columns, $data, $fileName),
            default => throw new \InvalidArgumentException("Неподдерживаемый формат: {$format}"),
        };
    }

    protected function generateFileName(CustomReport $report, string $format): string
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $slug = \Illuminate\Support\Str::slug($report->name);
        return "{$slug}_{$timestamp}.{$format}";
    }

    protected function streamExport(string $filePath, string $reportName, string $format): StreamedResponse
    {
        $fileName = \Illuminate\Support\Str::slug($reportName) . '.' . $format;
        
        $mimeType = match($format) {
            'csv' => 'text/csv',
            'excel' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };

        return response()->streamDownload(function () use ($filePath) {
            echo file_get_contents($filePath);
        }, $fileName, [
            'Content-Type' => $mimeType,
        ]);
    }

    public function getExecutionHistory(CustomReport $report, int $limit = 50): Collection
    {
        return $report->executions()
            ->with(['user'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getExecution(int $executionId, int $organizationId): ?CustomReportExecution
    {
        return CustomReportExecution::where('id', $executionId)
            ->where('organization_id', $organizationId)
            ->first();
    }
}

