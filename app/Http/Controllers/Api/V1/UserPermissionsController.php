<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RoleScanner;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Modules\Core\AccessController;
use App\Services\SubscriptionModuleSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

class UserPermissionsController extends Controller
{
    protected AuthorizationService $authService;
    protected RoleScanner $roleScanner;

    public function __construct(AuthorizationService $authService, RoleScanner $roleScanner)
    {
        $this->authService = $authService;
        $this->roleScanner = $roleScanner;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $organizationId = $this->getOrganizationId($request);

            if (!$user) {
                return AdminResponse::error(trans_message('permissions.unauthorized'), 401);
            }

            $this->ensureBundledModulesSynced($organizationId);

            $cacheKey = "user_permissions_full_effective_{$user->id}_{$organizationId}";
            $data = Cache::remember($cacheKey, 300, function () use ($user, $organizationId) {
                $context = $organizationId ? ['organization_id' => $organizationId] : null;
                $authContext = $organizationId ? AuthorizationContext::getOrganizationContext($organizationId) : null;

                $userRoles = $this->authService->getUserRoles($user, $authContext);
                $rolesSlugs = $this->authService->getUserRoleSlugs($user, $context);
                $permissions = $this->authService->getUserPermissionsStructured($user, $authContext);
                $availableInterfaces = $this->getAvailableInterfaces($user, $authContext);
                $activeModules = $organizationId ? $this->getActiveModules($organizationId) : [];
                $permissionsFlat = $this->flattenPermissions($permissions);

                return [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                    'context' => $context,
                    'roles' => $rolesSlugs,
                    'roles_detailed' => $userRoles->map(function ($assignment) {
                        return [
                            'slug' => $assignment->role_slug,
                            'type' => $assignment->role_type,
                            'is_active' => $assignment->is_active,
                            'expires_at' => $assignment->expires_at,
                            'context_id' => $assignment->context_id,
                        ];
                    })->toArray(),
                    'permissions' => [
                        'system' => array_values($permissions['system'] ?? []),
                        'modules' => $permissions['modules'] ?? [],
                    ],
                    'permissions_flat' => $permissionsFlat,
                    'interfaces' => $availableInterfaces,
                    'active_modules' => $activeModules,
                    'meta' => [
                        'checked_at' => now()->toISOString(),
                        'total_permissions' => count($permissionsFlat),
                        'total_roles' => count($rolesSlugs),
                    ],
                ];
            });

            return AdminResponse::success($data, trans_message('permissions.loaded'));
        } catch (Throwable $e) {
            Log::error('permissions.index.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $this->getOrganizationId($request),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('permissions.load_error'), 500);
        }
    }

    public function check(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'permission' => 'required|string',
                'context' => 'sometimes|array',
                'interface' => 'sometimes|string',
            ]);

            $user = Auth::user();
            if (!$user) {
                return AdminResponse::error(trans_message('permissions.unauthorized'), 401);
            }

            $permission = $validated['permission'];
            $context = $validated['context'] ?? null;
            $interface = $validated['interface'] ?? null;

            if (!$context) {
                $organizationId = $this->getOrganizationId($request);
                $context = $organizationId ? ['organization_id' => $organizationId] : null;
            }

            $payload = [
                'has_permission' => $this->authService->can($user, $permission, $context),
                'permission' => $permission,
                'context' => $context,
                'user_id' => $user->id,
            ];

            if ($interface) {
                $authContext = $context && isset($context['organization_id'])
                    ? AuthorizationContext::getOrganizationContext((int) $context['organization_id'])
                    : null;

                $payload['has_interface_access'] = $this->authService->canAccessInterface($user, $interface, $authContext);
            }

            return AdminResponse::success($payload);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('permissions.validation_error'), 422, $e->errors());
        } catch (Throwable $e) {
            Log::error('permissions.check.failed', [
                'user_id' => $request->user()?->id,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('permissions.check_error'), 500);
        }
    }

    protected function getOrganizationId(Request $request): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id');
        if ($organizationId) {
            return (int) $organizationId;
        }

        $user = Auth::user();
        if ($user && isset($user->current_organization_id)) {
            return (int) $user->current_organization_id;
        }

        if ($request->has('organization_id')) {
            return (int) $request->input('organization_id');
        }

        return null;
    }

    protected function getAvailableInterfaces($user, ?AuthorizationContext $context): array
    {
        $interfaces = [];
        $allInterfaces = ['lk', 'admin', 'mobile', 'customer'];

        foreach ($allInterfaces as $interface) {
            if ($this->authService->canAccessInterface($user, $interface, $context)) {
                $interfaces[] = $interface;
            }
        }

        return $interfaces;
    }

    protected function getActiveModules(int $organizationId): array
    {
        try {
            $accessController = app(AccessController::class);
            $modules = $accessController->getActiveModules($organizationId);

            if ($modules instanceof \Illuminate\Support\Collection) {
                return $modules->values()->toArray();
            }

            return is_array($modules) ? array_values($modules) : [];
        } catch (Throwable $e) {
            Log::warning('permissions.active_modules.failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function ensureBundledModulesSynced(?int $organizationId): void
    {
        if (!$organizationId) {
            return;
        }

        $cacheKey = "subscription_bundled_modules_synced_{$organizationId}";

        if (Cache::has($cacheKey)) {
            return;
        }

        try {
            $result = app(SubscriptionModuleSyncService::class)
                ->ensureBundledModulesSyncedForOrganization($organizationId);

            Cache::put($cacheKey, true, 300);

            if (
                ($result['activated_count'] ?? 0) > 0
                || ($result['converted_count'] ?? 0) > 0
                || ($result['packages_activated_count'] ?? 0) > 0
                || ($result['packages_converted_count'] ?? 0) > 0
            ) {
                app(AccessController::class)->clearAccessCache($organizationId);
            }
        } catch (Throwable $e) {
            Log::warning('permissions.subscription_modules_sync.failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function flattenPermissions(array $permissions): array
    {
        $flat = [];

        if (isset($permissions['system'])) {
            foreach ($permissions['system'] as $permission) {
                if ($this->isWildcardPermission($permission)) {
                    $flat = array_merge($flat, $this->expandWildcardPermission($permission));
                } else {
                    $flat[] = $permission;
                }
            }
        }

        if (isset($permissions['modules'])) {
            foreach ($permissions['modules'] as $module => $modulePermissions) {
                foreach ($modulePermissions as $permission) {
                    if ($permission === '*') {
                        $flat = array_merge($flat, $this->normalizePermissions($this->getModulePermissions($module)));
                    } else {
                        $normalizedPermission = $this->normalizePermission($permission);
                        if ($normalizedPermission !== null) {
                            $flat[] = $normalizedPermission;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($flat));
    }

    protected function normalizePermission($permission): ?string
    {
        if (is_string($permission)) {
            return $permission;
        }

        if (is_array($permission) && isset($permission['name'])) {
            return $permission['name'];
        }

        Log::warning('permissions.normalize_permission.unknown_format', ['permission' => $permission]);

        return null;
    }

    protected function normalizePermissions(array $permissions): array
    {
        $normalized = [];

        foreach ($permissions as $permission) {
            $normalizedPermission = $this->normalizePermission($permission);
            if ($normalizedPermission !== null) {
                $normalized[] = $normalizedPermission;
            }
        }

        return $normalized;
    }

    protected function getModulePermissions(string $moduleSlug): array
    {
        try {
            $module = \App\Models\Module::where('slug', $moduleSlug)->first();
            if ($module && $module->permissions) {
                return $this->normalizePermissions($module->permissions);
            }

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
                        return $config['permissions'];
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('permissions.module_permissions.failed', [
                'module_slug' => $moduleSlug,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    protected function isWildcardPermission(string $permission): bool
    {
        return str_contains($permission, '*');
    }

    protected function expandWildcardPermission(string $wildcardPermission): array
    {
        if ($wildcardPermission === 'admin.*') {
            return $this->getAllAdminPermissions();
        }

        return [$wildcardPermission];
    }

    protected function getAllAdminPermissions(): array
    {
        static $adminPermissions = null;

        if ($adminPermissions !== null) {
            return $adminPermissions;
        }

        $permissions = [];

        try {
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
        } catch (Throwable $e) {
            Log::warning('permissions.admin_permissions.failed', [
                'error' => $e->getMessage(),
            ]);
        }

        $adminPermissions = array_values(array_unique($permissions));
        sort($adminPermissions);

        return $adminPermissions;
    }
}
