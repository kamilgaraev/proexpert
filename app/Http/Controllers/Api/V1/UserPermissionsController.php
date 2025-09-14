<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RoleScanner;
use App\Domain\Authorization\Models\AuthorizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Контроллер для получения прав и ролей пользователя
 * Используется фронтендом для динамического управления доступом к UI
 */
class UserPermissionsController extends Controller
{
    protected AuthorizationService $authService;
    protected RoleScanner $roleScanner;

    public function __construct(AuthorizationService $authService, RoleScanner $roleScanner)
    {
        $this->authService = $authService;
        $this->roleScanner = $roleScanner;
    }

    /**
     * Получить все права и роли текущего пользователя
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $organizationId = $this->getOrganizationId($request);
        
        // Определяем контекст
        $context = $organizationId ? ['organization_id' => $organizationId] : null;
        $authContext = $organizationId ? AuthorizationContext::getOrganizationContext($organizationId) : null;
        
        // Получаем роли пользователя
        $userRoles = $this->authService->getUserRoles($user, $authContext);
        $rolesSlugs = $this->authService->getUserRoleSlugs($user, $context);
        
        // Получаем все права
        $permissions = $this->authService->getUserPermissionsStructured($user, $authContext);
        
        // Получаем доступные интерфейсы
        $availableInterfaces = $this->getAvailableInterfaces($user, $authContext);
        
        // Получаем активные модули (если в контексте организации)
        $activeModules = [];
        if ($organizationId) {
            $activeModules = $this->getActiveModules($organizationId);
        }
        
        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'context' => $context,
                
                // Роли пользователя
                'roles' => $rolesSlugs,
                'roles_detailed' => $userRoles->map(function($assignment) {
                    return [
                        'slug' => $assignment->role_slug,
                        'type' => $assignment->role_type,
                        'is_active' => $assignment->is_active,
                        'expires_at' => $assignment->expires_at,
                        'context_id' => $assignment->context_id,
                    ];
                })->toArray(),
                
                // Права пользователя
                'permissions' => [
                    'system' => array_values($permissions['system'] ?? []),
                    'modules' => $permissions['modules'] ?? []
                ],
                
                // Плоский список всех прав для удобства проверки на фронте
                'permissions_flat' => $this->flattenPermissions($permissions),
                
                // Доступные интерфейсы
                'interfaces' => $availableInterfaces,
                
                // Активные модули
                'active_modules' => $activeModules,
                
                // Метаданные для отладки
                'meta' => [
                    'checked_at' => now()->toISOString(),
                    'total_permissions' => count($this->flattenPermissions($permissions)),
                    'total_roles' => count($rolesSlugs)
                ]
            ]
        ]);
    }

    /**
     * Проверить конкретное право
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'permission' => 'required|string',
            'context' => 'sometimes|array',
            'interface' => 'sometimes|string'
        ]);
        
        $user = Auth::user();
        $permission = $request->input('permission');
        $context = $request->input('context');
        $interface = $request->input('interface');
        
        // Если контекст не передан, пробуем определить из организации
        if (!$context) {
            $organizationId = $this->getOrganizationId($request);
            $context = $organizationId ? ['organization_id' => $organizationId] : null;
        }
        
        $hasPermission = $this->authService->can($user, $permission, $context);
        
        $response = [
            'success' => true,
            'data' => [
                'has_permission' => $hasPermission,
                'permission' => $permission,
                'context' => $context,
                'user_id' => $user->id
            ]
        ];
        
        // Проверяем интерфейс, если передан
        if ($interface) {
            $authContext = $context && isset($context['organization_id']) 
                ? AuthorizationContext::getOrganizationContext($context['organization_id']) 
                : null;
                
            $hasInterfaceAccess = $this->authService->canAccessInterface($user, $interface, $authContext);
            $response['data']['has_interface_access'] = $hasInterfaceAccess;
        }
        
        return response()->json($response);
    }

    /**
     * Получить ID организации из запроса
     */
    protected function getOrganizationId(Request $request): ?int
    {
        // Из middleware SetOrganizationContext
        $organizationId = $request->attributes->get('current_organization_id');
        if ($organizationId) {
            return (int) $organizationId;
        }
        
        // Из текущего пользователя
        $user = Auth::user();
        if ($user && isset($user->current_organization_id)) {
            return (int) $user->current_organization_id;
        }
        
        // Из параметров запроса
        if ($request->has('organization_id')) {
            return (int) $request->input('organization_id');
        }
        
        return null;
    }

    /**
     * Получить доступные интерфейсы для пользователя
     */
    protected function getAvailableInterfaces($user, ?AuthorizationContext $context): array
    {
        $interfaces = [];
        
        // Проверяем доступ к каждому интерфейсу
        $allInterfaces = ['lk', 'admin', 'mobile'];
        
        foreach ($allInterfaces as $interface) {
            if ($this->authService->canAccessInterface($user, $interface, $context)) {
                $interfaces[] = $interface;
            }
        }
        
        return $interfaces;
    }

    /**
     * Получить активные модули для организации
     */
    protected function getActiveModules(int $organizationId): array
    {
        try {
            // Используем существующий AccessController
            $accessController = app(\App\Modules\Core\AccessController::class);
            $modules = $accessController->getActiveModules($organizationId);
            return is_array($modules) ? $modules : $modules->toArray();
        } catch (\Exception $e) {
            Log::warning('Failed to get active modules', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Преобразовать права в плоский список
     */
    protected function flattenPermissions(array $permissions): array
    {
        $flat = [];
        
        // Системные права
        if (isset($permissions['system'])) {
            $flat = array_merge($flat, $permissions['system']);
        }
        
        // Модульные права
        if (isset($permissions['modules'])) {
            foreach ($permissions['modules'] as $module => $modulePermissions) {
                $flat = array_merge($flat, $modulePermissions);
            }
        }
        
        return array_unique($flat);
    }
}
