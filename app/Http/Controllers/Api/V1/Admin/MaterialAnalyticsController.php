<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
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
        // Авторизация настроена на уровне роутов через middleware стек
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
            throw new \Exception(trans_message('materials.organization_context_not_defined'));
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

            return AdminResponse::success($summary, trans_message('materials.analytics_summary_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@summary Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'date_from' => $request->get('date_from'),
                'date_to' => $request->get('date_to'),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_summary_error'), 500);
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

            return AdminResponse::success($usage, trans_message('materials.analytics_usage_by_projects_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@usageByProjects Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'project_ids' => $request->get('project_ids'),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_usage_by_projects_error'), 500);
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

            return AdminResponse::success($usage, trans_message('materials.analytics_usage_by_suppliers_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@usageBySuppliers Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'supplier_ids' => $request->get('supplier_ids'),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_usage_by_suppliers_error'), 500);
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

            return AdminResponse::success($materials, trans_message('materials.analytics_low_stock_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@lowStock Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'threshold' => $request->get('threshold'),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_low_stock_error'), 500);
        }
    }

    public function mostUsed(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $period = $request->get('period', 30);
            $limit = $request->get('limit', 10);

            $dateFrom = $period ? now()->subDays($period)->format('Y-m-d') : null;
            $dateTo = now()->format('Y-m-d');

            $materials = $this->materialRepository->getMostUsedMaterials(
                $organizationId,
                (int) $limit,
                $dateFrom,
                $dateTo
            );

            return AdminResponse::success($materials, trans_message('materials.analytics_most_used_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@mostUsed Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'period' => $request->get('period'),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_most_used_error'), 500);
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
                return AdminResponse::error(trans_message('materials.analytics_material_id_required'), 400);
            }

            $history = $this->materialRepository->getMaterialCostHistory(
                $organizationId,
                (int) $materialId,
                $dateFrom,
                $dateTo
            );

            return AdminResponse::success($history, trans_message('materials.analytics_cost_history_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@costHistory Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'material_id' => $request->get('material_id'),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_cost_history_error'), 500);
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

            return AdminResponse::success($report, trans_message('materials.analytics_movement_report_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@movementReport Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'filters' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_movement_report_error'), 500);
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

            return AdminResponse::success($report, trans_message('materials.analytics_inventory_report_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@inventoryReport Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'filters' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_inventory_report_error'), 500);
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

            return AdminResponse::success($report, trans_message('materials.analytics_cost_dynamics_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@costDynamicsReport Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'filters' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_cost_dynamics_error'), 500);
        }
    }

    /**
     * Получить аналитику материалов по проекту
     */
    public function getMaterialAnalytics(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $projectId = $request->route('project');
            
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');

            $analytics = $this->materialRepository->getMaterialUsageByProjects(
                $organizationId,
                [$projectId],
                $dateFrom,
                $dateTo
            );

            return AdminResponse::success($analytics, trans_message('materials.analytics_project_analytics_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@getMaterialAnalytics Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'project_id' => $request->route('project'),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_project_analytics_error'), 500);
        }
    }

    /**
     * Получить аналитику затрат на материалы по проекту
     */
    public function getCostAnalytics(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $projectId = $request->route('project');
            
            $filters = [
                'project_ids' => [$projectId],
                'date_from' => $request->get('date_from'),
                'date_to' => $request->get('date_to'),
                'group_by' => $request->get('group_by', 'month')
            ];

            $costAnalytics = $this->materialRepository->getMaterialCostDynamicsReport($organizationId, $filters);

            return AdminResponse::success($costAnalytics, trans_message('materials.analytics_cost_analytics_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@getCostAnalytics Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'project_id' => $request->route('project'),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_cost_analytics_error'), 500);
        }
    }

    /**
     * Получить аналитику использования материалов по проекту
     */
    public function getUsageAnalytics(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->getOrganizationId($request);
            $projectId = $request->route('project');
            
            $filters = [
                'project_id' => $projectId,
                'date_from' => $request->get('date_from'),
                'date_to' => $request->get('date_to'),
            ];

            $movementReport = $this->materialRepository->getMaterialMovementReport($organizationId, $filters);

            return AdminResponse::success($movementReport, trans_message('materials.analytics_usage_analytics_success'));

        } catch (\Exception $e) {
            Log::error('MaterialAnalyticsController@getUsageAnalytics Exception', [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'project_id' => $request->route('project'),
                'trace' => $e->getTraceAsString()
            ]);

            return AdminResponse::error(trans_message('materials.analytics_usage_analytics_error'), 500);
        }
    }
} 