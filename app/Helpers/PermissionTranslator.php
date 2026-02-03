<?php

namespace App\Helpers;

class PermissionTranslator
{
    public static function translateSystemPermissions(array $permissions): array
    {
        $translations = [];
        
        foreach ($permissions as $permission => $description) {
            $key = is_numeric($permission) ? $description : $permission;
            $parts = explode('.', $key);
            if (count($parts) === 2) {
                [$group, $action] = $parts;
                $translations[$key] = __("permissions.system.{$group}.{$action}", [], 'ru');
            } else {
                $translations[$key] = __("permissions.system.{$key}", [], 'ru');
            }
        }
        
        return $translations;
    }

    public static function translateModulePermissions(array $modulePermissions): array
    {
        $translations = [];
        
        foreach ($modulePermissions as $module => $permissions) {
            $translations[$module] = [];
            
            $normalizedModule = str_replace('-', '_', $module);
            
            foreach ($permissions as $permission) {
                // 1. Пробуем полный ключ (permissions.modules.{module}.{permission})
                $fullKey = "permissions.modules.{$normalizedModule}.{$permission}";
                $translation = __($fullKey, [], 'ru');
                
                if ($translation !== $fullKey) {
                    $translations[$module][$permission] = $translation;
                    continue;
                }

                // 2. Пробуем старый метод (permissions.modules.{module}.{last_part})
                $permParts = explode('.', $permission);
                // ожидаем формат: {module}.{action}
                $action = end($permParts);
                $translationKey = "permissions.modules.{$normalizedModule}.{$action}";
                $translation = __($translationKey, [], 'ru');
                
                if ($translation === $translationKey) {
                    $translation = self::getPermissionTranslation($permission);
                }
                
                $translations[$module][$permission] = $translation;
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

            // Добавляем переводы заголовков групп модулей
            $result['module_groups'] = [];
            foreach (array_keys($data['module_permissions']) as $module) {
                $normalized = str_replace('-', '_', $module);
                $result['module_groups'][$module] = __("permissions.groups.{$normalized}", [], 'ru');
            }
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
            
            $systemTranslation = __("permissions.system.{$group}.{$action}", [], 'ru');
            if ($systemTranslation !== "permissions.system.{$group}.{$action}") {
                return $systemTranslation;
            }
            
            $normalizedGroup = str_replace('-', '_', $group);
            $moduleTranslation = __("permissions.modules.{$normalizedGroup}.{$action}", [], 'ru');
            if ($moduleTranslation !== "permissions.modules.{$normalizedGroup}.{$action}") {
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
