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
        $dataSources = $this->builderService->getAvailableDataSources();

        return response()->json([
            'success' => true,
            'data' => $dataSources,
        ]);
    }

    public function getDataSourceFields(string $dataSource): JsonResponse
    {
        $fields = $this->builderService->getDataSourceFields($dataSource);

        if (empty($fields)) {
            return response()->json([
                'success' => false,
                'message' => 'Источник данных не найден',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $fields,
        ]);
    }

    public function getDataSourceRelations(string $dataSource): JsonResponse
    {
        $relations = $this->builderService->getDataSourceRelations($dataSource);

        return response()->json([
            'success' => true,
            'data' => $relations,
        ]);
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
        $operators = $this->builderService->getAllowedOperators();

        return response()->json([
            'success' => true,
            'data' => $operators,
        ]);
    }

    public function getAggregations(): JsonResponse
    {
        $aggregations = $this->builderService->getAggregationFunctions();

        return response()->json([
            'success' => true,
            'data' => $aggregations,
        ]);
    }

    public function getExportFormats(): JsonResponse
    {
        $formats = $this->builderService->getExportFormats();

        return response()->json([
            'success' => true,
            'data' => $formats,
        ]);
    }

    public function getCategories(): JsonResponse
    {
        $categories = $this->builderService->getCategories();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }
}

