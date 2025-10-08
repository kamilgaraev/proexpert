<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\BusinessModules\Features\AdvancedDashboard\Services\DashboardLayoutService;
use App\BusinessModules\Features\AdvancedDashboard\Models\Dashboard;
use App\Services\LogService;

/**
 * Контроллер управления дашбордами
 * 
 * CRUD операции, share, duplicate, templates
 */
class DashboardManagementController extends Controller
{
    protected DashboardLayoutService $layoutService;

    public function __construct(DashboardLayoutService $layoutService)
    {
        $this->layoutService = $layoutService;
    }

    /**
     * Получить список дашбордов пользователя
     * 
     * GET /api/v1/admin/advanced-dashboard/dashboards
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $userId = $user?->id ?? 0;
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        $includeShared = $request->boolean('include_shared', true);
        
        $dashboards = $this->layoutService->getUserDashboards(
            $userId,
            $organizationId,
            $includeShared
        );
        
        return response()->json([
            'success' => true,
            'data' => $dashboards,
        ]);
    }

    /**
     * Создать новый дашборд
     * 
     * POST /api/v1/admin/advanced-dashboard/dashboards
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'slug' => 'nullable|string|max:255',
            'layout' => 'nullable|array',
            'widgets' => 'nullable|array',
            'filters' => 'nullable|array',
            'template' => 'nullable|string|in:financial,projects,contracts,materials,hr,predictive,activity,performance,custom',
            'refresh_interval' => 'nullable|integer|min:30|max:3600',
            'enable_realtime' => 'nullable|boolean',
            'visibility' => 'nullable|string|in:private,team,organization',
        ]);
        
        $user = Auth::user();
        $userId = $user?->id ?? 0;
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        try {
            $dashboard = $this->layoutService->createDashboard(
                $userId,
                $organizationId,
                $validated
            );
            
            LogService::info('Dashboard created', [
                'dashboard_id' => $dashboard->id,
                'dashboard_name' => $dashboard->name,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Dashboard created successfully',
                'data' => $dashboard,
            ], 201);
            
        } catch (\Exception $e) {
            LogService::error('Failed to create dashboard', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Создать дашборд из шаблона
     * 
     * POST /api/v1/admin/advanced-dashboard/dashboards/from-template
     */
    public function createFromTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template' => 'required|string|in:financial,projects,contracts,materials,hr,predictive,activity,performance',
            'name' => 'nullable|string|max:255',
        ]);
        
        $user = Auth::user();
        $userId = $user?->id ?? 0;
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        try {
            $dashboard = $this->layoutService->createFromTemplate(
                $userId,
                $organizationId,
                $validated['template'],
                $validated['name'] ?? null
            );
            
            LogService::info('Dashboard created from template', [
                'dashboard_id' => $dashboard->id,
                'template' => $validated['template'],
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Dashboard created from template',
                'data' => $dashboard,
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Получить доступные шаблоны
     * 
     * GET /api/v1/admin/advanced-dashboard/dashboards/templates
     */
    public function templates(): JsonResponse
    {
        $templates = $this->layoutService->getAvailableTemplates();
        
        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * Получить конкретный дашборд
     * 
     * GET /api/v1/admin/advanced-dashboard/dashboards/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $dashboard = Dashboard::findOrFail($id);
        
        $user = Auth::user();
        $userId = $user?->id ?? 0;
        $organizationId = $request->attributes->get('current_organization_id') ?? $user?->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization not determined',
            ], 400);
        }
        
        // Проверка доступа
        if (!$dashboard->canBeAccessedBy($userId, $organizationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied to this dashboard',
            ], 403);
        }
        
        // Увеличиваем счетчик просмотров
        $dashboard->incrementViews();
        
        return response()->json([
            'success' => true,
            'data' => $dashboard,
        ]);
    }

    /**
     * Обновить дашборд
     * 
     * PUT /api/v1/admin/advanced-dashboard/dashboards/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $dashboard = Dashboard::findOrFail($id);
        
        // Проверка прав
        $userId = Auth::id() ?? 0;
        if (!$dashboard->isOwnedBy($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only edit your own dashboards',
            ], 403);
        }
        
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'refresh_interval' => 'nullable|integer|min:30|max:3600',
            'enable_realtime' => 'nullable|boolean',
            'visibility' => 'nullable|string|in:private,team,organization',
        ]);
        
        $dashboard->update($validated);
        
        LogService::info('Dashboard updated', [
            'dashboard_id' => $dashboard->id,
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Dashboard updated successfully',
            'data' => $dashboard->fresh(),
        ]);
    }

    /**
     * Обновить layout дашборда
     * 
     * PUT /api/v1/admin/advanced-dashboard/dashboards/{id}/layout
     */
    public function updateLayout(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'layout' => 'required|array',
        ]);
        
        try {
            $dashboard = $this->layoutService->updateDashboardLayout(
                $id,
                $validated['layout']
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Layout updated successfully',
                'data' => $dashboard,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Обновить виджеты дашборда
     * 
     * PUT /api/v1/admin/advanced-dashboard/dashboards/{id}/widgets
     */
    public function updateWidgets(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'widgets' => 'required|array',
        ]);
        
        try {
            $dashboard = $this->layoutService->updateDashboardWidgets(
                $id,
                $validated['widgets']
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Widgets updated successfully',
                'data' => $dashboard,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Обновить фильтры дашборда
     * 
     * PUT /api/v1/admin/advanced-dashboard/dashboards/{id}/filters
     */
    public function updateFilters(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'filters' => 'required|array',
        ]);
        
        try {
            $dashboard = $this->layoutService->updateDashboardFilters(
                $id,
                $validated['filters']
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Filters updated successfully',
                'data' => $dashboard,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Расшарить дашборд
     * 
     * POST /api/v1/admin/advanced-dashboard/dashboards/{id}/share
     */
    public function share(Request $request, int $id): JsonResponse
    {
        $dashboard = Dashboard::findOrFail($id);
        
        $userId = Auth::id() ?? 0;
        if (!$dashboard->isOwnedBy($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only share your own dashboards',
            ], 403);
        }
        
        $validated = $request->validate([
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'integer|exists:users,id',
            'visibility' => 'required|string|in:team,organization',
        ]);
        
        try {
            $dashboard = $this->layoutService->shareDashboard(
                $id,
                $validated['user_ids'] ?? [],
                $validated['visibility']
            );
            
            LogService::info('Dashboard shared', [
                'dashboard_id' => $dashboard->id,
                'visibility' => $validated['visibility'],
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Dashboard shared successfully',
                'data' => $dashboard,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Убрать расшаривание дашборда
     * 
     * DELETE /api/v1/admin/advanced-dashboard/dashboards/{id}/share
     */
    public function unshare(int $id): JsonResponse
    {
        $dashboard = Dashboard::findOrFail($id);
        
        $userId = Auth::id() ?? 0;
        if (!$dashboard->isOwnedBy($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only unshare your own dashboards',
            ], 403);
        }
        
        try {
            $dashboard = $this->layoutService->unshareDashboard($id);
            
            return response()->json([
                'success' => true,
                'message' => 'Dashboard unshared successfully',
                'data' => $dashboard,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Дублировать дашборд
     * 
     * POST /api/v1/admin/advanced-dashboard/dashboards/{id}/duplicate
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
        ]);
        
        try {
            $newDashboard = $this->layoutService->duplicateDashboard(
                $id,
                $validated['name'] ?? null
            );
            
            LogService::info('Dashboard duplicated', [
                'original_id' => $id,
                'new_id' => $newDashboard->id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Dashboard duplicated successfully',
                'data' => $newDashboard,
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Установить дашборд как дефолтный
     * 
     * POST /api/v1/admin/advanced-dashboard/dashboards/{id}/make-default
     */
    public function makeDefault(int $id): JsonResponse
    {
        $dashboard = Dashboard::findOrFail($id);
        
        $userId = Auth::id() ?? 0;
        if (!$dashboard->isOwnedBy($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only set your own dashboards as default',
            ], 403);
        }
        
        try {
            $dashboard = $this->layoutService->setDefaultDashboard($id);
            
            return response()->json([
                'success' => true,
                'message' => 'Dashboard set as default',
                'data' => $dashboard,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Удалить дашборд
     * 
     * DELETE /api/v1/admin/advanced-dashboard/dashboards/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $dashboard = Dashboard::findOrFail($id);
        
        $userId = Auth::id() ?? 0;
        if (!$dashboard->isOwnedBy($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete your own dashboards',
            ], 403);
        }
        
        try {
            $this->layoutService->deleteDashboard($id);
            
            LogService::info('Dashboard deleted', [
                'dashboard_id' => $id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Dashboard deleted successfully',
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}

