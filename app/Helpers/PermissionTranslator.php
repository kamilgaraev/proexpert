<?php

namespace App\Helpers;

class PermissionTranslator
{
    public static function translateSystemPermissions(array $permissions): array
    {
        $translations = [];
        
        foreach ($permissions as $permission => $description) {
            $key = is_numeric($permission) ? $description : $permission;
            $translations[$key] = __("permissions.system.{$key}", [], 'ru');
        }
        
        return $translations;
    }

    public static function translateModulePermissions(array $modulePermissions): array
    {
        $translations = [];
        
        foreach ($modulePermissions as $module => $permissions) {
            $translations[$module] = [
                'module_name' => __("permissions.groups.{$module}", [], 'ru'),
                'permissions' => []
            ];
            
            foreach ($permissions as $permission) {
                $translations[$module]['permissions'][$permission] = 
                    __("permissions.modules.{$module}.{$permission}", [], 'ru');
            }
        }
        
        return $translations;
    }

    public static function translateInterfaceAccess(array $interfaces): array
    {
        $translations = [];
        
        foreach ($interfaces as $interface => $description) {
            $key = is_numeric($interface) ? $description : $interface;
            $translations[$key] = __("permissions.interfaces.{$key}", [], 'ru');
        }
        
        return $translations;
    }

    public static function translatePermissionsData(array $data): array
    {
        $result = [];
        
        if (isset($data['system_permissions'])) {
            $result['system_permissions'] = self::translateSystemPermissions($data['system_permissions']);
        }
        
        if (isset($data['module_permissions'])) {
            $result['module_permissions'] = self::translateModulePermissions($data['module_permissions']);
        }
        
        if (isset($data['interface_access'])) {
            $result['interface_access'] = self::translateInterfaceAccess($data['interface_access']);
        }
        
        return $result;
    }

    public static function getPermissionTranslation(string $permission): string
    {
        $parts = explode('.', $permission);
        
        if (count($parts) === 2) {
            [$group, $action] = $parts;
            
            $systemTranslation = __("permissions.system.{$permission}", [], 'ru');
            if ($systemTranslation !== "permissions.system.{$permission}") {
                return $systemTranslation;
            }
            
            $moduleTranslation = __("permissions.modules.{$group}.{$permission}", [], 'ru');
            if ($moduleTranslation !== "permissions.modules.{$group}.{$permission}") {
                return $moduleTranslation;
            }
        }
        
        return $permission;
    }

    public static function getGroupTranslation(string $group): string
    {
        return __("permissions.groups.{$group}", [], 'ru');
    }
}
