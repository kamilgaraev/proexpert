<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\BusinessModules\Features\AdvancedDashboard\Services\AlertsService;
use App\BusinessModules\Features\AdvancedDashboard\Models\DashboardAlert;
use App\Services\LogService;

/**
 * Контроллер управления алертами
 */
class AlertsController extends Controller
{
    protected AlertsService $alertsService;

    public function __construct(AlertsService $alertsService)
    {
        $this->alertsService = $alertsService;
    }

    /**
     * Получить список алертов
     * 
     * GET /api/v1/admin/advanced-dashboard/alerts
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->header('X-Organization-ID');
        $userId = Auth::id() ?? 0;
        
        $query = DashboardAlert::forUser($userId)
            ->forOrganization($organizationId);
        
        // Фильтры
        if ($request->has('dashboard_id')) {
            $query->where('dashboard_id', $request->input('dashboard_id'));
        }
        
        if ($request->has('type')) {
            $query->byType($request->input('type'));
        }
        
        if ($request->has('is_active')) {
            $isActive = $request->boolean('is_active');
            if ($isActive) {
                $query->active();
            }
        }
        
        if ($request->has('priority')) {
            $query->byPriority($request->input('priority'));
        }
        
        $alerts = $query->orderBy('created_at', 'desc')->get();
        
        return response()->json([
            'success' => true,
            'data' => $alerts,
        ]);
    }

    /**
     * Создать новый алерт
     * 
     * POST /api/v1/admin/advanced-dashboard/alerts
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dashboard_id' => 'nullable|integer|exists:dashboards,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'alert_type' => 'required|string|in:budget_overrun,deadline_risk,low_stock,contract_completion,payment_overdue,kpi_threshold,custom',
            'target_entity' => 'nullable|string|in:project,contract,material,user',
            'target_entity_id' => 'nullable|integer',
            'conditions' => 'nullable|array',
            'comparison_operator' => 'required|string|in:gt,gte,lt,lte,eq,neq,>,>=,<,<=,==,!=',
            'threshold_value' => 'nullable|numeric',
            'threshold_unit' => 'nullable|string',
            'notification_channels' => 'nullable|array',
            'notification_channels.*' => 'string|in:email,in_app,webhook',
            'recipients' => 'nullable|array',
            'cooldown_minutes' => 'nullable|integer|min:1|max:10080',
            'priority' => 'nullable|string|in:low,medium,high,critical',
            'is_active' => 'nullable|boolean',
        ]);
        
        $userId = Auth::id() ?? 0;
        $organizationId = $request->header('X-Organization-ID');
        
        try {
            $alert = $this->alertsService->registerAlert(
                $userId,
                $organizationId,
                $validated
            );
            
            LogService::info('Alert created', [
                'alert_id' => $alert->id,
                'alert_type' => $alert->alert_type,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Alert created successfully',
                'data' => $alert,
            ], 201);
            
        } catch (\Exception $e) {
            LogService::error('Failed to create alert', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Получить конкретный алерт
     * 
     * GET /api/v1/admin/advanced-dashboard/alerts/{id}
     */
    public function show(int $id): JsonResponse
    {
        $alert = DashboardAlert::findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $alert,
        ]);
    }

    /**
     * Обновить алерт
     * 
     * PUT /api/v1/admin/advanced-dashboard/alerts/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'threshold_value' => 'nullable|numeric',
            'threshold_unit' => 'nullable|string',
            'notification_channels' => 'nullable|array',
            'recipients' => 'nullable|array',
            'cooldown_minutes' => 'nullable|integer|min:1|max:10080',
            'priority' => 'nullable|string|in:low,medium,high,critical',
        ]);
        
        try {
            $alert = $this->alertsService->updateAlert($id, $validated);
            
            LogService::info('Alert updated', [
                'alert_id' => $alert->id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Alert updated successfully',
                'data' => $alert,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Включить/выключить алерт
     * 
     * POST /api/v1/admin/advanced-dashboard/alerts/{id}/toggle
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ]);
        
        try {
            $alert = $this->alertsService->toggleAlert($id, $validated['is_active']);
            
            $action = $validated['is_active'] ? 'enabled' : 'disabled';
            
            LogService::info("Alert {$action}", [
                'alert_id' => $alert->id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Alert {$action} successfully",
                'data' => $alert,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Сбросить состояние алерта
     * 
     * POST /api/v1/admin/advanced-dashboard/alerts/{id}/reset
     */
    public function reset(int $id): JsonResponse
    {
        try {
            $alert = $this->alertsService->resetAlert($id);
            
            LogService::info('Alert reset', [
                'alert_id' => $alert->id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Alert reset successfully',
                'data' => $alert,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Получить историю срабатываний алерта
     * 
     * GET /api/v1/admin/advanced-dashboard/alerts/{id}/history
     */
    public function history(Request $request, int $id): JsonResponse
    {
        $limit = $request->input('limit', 50);
        
        try {
            $history = $this->alertsService->getAlertHistory($id, $limit);
            
            return response()->json([
                'success' => true,
                'data' => $history,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Проверить все активные алерты
     * 
     * POST /api/v1/admin/advanced-dashboard/alerts/check-all
     */
    public function checkAll(Request $request): JsonResponse
    {
        $organizationId = $request->header('X-Organization-ID');
        
        try {
            $stats = $this->alertsService->checkAllAlerts($organizationId);
            
            LogService::info('Alerts checked', $stats);
            
            return response()->json([
                'success' => true,
                'message' => 'Alerts checked successfully',
                'data' => $stats,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Удалить алерт
     * 
     * DELETE /api/v1/admin/advanced-dashboard/alerts/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->alertsService->deleteAlert($id);
            
            LogService::info('Alert deleted', [
                'alert_id' => $id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Alert deleted successfully',
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}

