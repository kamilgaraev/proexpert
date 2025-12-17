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
        
        // КРИТИЧНО: Кешируем права пользователя на 5 минут для избежания медленных запросов
        $cacheKey = "user_permissions_full_{$user->id}_{$organizationId}";
        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($user, $organizationId) {
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
            
            return [
                'user_roles' => $userRoles,
                'roles_slugs' => $rolesSlugs,
                'permissions' => $permissions,
                'available_interfaces' => $availableInterfaces,
                'active_modules' => $activeModules,
                'organization_id' => $organizationId,
                'user_id' => $user->id
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $data['user_id'],
                'organization_id' => $data['organization_id'],
                'context' => $organizationId ? ['organization_id' => $organizationId] : null,
                
                // Роли пользователя
                'roles' => $data['roles_slugs'],
                'roles_detailed' => $data['user_roles']->map(function($assignment) {
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
                    'system' => array_values($data['permissions']['system'] ?? []),
                    'modules' => $data['permissions']['modules'] ?? []
                ],
                
                // Плоский список всех прав для удобства проверки на фронте
                'permissions_flat' => $this->flattenPermissions($data['permissions']),
                
                // Доступные интерфейсы
                'interfaces' => $data['available_interfaces'],
                
                // Активные модули
                'active_modules' => $data['active_modules'],
                
                // Метаданные для отладки
                'meta' => [
                    'checked_at' => now()->toISOString(),
                    'total_permissions' => count($this->flattenPermissions($data['permissions'])),
                    'total_roles' => count($data['roles_slugs'])
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
            
            // ВАЖНО: values() сбрасывает ключи массива, чтобы JSON вернулся как массив [], а не объект {}
            if ($modules instanceof \Illuminate\Support\Collection) {
                return $modules->values()->toArray();
            }
            
            return is_array($modules) ? array_values($modules) : [];
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
            foreach ($permissions['system'] as $permission) {
                if ($this->isWildcardPermission($permission)) {
                    // Разворачиваем wildcard в конкретные права
                    $expandedPermissions = $this->expandWildcardPermission($permission);
                    $flat = array_merge($flat, $expandedPermissions);
                } else {
                    $flat[] = $permission;
                }
            }
        }
        
        // Модульные права
        if (isset($permissions['modules'])) {
            foreach ($permissions['modules'] as $module => $modulePermissions) {
                foreach ($modulePermissions as $permission) {
                    if ($permission === '*') {
                        // Разворачиваем wildcard в конкретные права модуля
                        $moduleSpecificPermissions = $this->getModulePermissions($module);
                        $flat = array_merge($flat, $moduleSpecificPermissions);
                    } else {
                        $flat[] = $permission;
                    }
                }
            }
        }
        
        return array_unique($flat);
    }

    /**
     * Получить список прав модуля из конфигурации
     */
    protected function getModulePermissions(string $moduleSlug): array
    {
        try {
            // Сначала пытаемся получить права из базы данных (наиболее надежный способ)
            $module = \App\Models\Module::where('slug', $moduleSlug)->first();
            if ($module && $module->permissions) {
                Log::debug("Права модуля {$moduleSlug} загружены из БД", [
                    'count' => count($module->permissions),
                    'permissions' => $module->permissions
                ]);
                return $module->permissions;
            }

            // Если в БД нет, ищем в файлах конфигурации
            // Рекурсивно ищем во всех подпапках ModuleList
            $configPath = config_path('ModuleList');
            if (is_dir($configPath)) {
                $finder = new \Symfony\Component\Finder\Finder();
                $finder->files()
                    ->name("{$moduleSlug}.json")
                    ->in($configPath);

                foreach ($finder as $file) {
                    $config = json_decode($file->getContents(), true);
                    if ($config && isset($config['permissions'])) {
                        return $config['permissions'];
                    }
                }
            }

            // Fallback: проверяем стандартные пути для обратной совместимости
            $configPaths = [
                base_path("config/ModuleList/core/{$moduleSlug}.json"),
                base_path("config/ModuleList/premium/{$moduleSlug}.json"),
                base_path("config/ModuleList/enterprise/{$moduleSlug}.json"),
                base_path("config/ModuleList/features/{$moduleSlug}.json"),
                base_path("config/ModuleList/addons/{$moduleSlug}.json"),
                base_path("config/ModuleList/services/{$moduleSlug}.json"),
            ];

            foreach ($configPaths as $path) {
                if (file_exists($path)) {
                    $config = json_decode(file_get_contents($path), true);
                    if ($config && isset($config['permissions'])) {
                        Log::debug("Права модуля {$moduleSlug} загружены из файла", [
                            'path' => $path,
                            'count' => count($config['permissions'])
                        ]);
                        return $config['permissions'];
                    }
                }
            }

            Log::warning("Права модуля {$moduleSlug} не найдены ни в БД, ни в файлах конфигурации");
        } catch (\Exception $e) {
            Log::warning("Не удалось загрузить права модуля {$moduleSlug}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Проверить является ли право wildcard
     */
    protected function isWildcardPermission(string $permission): bool
    {
        return str_contains($permission, '*');
    }

    /**
     * Развернуть wildcard право в список конкретных прав
     */
    protected function expandWildcardPermission(string $wildcardPermission): array
    {
        // Если это admin.*, собираем все admin права из всех ролей
        if ($wildcardPermission === 'admin.*') {
            return $this->getAllAdminPermissions();
        }

        // Для других wildcards можно добавить логику позже
        return [$wildcardPermission];
    }

    /**
     * Получить все admin права из всех ролей в системе
     */
    protected function getAllAdminPermissions(): array
    {
        static $adminPermissions = null;
        
        if ($adminPermissions !== null) {
            return $adminPermissions;
        }
        
        $permissions = [];
        
        try {
            // Сканируем все роли и собираем admin права
            $roleDirectories = [
                base_path('config/RoleDefinitions/admin'),
                base_path('config/RoleDefinitions/system'),
                base_path('config/RoleDefinitions/lk'),
            ];

            foreach ($roleDirectories as $directory) {
                if (!is_dir($directory)) {
                    continue;
                }

                $files = glob($directory . '/*.json');
                foreach ($files as $file) {
                    $roleData = json_decode(file_get_contents($file), true);
                    if (!$roleData || !isset($roleData['system_permissions'])) {
                        continue;
                    }

                    foreach ($roleData['system_permissions'] as $permission) {
                        if (str_starts_with($permission, 'admin.') && !str_contains($permission, '*')) {
                            $permissions[] = $permission;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Ошибка при сборе admin permissions: ' . $e->getMessage());
        }

        $adminPermissions = array_unique($permissions);
        sort($adminPermissions);
        
        Log::info('Собранные admin permissions для wildcard разворота', [
            'count' => count($adminPermissions),
            'permissions' => $adminPermissions
        ]);
        
        return $adminPermissions;
    }
}
