<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Modules\Core\ModuleManager;
use App\Modules\Services\ModuleActivationService;
use App\Modules\Services\ModuleBillingService;
use App\Modules\Services\ModulePermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Http\Responses\Api\V1\ErrorResponse;
use App\Http\Responses\Api\V1\SuccessResponse;

class ModuleController extends Controller
{
    protected ModuleManager $moduleManager;
    protected ModuleActivationService $activationService;
    protected ModuleBillingService $billingService;
    protected ModulePermissionService $permissionService;

    public function __construct(
        ModuleManager $moduleManager,
        ModuleActivationService $activationService,
        ModuleBillingService $billingService,
        ModulePermissionService $permissionService
    ) {
        $this->moduleManager = $moduleManager;
        $this->activationService = $activationService;
        $this->billingService = $billingService;
        $this->permissionService = $permissionService;
    }

    /**
     * Получить список всех доступных модулей с их статусами
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $allModules = $this->moduleManager->getAllAvailableModules();
        $activeModules = $this->moduleManager->getOrganizationModules($organizationId);
        $activeModuleSlugs = $activeModules->pluck('slug')->toArray();

        $modulesWithStatus = $allModules->map(function ($module) use ($activeModuleSlugs, $organizationId) {
            $isActive = in_array($module->slug, $activeModuleSlugs);
            $activation = $isActive ? $module->getActivationForOrganization($organizationId) : null;
            
            return [
                'slug' => $module->slug,
                'name' => $module->name,
                'description' => $module->description,
                'type' => $module->type,
                'category' => $module->category,
                'billing_model' => $module->billing_model,
                'price' => $module->getPrice(),
                'currency' => $module->getCurrency(),
                'duration_days' => $module->getDurationDays(),
                'features' => $module->features ?? [],
                'permissions' => $module->permissions ?? [],
                'icon' => $module->icon,
                'is_active' => $isActive,
                'activation' => $activation ? [
                    'activated_at' => $activation->activated_at,
                    'expires_at' => $activation->expires_at,
                    'status' => $activation->status,
                    'days_until_expiration' => $activation->getDaysUntilExpiration()
                ] : null
            ];
        })->groupBy('category');

        return (new SuccessResponse($modulesWithStatus->toArray()))->toResponse($request);
    }

    /**
     * Получить список только активных модулей организации
     */
    public function active(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $activeModules = $this->moduleManager->getOrganizationModules($organizationId);

        return (new SuccessResponse($activeModules->toArray()))->toResponse($request);
    }

    /**
     * Предпросмотр активации модуля
     */
    public function activationPreview(Request $request, string $moduleSlug): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $preview = $this->activationService->getActivationPreview($organizationId, $moduleSlug);

        if (!$preview['success']) {
            return (new ErrorResponse($preview['message'], 404))->toResponse($request);
        }

        return (new SuccessResponse($preview))->toResponse($request);
    }

    /**
     * Активировать модуль
     */
    public function activate(Request $request): JsonResponse
    {
        $request->validate([
            'module_slug' => 'required|string',
            'settings' => 'sometimes|array',
        ]);

        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $result = $this->activationService->activateModule(
            $organizationId,
            $request->input('module_slug'),
            [
                'settings' => $request->input('settings', [])
            ]
        );

        if (!$result['success']) {
            return (new ErrorResponse($result['message'], 400))->toResponse($request);
        }

        return (new SuccessResponse($result))->toResponse($request);
    }

    /**
     * Деактивировать модуль
     */
    public function deactivate(Request $request, string $moduleSlug): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $withRefund = $request->boolean('with_refund', false);
        
        $result = $this->activationService->deactivateModule($organizationId, $moduleSlug, $withRefund);

        if (!$result['success']) {
            return (new ErrorResponse($result['message'], 400))->toResponse($request);
        }

        return (new SuccessResponse($result))->toResponse($request);
    }

    /**
     * Продлить модуль
     */
    public function renew(Request $request, string $moduleSlug): JsonResponse
    {
        $request->validate([
            'additional_days' => 'sometimes|integer|min:1|max:365'
        ]);

        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $additionalDays = $request->input('additional_days', 30);
        
        $result = $this->activationService->renewModule($organizationId, $moduleSlug, $additionalDays);

        if (!$result['success']) {
            return (new ErrorResponse($result['message'], 400))->toResponse($request);
        }

        return (new SuccessResponse($result))->toResponse($request);
    }

    /**
     * Проверить доступ к модулю
     */
    public function checkAccess(Request $request): JsonResponse
    {
        $request->validate([
            'module_slug' => 'required|string'
        ]);

        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $moduleSlug = $request->input('module_slug');
        $hasAccess = $this->moduleManager->hasAccess($organizationId, $moduleSlug);

        return (new SuccessResponse([
            'module_slug' => $moduleSlug,
            'has_access' => $hasAccess
        ]))->toResponse($request);
    }

    /**
     * Получить истекающие модули
     */
    public function expiring(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $daysAhead = $request->input('days_ahead', 7);
        $expiringModules = $this->activationService->getExpiringModules($organizationId, $daysAhead);

        return (new SuccessResponse($expiringModules))->toResponse($request);
    }

    /**
     * Получить информацию о биллинге модулей
     */
    public function billing(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $billingStats = $this->billingService->getOrganizationBillingStats($organizationId);
        $upcomingBilling = $this->billingService->getUpcomingBilling($organizationId);

        return (new SuccessResponse([
            'stats' => $billingStats,
            'upcoming' => $upcomingBilling
        ]))->toResponse($request);
    }

    /**
     * Получить историю биллинга
     */
    public function billingHistory(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $moduleSlug = $request->input('module_slug');
        $history = $this->billingService->getBillingHistory($organizationId, $moduleSlug);

        return (new SuccessResponse($history))->toResponse($request);
    }

    /**
     * Получить права доступа пользователя
     */
    public function permissions(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        $permissions = $this->permissionService->getUserAvailablePermissions($user);
        $activeModules = $this->permissionService->getUserActiveModules($user);
        $permissionMatrix = $this->permissionService->getUserModulePermissionMatrix($user);

        return (new SuccessResponse([
            'permissions' => $permissions,
            'active_modules' => $activeModules,
            'permission_matrix' => $permissionMatrix
        ]))->toResponse($request);
    }

    /**
     * Массовая активация модулей
     */
    public function bulkActivate(Request $request): JsonResponse
    {
        $request->validate([
            'module_slugs' => 'required|array',
            'module_slugs.*' => 'string'
        ]);

        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $moduleSlugs = $request->input('module_slugs');
        $result = $this->activationService->bulkActivateModules($organizationId, $moduleSlugs);

        return (new SuccessResponse($result))->toResponse($request);
    }
}
