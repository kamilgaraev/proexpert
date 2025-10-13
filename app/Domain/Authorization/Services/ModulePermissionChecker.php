<?php

namespace App\Domain\Authorization\Services;

use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\Cache;

/**
 * Сервис для проверки активации модулей и их прав
 * Интегрирован с существующей модульной системой
 */
class ModulePermissionChecker
{
    protected AccessController $accessController;

    public function __construct(AccessController $accessController)
    {
        $this->accessController = $accessController;
    }

    /**
     * Проверить, активирован ли модуль для организации
     * Использует существующую модульную систему
     */
    public function isModuleActive(string $moduleSlug, int $organizationId): bool
    {
        return $this->accessController->hasModuleAccess($organizationId, $moduleSlug);
    }

    /**
     * Получить все активные модули организации
     * Использует существующую модульную систему
     */
    public function getActiveModules(int $organizationId): array
    {
        return $this->accessController->getActiveModules($organizationId)->pluck('slug')->toArray();
    }

    /**
     * Проверить, есть ли право у модуля для организации
     * Использует существующую модульную систему
     */
    public function hasModulePermission(int $organizationId, string $permission): bool
    {
        return $this->accessController->hasModulePermission($organizationId, $permission);
    }

    /**
     * Проверить, есть ли у модуля указанное право (статическая проверка)
     */
    public function moduleHasPermission(string $moduleSlug, string $permission): bool
    {
        $module = $this->getModule($moduleSlug);
        
        if (!$module || !$module->is_active) {
            return false;
        }

        $permissions = $module->permissions ?? [];
        
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }

    /**
     * Получить все права модуля
     */
    public function getModulePermissions(string $moduleSlug): array
    {
        $module = $this->getModule($moduleSlug);
        
        return $module ? ($module->permissions ?? []) : [];
    }

    /**
     * Проверить, может ли организация активировать модуль
     */
    public function canActivateModule(int $organizationId, string $moduleSlug): bool
    {
        $module = $this->getModule($moduleSlug);
        
        if (!$module || !$module->is_active) {
            return false;
        }

        // Проверяем, не активирован ли уже
        if ($this->isModuleActive($moduleSlug, $organizationId)) {
            return false;
        }

        // Проверяем зависимости модуля
        $dependencies = $module->dependencies ?? [];
        foreach ($dependencies as $dependency) {
            if (!$this->isModuleActive($dependency, $organizationId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Активировать модуль для организации
     */
    public function activateModule(int $organizationId, string $moduleSlug): bool
    {
        $module = $this->getModule($moduleSlug);
        
        if (!$module || !$this->canActivateModule($organizationId, $moduleSlug)) {
            return false;
        }

        $activation = OrganizationModuleActivation::firstOrNew([
            'organization_id' => $organizationId,
            'module_id' => $module->id
        ]);

        $activation->status = 'active';
        $activation->activated_at = now();
        
        $result = $activation->save();
        
        // Очищаем кеш
        $this->clearModuleCache($organizationId, $moduleSlug);
        
        return $result;
    }

    /**
     * Деактивировать модуль для организации
     */
    public function deactivateModule(int $organizationId, string $moduleSlug): bool
    {
        $module = $this->getModule($moduleSlug);
        
        if (!$module) {
            return false;
        }

        $activation = OrganizationModuleActivation::where([
            'organization_id' => $organizationId,
            'module_id' => $module->id
        ])->first();

        if (!$activation) {
            return false;
        }

        // Проверяем, не требуют ли этот модуль другие активированные модули
        if ($this->isRequiredByOtherModules($organizationId, $moduleSlug)) {
            return false;
        }

        $result = $activation->update([
            'status' => 'inactive',
            'deactivated_at' => now()
        ]);

        // Очищаем кеш
        $this->clearModuleCache($organizationId, $moduleSlug);
        
        return $result;
    }

    /**
     * Получить статус модуля для организации
     */
    public function getModuleStatus(int $organizationId, string $moduleSlug): string
    {
        $activation = OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) use ($moduleSlug) {
                $query->where('slug', $moduleSlug);
            })
            ->first();

        return $activation ? $activation->status : 'not_activated';
    }

    /**
     * Получить все доступные модули
     */
    public function getAvailableModules(): array
    {
        $cacheKey = 'available_modules';
        
        return Cache::remember($cacheKey, 3600, function () {
            return Module::where('is_active', true)
                ->select(['id', 'name', 'slug', 'description', 'permissions', 'dependencies'])
                ->get()
                ->keyBy('slug')
                ->toArray();
        });
    }

    /**
     * Проверить права модуля против списка разрешенных
     */
    public function validateModulePermissions(string $moduleSlug, array $requiredPermissions): bool
    {
        $modulePermissions = $this->getModulePermissions($moduleSlug);
        
        foreach ($requiredPermissions as $permission) {
            if (!in_array($permission, $modulePermissions) && !in_array('*', $modulePermissions)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Очистить кеш модулей
     */
    public function clearModuleCache(?int $organizationId = null, ?string $moduleSlug = null): void
    {
        if ($organizationId && $moduleSlug) {
            Cache::forget("module_active_{$moduleSlug}_{$organizationId}");
        }
        
        if ($organizationId) {
            Cache::forget("org_active_modules_$organizationId");
            Cache::forget("modules_with_status_{$organizationId}");  // Кеш списка модулей для UI
        }
        
        Cache::forget('available_modules');
    }

    /**
     * Получить модуль с кешированием
     */
    protected function getModule(string $moduleSlug): ?Module
    {
        $cacheKey = "module_$moduleSlug";
        
        return Cache::remember($cacheKey, 3600, function () use ($moduleSlug) {
            return Module::where('slug', $moduleSlug)->first();
        });
    }

    /**
     * Проверить, требуется ли модуль другими активированными модулями
     */
    protected function isRequiredByOtherModules(int $organizationId, string $moduleSlug): bool
    {
        $activeModules = $this->getActiveModules($organizationId);
        
        foreach ($activeModules as $activeModuleSlug) {
            if ($activeModuleSlug === $moduleSlug) {
                continue;
            }
            
            $module = $this->getModule($activeModuleSlug);
            $dependencies = $module->dependencies ?? [];
            
            if (in_array($moduleSlug, $dependencies)) {
                return true;
            }
        }
        
        return false;
    }
}
