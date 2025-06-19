<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Repositories\MaterialRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessLogicException;

class MaterialAnalyticsController extends Controller
{
    protected MaterialRepository $materialRepository;

    public function __construct(MaterialRepository $materialRepository)
    {
        $this->materialRepository = $materialRepository;
        $this->middleware('can:access-admin-panel');
    }

    private function getOrganizationId(Request $request): int
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            $user = $request->user();
            if ($user && $user->current_organization_id) {
                $organizationId = $user->current_organization_id;
            }
        }
        
        if (!$organizationId) {
            throw new \Exception('Контекст организации не определен');
        }
        
        return (int) $organizationId;
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');

            $summary = $this->materialRepository->getMaterialUsageSummary(
                $organizationId,
                $dateFrom,
                $dateTo
            );

            return response()->json([
                'success' => true,
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения сводки по материалам: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'date_from' => $request->get('date_from'),
                'date_to' => $request->get('date_to'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения сводки по материалам'
            ], 500);
        }
    }

    public function usageByProjects(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $projectIds = $request->get('project_ids', []);
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');

            if (is_string($projectIds)) {
                $projectIds = explode(',', $projectIds);
            }

            $usage = $this->materialRepository->getMaterialUsageByProjects(
                $organizationId,
                $projectIds,
                $dateFrom,
                $dateTo
            );

            return response()->json([
                'success' => true,
                'data' => $usage
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения использования материалов по проектам: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'project_ids' => $request->get('project_ids'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения использования материалов по проектам'
            ], 500);
        }
    }

    public function usageBySuppliers(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $supplierIds = $request->get('supplier_ids', []);
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');

            if (is_string($supplierIds)) {
                $supplierIds = explode(',', $supplierIds);
            }

            $usage = $this->materialRepository->getMaterialUsageBySuppliers(
                $organizationId,
                $supplierIds,
                $dateFrom,
                $dateTo
            );

            return response()->json([
                'success' => true,
                'data' => $usage
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения использования материалов по поставщикам: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'supplier_ids' => $request->get('supplier_ids'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения использования материалов по поставщикам'
            ], 500);
        }
    }

    public function lowStock(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $threshold = $request->get('threshold', 10);

            $materials = $this->materialRepository->getMaterialsWithLowStock(
                $organizationId,
                (float) $threshold
            );

            return response()->json([
                'success' => true,
                'data' => $materials
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения материалов с низким остатком: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'threshold' => $request->get('threshold'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения материалов с низким остатком'
            ], 500);
        }
    }

    public function mostUsed(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $period = $request->get('period', 30);
            $limit = $request->get('limit', 10);

            $materials = $this->materialRepository->getMostUsedMaterials(
                $organizationId,
                (int) $period,
                (int) $limit
            );

            return response()->json([
                'success' => true,
                'data' => $materials
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения наиболее используемых материалов: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'period' => $request->get('period'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения наиболее используемых материалов'
            ], 500);
        }
    }

    public function costHistory(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $materialId = $request->get('material_id');
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');

            if (!$materialId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не указан ID материала'
                ], 400);
            }

            $history = $this->materialRepository->getMaterialCostHistory(
                $organizationId,
                (int) $materialId,
                $dateFrom,
                $dateTo
            );

            return response()->json([
                'success' => true,
                'data' => $history
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения истории стоимости материала: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'material_id' => $request->get('material_id'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения истории стоимости материала'
            ], 500);
        }
    }

    public function movementReport(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $filters = [
                'date_from' => $request->get('date_from'),
                'date_to' => $request->get('date_to'),
                'material_ids' => $request->get('material_ids', []),
                'project_ids' => $request->get('project_ids', [])
            ];

            if (is_string($filters['material_ids'])) {
                $filters['material_ids'] = explode(',', $filters['material_ids']);
            }

            if (is_string($filters['project_ids'])) {
                $filters['project_ids'] = explode(',', $filters['project_ids']);
            }

            $report = $this->materialRepository->getMaterialMovementReport($organizationId, $filters);

            return response()->json([
                'success' => true,
                'data' => $report
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения отчета по движению материалов: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'filters' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения отчета по движению материалов'
            ], 500);
        }
    }

    public function inventoryReport(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $filters = [
                'category_ids' => $request->get('category_ids', []),
                'supplier_ids' => $request->get('supplier_ids', []),
                'include_zero_balance' => $request->get('include_zero_balance', false),
                'only_low_stock' => $request->get('only_low_stock', false),
                'stock_threshold' => $request->get('stock_threshold', 10)
            ];

            if (is_string($filters['category_ids'])) {
                $filters['category_ids'] = explode(',', $filters['category_ids']);
            }

            if (is_string($filters['supplier_ids'])) {
                $filters['supplier_ids'] = explode(',', $filters['supplier_ids']);
            }

            $report = $this->materialRepository->getInventoryReport($organizationId, $filters);

            return response()->json([
                'success' => true,
                'data' => $report
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения инвентаризационного отчета: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'filters' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения инвентаризационного отчета'
            ], 500);
        }
    }

    public function costDynamicsReport(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $filters = [
                'material_ids' => $request->get('material_ids', []),
                'date_from' => $request->get('date_from'),
                'date_to' => $request->get('date_to'),
                'group_by' => $request->get('group_by', 'month')
            ];

            if (is_string($filters['material_ids'])) {
                $filters['material_ids'] = explode(',', $filters['material_ids']);
            }

            $report = $this->materialRepository->getMaterialCostDynamicsReport($organizationId, $filters);

            return response()->json([
                'success' => true,
                'data' => $report
            ]);

        } catch (\Exception $e) {
            Log::error('Ошибка получения отчета по динамике стоимости: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'filters' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения отчета по динамике стоимости'
            ], 500);
        }
    }
} 