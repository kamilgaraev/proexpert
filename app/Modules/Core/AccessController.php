<?php

namespace App\Modules\Core;

use App\Models\Organization;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AccessController 
{
    public function hasModuleAccess(int $organizationId, string $moduleSlug): bool
    {
        $cacheKey = "org_module_access_{$organizationId}_{$moduleSlug}";
        
        return Cache::remember($cacheKey, 300, function () use ($organizationId, $moduleSlug) {
            $module = Module::where('slug', $moduleSlug)
                ->where('is_active', true)
                ->first();
                
            if (!$module) {
                return false;
            }
            
            // Проверяем активацию модуля для организации
            $activation = OrganizationModuleActivation::where('organization_id', $organizationId)
                ->where('module_id', $module->id)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->first();
                
            return $activation !== null;
        });
    }
    
    public function hasModulePermission(int $organizationId, string $permission): bool
    {
        $cacheKey = "org_module_permission_{$organizationId}_{$permission}";
        
        return Cache::remember($cacheKey, 300, function () use ($organizationId, $permission) {
            // Ищем модули с такими правами среди активированных
            $hasPermission = Module::where('is_active', true)
                ->whereJsonContains('permissions', $permission)
                ->whereHas('activations', function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->where('status', 'active')
                        ->where(function ($subQuery) {
                            $subQuery->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                        });
                })
                ->exists();
                
            return $hasPermission;
        });
    }
    
    public function canUserAccessModule(User $user, string $moduleSlug): bool
    {
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return false;
        }
        
        return $this->hasModuleAccess($organizationId, $moduleSlug);
    }
    
    public function canUserUsePermission(User $user, string $permission): bool
    {
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return false;
        }
        
        return $this->hasModulePermission($organizationId, $permission);
    }
    
    public function getActiveModules(int $organizationId): \Illuminate\Support\Collection
    {
        $cacheKey = "org_active_modules_{$organizationId}";
        
        return Cache::remember($cacheKey, 300, function () use ($organizationId) {
            return OrganizationModuleActivation::with('module')
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->whereHas('module', function ($query) {
                    $query->where('is_active', true);
                })
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->get()
                ->pluck('module');
        });
    }
    
    public function getUserAvailablePermissions(User $user): array
    {
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return [];
        }
        
        $cacheKey = "user_available_permissions_{$user->id}_{$organizationId}";
        
        return Cache::remember($cacheKey, 300, function () use ($organizationId) {
            $activeModules = $this->getActiveModules($organizationId);
            
            $permissions = [];
            foreach ($activeModules as $module) {
                if ($module && $module->permissions) {
                    $permissions = array_merge($permissions, $module->permissions);
                }
            }
            
            return array_unique($permissions);
        });
    }
    
    public function checkDependencies(int $organizationId, Module $module): array
    {
        $dependencies = $module->dependencies ?? [];
        $missing = [];
        
        foreach ($dependencies as $dependencySlug) {
            if (!$this->hasModuleAccess($organizationId, $dependencySlug)) {
                $missing[] = $dependencySlug;
            }
        }
        
        return $missing;
    }
    
    public function checkConflicts(int $organizationId, Module $module): array
    {
        $conflicts = $module->conflicts ?? [];
        $found = [];
        
        foreach ($conflicts as $conflictSlug) {
            if ($this->hasModuleAccess($organizationId, $conflictSlug)) {
                $found[] = $conflictSlug;
            }
        }
        
        return $found;
    }
    
    public function clearAccessCache(int $organizationId): void
    {
        $patterns = [
            "org_module_access_{$organizationId}_*",
            "org_module_permission_{$organizationId}_*",
            "org_active_modules_{$organizationId}",
            "user_available_permissions_*_{$organizationId}"
        ];
        
        foreach ($patterns as $pattern) {
            Cache::forget($pattern);
        }
    }
}
