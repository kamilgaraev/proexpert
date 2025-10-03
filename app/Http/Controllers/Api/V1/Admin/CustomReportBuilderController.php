<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomReport;
use App\Services\Report\CustomReportBuilderService;
use App\Services\Logging\LoggingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CustomReportBuilderController extends Controller
{
    public function __construct(
        protected CustomReportBuilderService $builderService,
        protected LoggingService $logging
    ) {
        $this->middleware('can:view-reports');
    }

    public function getDataSources(): JsonResponse
    {
        try {
            $this->logging->technical('report_builder.get_data_sources_requested', [], 'debug');

            $dataSources = $this->builderService->getAvailableDataSources();

            $this->logging->technical('report_builder.get_data_sources_success', [
                'count' => count($dataSources)
            ], 'debug');

            return response()->json([
                'success' => true,
                'data' => $dataSources,
            ]);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_data_sources_failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportBuilderController@getDataSources] Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения источников данных: ' . $e->getMessage()
            ], 500);
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

                return response()->json([
                    'success' => false,
                    'message' => 'Источник данных не найден',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $fields,
            ]);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_fields_failed', [
                'data_source' => $dataSource,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportBuilderController@getDataSourceFields] Error', [
                'data_source' => $dataSource,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения полей: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getDataSourceRelations(string $dataSource): JsonResponse
    {
        try {
            $relations = $this->builderService->getDataSourceRelations($dataSource);

            return response()->json([
                'success' => true,
                'data' => $relations,
            ]);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_relations_failed', [
                'data_source' => $dataSource,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportBuilderController@getDataSourceRelations] Error', [
                'data_source' => $dataSource,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения связей: ' . $e->getMessage()
            ], 500);
        }
    }

    public function validateConfig(Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $this->logging->technical('report_builder.validate_config_requested', [
                'user_id' => $user->id,
                'organization_id' => $user->current_organization_id
            ], 'debug');

            $config = $request->all();
            
            $errors = $this->builderService->validateReportConfig($config);

            if (!empty($errors)) {
                $this->logging->technical('report_builder.validation_errors', [
                    'user_id' => $user->id,
                    'errors_count' => count($errors),
                    'errors' => $errors
                ], 'info');

                return response()->json([
                    'success' => false,
                    'message' => 'Конфигурация содержит ошибки',
                    'errors' => $errors,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Конфигурация валидна',
            ]);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.validate_config_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportBuilderController@validateConfig] Unexpected error', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка при валидации'
            ], 500);
        }
    }

    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        try {
            $this->logging->technical('report_builder.preview_requested', [
                'user_id' => $user->id,
                'organization_id' => $organizationId
            ], 'debug');

            $config = $request->all();
            
            $errors = $this->builderService->validateReportConfig($config);
            
            if (!empty($errors)) {
                $this->logging->technical('report_builder.preview_validation_failed', [
                    'user_id' => $user->id,
                    'errors' => $errors
                ], 'warning');

                return response()->json([
                    'success' => false,
                    'message' => 'Конфигурация содержит ошибки',
                    'errors' => $errors,
                ], 422);
            }

            $tempReport = new CustomReport($config);
            $tempReport->organization_id = $organizationId;

            $filters = $request->input('filters', []);
            $result = $this->builderService->testReportQuery($tempReport, $organizationId, $filters);

            $this->logging->business('report_builder.preview_completed', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'rows_count' => $result['rows_count'] ?? 0,
                'execution_time_ms' => $result['execution_time_ms'] ?? 0
            ]);

            return response()->json($result);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.preview_failed', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportBuilderController@preview] Unexpected error', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка при предпросмотре отчета'
            ], 500);
        }
    }

    public function getOperators(): JsonResponse
    {
        try {
            $operators = $this->builderService->getAllowedOperators();

            return response()->json([
                'success' => true,
                'data' => $operators,
            ]);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_operators_failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportBuilderController@getOperators] Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения операторов: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getAggregations(): JsonResponse
    {
        try {
            $aggregations = $this->builderService->getAggregationFunctions();

            return response()->json([
                'success' => true,
                'data' => $aggregations,
            ]);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_aggregations_failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportBuilderController@getAggregations] Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения агрегаций: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getExportFormats(): JsonResponse
    {
        try {
            $formats = $this->builderService->getExportFormats();

            return response()->json([
                'success' => true,
                'data' => $formats,
            ]);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_export_formats_failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportBuilderController@getExportFormats] Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения форматов экспорта: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getCategories(): JsonResponse
    {
        try {
            $categories = $this->builderService->getCategories();

            return response()->json([
                'success' => true,
                'data' => $categories,
            ]);
        } catch (\Throwable $e) {
            $this->logging->technical('report_builder.get_categories_failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ], 'error');

            Log::error('[CustomReportBuilderController@getCategories] Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения категорий: ' . $e->getMessage()
            ], 500);
        }
    }
}

