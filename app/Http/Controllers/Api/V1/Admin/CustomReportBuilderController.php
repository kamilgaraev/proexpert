<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomReport;
use App\Services\Report\CustomReportBuilderService;
use App\Services\Logging\LoggingService;
use App\Http\Requests\Api\V1\Admin\CustomReport\ValidateConfigRequest;
use App\Http\Requests\Api\V1\Admin\CustomReport\PreviewReportRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomReportBuilderController extends Controller
{
    public function __construct(
        protected CustomReportBuilderService $builderService,
        protected LoggingService $logging
    ) {
    }

    public function getDataSources(): JsonResponse
    {
        try {
            $dataSources = $this->builderService->getAvailableDataSources();

            $this->logging->technical('report_builder.get_data_sources_success', [
                'count' => count($dataSources)
            ], 'debug');

            return AdminResponse::success($dataSources);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_data_sources_failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            return AdminResponse::error(trans_message('reports.builder.data_sources_failed'), 500);
        }
    }

    public function getDataSourceFields(string $dataSource): JsonResponse
    {
        try {
            $this->logging->technical('report_builder.get_fields_requested', [
                'data_source' => $dataSource
            ], 'debug');

            $fields = $this->builderService->getDataSourceFields($dataSource);

            if (empty($fields)) {
                $this->logging->technical('report_builder.data_source_not_found', [
                    'data_source' => $dataSource
                ], 'warning');

                return AdminResponse::error(trans_message('reports.builder.data_source_not_found'), 404);
            }

            return AdminResponse::success($fields);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_fields_failed', [
                'data_source' => $dataSource,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            return AdminResponse::error(trans_message('reports.builder.fields_failed'), 500);
        }
    }

    public function getDataSourceRelations(string $dataSource): JsonResponse
    {
        try {
            $relations = $this->builderService->getDataSourceRelations($dataSource);

            return AdminResponse::success($relations);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_relations_failed', [
                'data_source' => $dataSource,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            return AdminResponse::error(trans_message('reports.builder.relations_failed'), 500);
        }
    }

    public function validateConfig(ValidateConfigRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $this->logging->technical('report_builder.validate_config_start', [
                'user_id' => $user->id,
                'endpoint' => 'validateConfig'
            ], 'info');

            $config = $request->validated();
            
            $this->logging->technical('report_builder.validate_config_data_received', [
                'user_id' => $user->id,
                'config_keys' => array_keys($config),
                'data_sources_present' => isset($config['data_sources']),
                'columns_config_present' => isset($config['columns_config'])
            ], 'info');
            
            $this->logging->technical('report_builder.calling_validation_service', [
                'user_id' => $user->id,
                'organization_id' => $user->current_organization_id,
                'require_full_config' => false
            ], 'info');
            
            $errors = $this->builderService->validateReportConfig($config, false);
            
            $this->logging->technical('report_builder.validation_service_completed', [
                'user_id' => $user->id,
                'errors_count' => count($errors),
                'has_errors' => !empty($errors)
            ], 'info');

            if (!empty($errors)) {
                $this->logging->technical('report_builder.validation_errors', [
                    'user_id' => $user->id,
                    'errors_count' => count($errors),
                    'errors' => $errors
                ], 'info');

                return AdminResponse::error(trans_message('reports.custom.config_invalid'), 422, $errors);
            }

            $this->logging->technical('report_builder.validation_success', [
                'user_id' => $user->id,
                'organization_id' => $user->current_organization_id
            ], 'debug');

            return AdminResponse::success(null, trans_message('reports.builder.config_valid'));
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.validate_config_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            return AdminResponse::error(trans_message('reports.builder.validation_failed'), 500);
        }
    }

    public function preview(PreviewReportRequest $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        try {
            $config = $request->validated();
            
            $this->logging->technical('report_builder.preview_requested', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'has_filters' => isset($config['filters'])
            ], 'debug');
            
            $errors = $this->builderService->validateReportConfig($config, false);
            
            if (!empty($errors)) {
                $this->logging->technical('report_builder.preview_validation_failed', [
                    'user_id' => $user->id,
                    'errors' => $errors
                ], 'warning');

                return AdminResponse::error(trans_message('reports.custom.config_invalid'), 422, $errors);
            }

            $tempReport = new CustomReport($config);
            $tempReport->organization_id = $organizationId;

            $filters = $config['filters'] ?? [];
            $result = $this->builderService->testReportQuery($tempReport, $organizationId, $filters);

            $this->logging->business('report_builder.preview_completed', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'rows_count' => $result['rows_count'] ?? 0,
                'execution_time_ms' => $result['execution_time_ms'] ?? 0
            ]);

            return AdminResponse::success($result);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.preview_failed', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            return AdminResponse::error(trans_message('reports.builder.preview_failed'), 500);
        }
    }

    public function getOperators(): JsonResponse
    {
        try {
            $operators = $this->builderService->getAllowedOperators();

            return AdminResponse::success($operators);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_operators_failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            return AdminResponse::error(trans_message('reports.builder.operators_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    public function getAggregations(): JsonResponse
    {
        try {
            $aggregations = $this->builderService->getAggregationFunctions();

            return AdminResponse::success($aggregations);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_aggregations_failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            return AdminResponse::error(trans_message('reports.builder.aggregations_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    public function getExportFormats(): JsonResponse
    {
        try {
            $formats = $this->builderService->getExportFormats();

            return AdminResponse::success($formats);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_export_formats_failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            return AdminResponse::error(trans_message('reports.builder.export_formats_failed') . ': ' . $e->getMessage(), 500);
        }
    }

    public function getCategories(): JsonResponse
    {
        try {
            $categories = $this->builderService->getCategories();

            return AdminResponse::success($categories);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_categories_failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            return AdminResponse::error(trans_message('reports.builder.categories_failed') . ': ' . $e->getMessage(), 500);
        }
    }
}

