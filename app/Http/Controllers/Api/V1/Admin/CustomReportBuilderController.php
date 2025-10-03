<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomReport;
use App\Services\Report\CustomReportBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomReportBuilderController extends Controller
{
    public function __construct(
        protected CustomReportBuilderService $builderService
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
        $config = $request->all();
        
        $errors = $this->builderService->validateReportConfig($config);

        if (!empty($errors)) {
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
    }

    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;

        $config = $request->all();
        
        $errors = $this->builderService->validateReportConfig($config);
        
        if (!empty($errors)) {
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

        return response()->json($result);
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

