<?php

namespace App\Modules\Services;

use App\Models\User;
use App\Models\Module;
use App\Modules\Core\AccessController;
use Illuminate\Support\Collection;

class ModulePermissionService
{
    protected AccessController $accessController;
    
    public function __construct(AccessController $accessController)
    {
        $this->accessController = $accessController;
    }
    
    public function userHasModuleAccess(User $user, string $moduleSlug): bool
    {
        return $this->accessController->canUserAccessModule($user, $moduleSlug);
    }
    
    public function userHasPermission(User $user, string $permission): bool
    {
        return $this->accessController->canUserUsePermission($user, $permission);
    }
    
    public function getUserAvailablePermissions(User $user): array
    {
        return $this->accessController->getUserAvailablePermissions($user);
    }
    
    public function getUserActiveModules(User $user): Collection
    {
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return collect();
        }
        
        return $this->accessController->getActiveModules($organizationId);
    }
    
    public function checkUserPermissions(User $user, array $permissions): array
    {
        $results = [];
        $availablePermissions = $this->getUserAvailablePermissions($user);
        
        foreach ($permissions as $permission) {
            $results[$permission] = in_array($permission, $availablePermissions);
        }
        
        return $results;
    }
    
    public function getPermissionsByModule(int $organizationId): array
    {
        $activeModules = $this->accessController->getActiveModules($organizationId);
        $permissionsByModule = [];
        
        foreach ($activeModules as $module) {
            $permissionsByModule[$module->slug] = [
                'module_name' => $module->name,
                'permissions' => $module->permissions ?? []
            ];
        }
        
        return $permissionsByModule;
    }
    
    public function getModulesByPermission(string $permission): Collection
    {
        return Module::where('is_active', true)
            ->whereJsonContains('permissions', $permission)
            ->get();
    }
    
    public function getAllAvailablePermissions(): array
    {
        $modules = Module::where('is_active', true)->get();
        $allPermissions = [];
        
        foreach ($modules as $module) {
            if ($module->permissions) {
                foreach ($module->permissions as $permission) {
                    if (!in_array($permission, $allPermissions)) {
                        $allPermissions[] = $permission;
                    }
                }
            }
        }
        
        sort($allPermissions);
        return $allPermissions;
    }
    
    public function getPermissionDetails(string $permission): array
    {
        $modules = $this->getModulesByPermission($permission);
        
        return [
            'permission' => $permission,
            'provided_by_modules' => $modules->map(function ($module) {
                return [
                    'slug' => $module->slug,
                    'name' => $module->name,
                    'type' => $module->type,
                    'billing_model' => $module->billing_model,
                    'price' => $module->getPrice()
                ];
            })->toArray(),
            'is_free' => $modules->some(function ($module) {
                return $module->isFree();
            })
        ];
    }
    
    public function getUserModulePermissionMatrix(User $user): array
    {
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return [];
        }
        
        $activeModules = $this->getUserActiveModules($user);
        $matrix = [];
        
        foreach ($activeModules as $module) {
            $matrix[$module->slug] = [
                'module_name' => $module->name,
                'module_type' => $module->type,
                'has_access' => true,
                'permissions' => $module->permissions ?? [],
                'features' => $module->features ?? []
            ];
        }
        
        return $matrix;
    }
    
    public function requiresModuleAccess(string $moduleSlug): \Closure
    {
        return function () use ($moduleSlug) {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходима авторизация'
                ], 401);
            }
            
            if (!$this->userHasModuleAccess($user, $moduleSlug)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Доступ к модулю запрещен',
                    'required_module' => $moduleSlug
                ], 403);
            }
            
            return null; // Доступ разрешен
        };
    }
    
    public function requiresPermission(string $permission): \Closure
    {
        return function () use ($permission) {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Необходима авторизация'
                ], 401);
            }
            
            if (!$this->userHasPermission($user, $permission)) {
                $permissionDetails = $this->getPermissionDetails($permission);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Недостаточно прав доступа',
                    'required_permission' => $permission,
                    'available_in_modules' => $permissionDetails['provided_by_modules']
                ], 403);
            }
            
            return null; // Доступ разрешен
        };
    }
    
    public function getOrganizationPermissionSummary(int $organizationId): array
    {
        $activeModules = $this->accessController->getActiveModules($organizationId);
        $allPermissions = [];
        $moduleBreakdown = [];
        
        foreach ($activeModules as $module) {
            $modulePermissions = $module->permissions ?? [];
            $allPermissions = array_merge($allPermissions, $modulePermissions);
            
            $moduleBreakdown[] = [
                'module_name' => $module->name,
                'module_slug' => $module->slug,
                'permissions_count' => count($modulePermissions),
                'permissions' => $modulePermissions
            ];
        }
        
        $allPermissions = array_unique($allPermissions);
        
        return [
            'summary' => [
                'active_modules' => $activeModules->count(),
                'total_permissions' => count($allPermissions),
                'free_modules' => $activeModules->where('billing_model', 'free')->count(),
                'paid_modules' => $activeModules->where('billing_model', '!=', 'free')->count()
            ],
            'all_permissions' => array_values($allPermissions),
            'modules_breakdown' => $moduleBreakdown
        ];
    }
}
