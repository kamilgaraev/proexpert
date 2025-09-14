<?php

namespace App\Domain\Authorization\Services;

use App\Domain\Authorization\Services\RoleScanner;
use App\Models\Module;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class RoleUpdater
{
    protected RoleScanner $roleScanner;

    public function __construct(RoleScanner $roleScanner)
    {
        $this->roleScanner = $roleScanner;
    }

    public function updateRolesForModule(string $moduleSlug): bool
    {
        $module = Module::where('slug', $moduleSlug)->first();
        
        if (!$module) {
            Log::warning("Модуль {$moduleSlug} не найден при обновлении ролей");
            return false;
        }

        $moduleConfig = $this->getModuleConfig($moduleSlug);
        if (!$moduleConfig || empty($moduleConfig['permissions'])) {
            Log::info("У модуля {$moduleSlug} нет прав для добавления в роли");
            return true;
        }

        $modulePermissions = $moduleConfig['permissions'];
        $rolesUpdated = false;

        $roleDefinitions = $this->roleScanner->getAllRoles();
        
        foreach ($roleDefinitions as $roleSlug => $roleData) {
            if ($this->shouldUpdateRoleForModule($roleData, $moduleSlug)) {
                if ($this->addModulePermissionsToRole($roleSlug, $moduleSlug, $modulePermissions)) {
                    $rolesUpdated = true;
                    Log::info("Обновлена роль {$roleSlug} правами модуля {$moduleSlug}");
                }
            }
        }

        if ($rolesUpdated) {
            $this->roleScanner->clearCache();
            // Очищаем кеш авторизации для всех пользователей
            Cache::flush();
        }

        return true;
    }

    public function removeRolesForModule(string $moduleSlug): bool
    {
        $rolesUpdated = false;
        $roleDefinitions = $this->roleScanner->getAllRoles();
        
        foreach ($roleDefinitions as $roleSlug => $roleData) {
            if ($this->removeModulePermissionsFromRole($roleSlug, $moduleSlug)) {
                $rolesUpdated = true;
                Log::info("Удалены права модуля {$moduleSlug} из роли {$roleSlug}");
            }
        }

        if ($rolesUpdated) {
            $this->roleScanner->clearCache();
            // Очищаем кеш авторизации для всех пользователей
            Cache::flush();
        }

        return true;
    }

    protected function shouldUpdateRoleForModule(array $roleData, string $moduleSlug): bool
    {
        $context = $roleData['context'] ?? '';
        
        if ($context === 'system') {
            return false;
        }

        if ($moduleSlug === 'multi-organization') {
            return in_array($roleData['slug'] ?? '', ['organization_owner', 'organization_admin']);
        }

        return $context === 'organization';
    }

    protected function addModulePermissionsToRole(string $roleSlug, string $moduleSlug, array $permissions): bool
    {
        $rolePath = $this->getRolePath($roleSlug);
        if (!$rolePath || !File::exists($rolePath)) {
            return false;
        }

        $roleContent = File::get($rolePath);
        $roleData = json_decode($roleContent, true);
        
        if (!$roleData) {
            return false;
        }

        if (!isset($roleData['module_permissions'])) {
            $roleData['module_permissions'] = [];
        }

        $permissionsToAdd = $this->filterPermissionsForRole($roleData['slug'], $permissions);
        
        if (empty($permissionsToAdd)) {
            return false;
        }

        $roleData['module_permissions'][$moduleSlug] = $permissionsToAdd;

        return File::put($rolePath, json_encode($roleData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function removeModulePermissionsFromRole(string $roleSlug, string $moduleSlug): bool
    {
        $rolePath = $this->getRolePath($roleSlug);
        if (!$rolePath || !File::exists($rolePath)) {
            return false;
        }

        $roleContent = File::get($rolePath);
        $roleData = json_decode($roleContent, true);
        
        if (!$roleData || !isset($roleData['module_permissions'][$moduleSlug])) {
            return false;
        }

        unset($roleData['module_permissions'][$moduleSlug]);

        return File::put($rolePath, json_encode($roleData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function filterPermissionsForRole(string $roleSlug, array $permissions): array
    {
        switch ($roleSlug) {
            case 'organization_owner':
                return array_map(function($permission) {
                    return str_replace('.manage', '.*', $permission);
                }, $permissions);
                
            case 'organization_admin':
                return array_filter($permissions, function($permission) {
                    return !str_contains($permission, '.delete') && !str_contains($permission, '.billing');
                });
                
            case 'viewer':
                return array_filter($permissions, function($permission) {
                    return str_contains($permission, '.view') || str_contains($permission, '.dashboard');
                });
                
            default:
                return [];
        }
    }

    protected function getModuleConfig(string $moduleSlug): ?array
    {
        $configPaths = [
            base_path("config/ModuleList/core/{$moduleSlug}.json"),
            base_path("config/ModuleList/premium/{$moduleSlug}.json"),
            base_path("config/ModuleList/enterprise/{$moduleSlug}.json"),
        ];

        foreach ($configPaths as $path) {
            if (File::exists($path)) {
                $content = File::get($path);
                return json_decode($content, true);
            }
        }

        return null;
    }

    protected function getRolePath(string $roleSlug): ?string
    {
        $interfaces = ['lk', 'admin', 'mobile'];
        
        foreach ($interfaces as $interface) {
            $path = base_path("config/RoleDefinitions/{$interface}/{$roleSlug}.json");
            if (File::exists($path)) {
                return $path;
            }
        }

        return null;
    }
}
