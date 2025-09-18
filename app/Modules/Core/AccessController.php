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
            
            // Системные модули (can_deactivate: false) всегда доступны
            if (!$module->can_deactivate) {
                return true;
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
            // Проверяем системные модули (can_deactivate: false) - их права всегда доступны
            $systemModulePermission = Module::where('is_active', true)
                ->where('can_deactivate', false)
                ->whereJsonContains('permissions', $permission)
                ->exists();
                
            if ($systemModulePermission) {
                return true;
            }
            
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
            // Получаем активированные модули
            $activatedModules = OrganizationModuleActivation::with('module')
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
                
            // Добавляем системные модули (can_deactivate: false)
            $systemModules = Module::where('is_active', true)
                ->where('can_deactivate', false)
                ->get();
                
            return $activatedModules->merge($systemModules)->unique('id');
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
        // Очищаем конкретные ключи без wildcards
        $specificKeys = [
            "org_active_modules_{$organizationId}"
        ];
        
        foreach ($specificKeys as $key) {
            Cache::forget($key);
        }
        
        // Для wildcard паттернов используем теги или полную очистку
        try {
            // Пытаемся использовать tags если поддерживаются
            Cache::tags(['module_access', "org_{$organizationId}"])->flush();
        } catch (\Exception $e) {
            // Fallback - очищаем весь кэш для критических модулей
            $this->clearWildcardCache("org_module_access_{$organizationId}_");
            $this->clearWildcardCache("org_module_permission_{$organizationId}_");
            $this->clearWildcardCache("user_available_permissions_", "_{$organizationId}");
        }
    }
    
    private function clearWildcardCache(string $prefix, string $suffix = ''): void
    {
        try {
            // Для Redis - используем scan
            if (Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
                $redis = Cache::getStore()->connection();
                $keys = $redis->keys($prefix . '*' . $suffix);
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            } else {
                // Для других драйверов - ничего не делаем, cache:clear покроет
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки очистки кэша
        }
    }
}
