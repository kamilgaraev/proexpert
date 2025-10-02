<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\V1\ErrorResponse;
use App\Http\Responses\Api\V1\SuccessResponse;
use App\Models\Module;
use App\Modules\Core\ModuleManager;
use App\Modules\Services\ModuleActivationService;
use App\Modules\Services\ModuleBillingService;
use App\Modules\Services\ModulePermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

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

        // Кешируем модули на 5 минут - они редко меняются
        $cacheKey = "modules_with_status_{$organizationId}";
        $modulesWithStatus = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($organizationId) {
            
            $allModules = $this->moduleManager->getAllAvailableModules();
            $activeModules = $this->moduleManager->getOrganizationModules($organizationId);
            $activeModuleSlugs = $activeModules->pluck('slug')->toArray();
            
            // КРИТИЧНО: Предзагружаем ВСЕ активации сразу одним запросом вместо N+1
            // Сначала получаем ID модулей по их slug'ам, затем активации
            $moduleIds = \App\Models\Module::whereIn('slug', $activeModuleSlugs)->pluck('id', 'slug');
            
            $activations = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
                ->whereIn('module_id', $moduleIds->values())
                ->with('module')
                ->get()
                ->keyBy(function($activation) {
                    return $activation->module->slug;
                });

            return $allModules->map(function ($module) use ($activeModuleSlugs, $organizationId, $activations) {
                // Системные модули (can_deactivate: false) всегда активны
                $isActive = !$module->can_deactivate || in_array($module->slug, $activeModuleSlugs);
                $activation = $isActive && isset($activations[$module->slug]) ? $activations[$module->slug] : null;
                
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
                    'can_deactivate' => $module->can_deactivate,
                    'is_active' => $isActive,
                    'activation' => $activation ? [
                        'activated_at' => $activation->activated_at,
                        'expires_at' => $activation->expires_at,
                        'status' => $activation->status,
                        'days_until_expiration' => $activation->getDaysUntilExpiration()
                    ] : null
                ];
            })->groupBy('category');
        });

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

        // Кешируем активные модули на 5 минут
        $cacheKey = "active_modules_{$organizationId}";
        $activeModules = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($organizationId) {
            return $this->moduleManager->getOrganizationModules($organizationId);
        });

        return (new SuccessResponse(Module::toPublicCollection($activeModules)))->toResponse($request);
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
     * Превью деактивации модуля - показать что потеряет пользователь
     */
    public function deactivationPreview(Request $request, string $moduleSlug): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $result = $this->moduleManager->deactivationPreview($organizationId, $moduleSlug);

        if (!$result['success']) {
            return (new ErrorResponse($result['message'], $result['code'] === 'MODULE_NOT_FOUND' ? 404 : 400))->toResponse($request);
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

        $daysAhead = $request->input('days', 7); // Исправлено: используем 'days' как в логах
        
        // Кешируем истекающие модули на 1 час
        $cacheKey = "expiring_modules_{$organizationId}_{$daysAhead}";
        $expiringModules = \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function () use ($organizationId, $daysAhead) {
            return $this->activationService->getExpiringModules($organizationId, $daysAhead);
        });

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

        // Кешируем биллинг на 2 минуты
        $cacheKey = "module_billing_{$organizationId}";
        $billingData = \Illuminate\Support\Facades\Cache::remember($cacheKey, 120, function () use ($organizationId) {
            return [
                'stats' => $this->billingService->getOrganizationBillingStats($organizationId),
                'upcoming' => $this->billingService->getUpcomingBilling($organizationId)
            ];
        });

        return (new SuccessResponse($billingData))->toResponse($request);
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
        
        // Кешируем права доступа пользователя на 5 минут
        $cacheKey = "user_permissions_{$user->id}_{$user->current_organization_id}";
        $permissionsData = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($user) {
            return [
                'permissions' => $this->permissionService->getUserAvailablePermissions($user),
                'active_modules' => $this->permissionService->getUserActiveModules($user),
                'permission_matrix' => $this->permissionService->getUserModulePermissionMatrix($user)
            ];
        });

        return (new SuccessResponse($permissionsData))->toResponse($request);
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

    public function getBundledModules(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return (new ErrorResponse('Организация не найдена', 404))->toResponse($request);
        }

        $subscription = \App\Models\OrganizationSubscription::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->with(['activeBundledModules.module'])
            ->first();

        if (!$subscription) {
            return (new SuccessResponse([
                'bundled_modules' => [],
                'has_subscription' => false,
                'message' => 'Нет активной подписки'
            ]))->toResponse($request);
        }

        $bundledModules = $subscription->activeBundledModules->map(function ($activation) {
            return [
                'id' => $activation->module->id,
                'name' => $activation->module->name,
                'slug' => $activation->module->slug,
                'description' => $activation->module->description,
                'category' => $activation->module->category,
                'icon' => $activation->module->icon,
                'activated_at' => $activation->activated_at,
                'expires_at' => $activation->expires_at,
                'status' => $activation->status,
            ];
        });

        return (new SuccessResponse([
            'bundled_modules' => $bundledModules,
            'has_subscription' => true,
            'subscription' => [
                'plan_name' => $subscription->plan->name,
                'ends_at' => $subscription->ends_at,
            ]
        ]))->toResponse($request);
    }
}
