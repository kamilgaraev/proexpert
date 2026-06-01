<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Support\Facades\Lang;

class PermissionTranslator
{
    public static function translateSystemPermissions(array $permissions): array
    {
        $translations = [];

        foreach ($permissions as $permission => $description) {
            $key = is_numeric($permission) ? $description : $permission;

            if (!is_string($key)) {
                continue;
            }

            $fallback = is_string($description) ? $description : null;
            $translations[$key] = self::getPermissionTranslation($key, null, $fallback);
        }

        return $translations;
    }

    public static function translateModulePermissions(array $modulePermissions): array
    {
        $translations = [];

        foreach ($modulePermissions as $module => $permissions) {
            $translations[$module] = [];

            if (!is_array($permissions)) {
                continue;
            }

            foreach ($permissions as $permission) {
                if (!is_string($permission)) {
                    continue;
                }

                $translations[$module][$permission] = self::getPermissionTranslation($permission, (string) $module);
            }
        }

        return $translations;
    }

    public static function translateInterfaceAccess(array $interfaces): array
    {
        $translations = [];

        foreach ($interfaces as $interface => $description) {
            $key = is_numeric($interface) ? $description : $interface;

            if (!is_string($key)) {
                continue;
            }

            $translation = self::dictionaryValue('interfaces', $key);
            $translations[$key] = $translation ?? self::cleanFallback(
                is_string($description) ? $description : null,
                $key
            ) ?? self::unknownInterfaceLabel();
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
            $result['module_groups'] = [];

            foreach (array_keys($data['module_permissions']) as $module) {
                $result['module_groups'][$module] = self::getGroupTranslation((string) $module);
            }
        }

        if (isset($data['interface_access'])) {
            $result['interface_access'] = self::translateInterfaceAccess($data['interface_access']);
        }

        return $result;
    }

    public static function getPermissionTranslation(
        string $permission,
        ?string $module = null,
        ?string $fallback = null
    ): string {
        $fallbackLabel = self::cleanFallback($fallback, $permission);

        return self::dictionaryValue('values', $permission)
            ?? self::modulePermissionTranslation($permission, $module)
            ?? self::systemPermissionTranslation($permission)
            ?? $fallbackLabel
            ?? self::buildReadablePermissionLabel($permission);
    }

    public static function getGroupTranslation(string $group): string
    {
        $normalizedGroup = str_replace('-', '_', $group);

        return self::dictionaryValue('groups', $normalizedGroup)
            ?? self::dictionaryValue('groups', $group)
            ?? self::unknownGroupLabel();
    }

    private static function modulePermissionTranslation(string $permission, ?string $module): ?string
    {
        if (!$module) {
            return null;
        }

        $normalizedModule = str_replace('-', '_', $module);
        $moduleTranslations = self::dictionary('modules');
        $moduleValues = $moduleTranslations[$normalizedModule] ?? $moduleTranslations[$module] ?? null;

        if (is_array($moduleValues) && isset($moduleValues[$permission]) && is_string($moduleValues[$permission])) {
            return $moduleValues[$permission];
        }

        return null;
    }

    private static function systemPermissionTranslation(string $permission): ?string
    {
        $parts = explode('.', $permission);

        if (count($parts) !== 2) {
            return null;
        }

        [$group, $action] = $parts;
        $systemTranslations = self::dictionary('system');
        $groupTranslations = $systemTranslations[$group] ?? null;

        if (is_array($groupTranslations) && isset($groupTranslations[$action]) && is_string($groupTranslations[$action])) {
            return $groupTranslations[$action];
        }

        return null;
    }

    private static function buildReadablePermissionLabel(string $permission): string
    {
        $subject = self::findSubject($permission);
        $action = self::findAction($permission, $subject['key'] ?? null);

        if ($subject['label'] && $action) {
            return "{$subject['label']}: {$action}";
        }

        if ($subject['label']) {
            return $subject['label'];
        }

        if ($action) {
            return "Право доступа: {$action}";
        }

        return 'Дополнительное право доступа';
    }

    private static function findSubject(string $permission): array
    {
        $subjects = self::dictionary('subjects');
        uksort($subjects, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($subjects as $key => $label) {
            if (!is_string($label)) {
                continue;
            }

            if ($permission === $key || str_starts_with($permission, "{$key}.")) {
                return ['key' => $key, 'label' => $label];
            }
        }

        return ['key' => null, 'label' => null];
    }

    private static function findAction(string $permission, ?string $subjectKey): ?string
    {
        $actionKey = $permission;

        if ($subjectKey && str_starts_with($permission, "{$subjectKey}.")) {
            $actionKey = substr($permission, strlen($subjectKey) + 1);
        }

        $actions = self::dictionary('actions');

        if (isset($actions[$actionKey]) && is_string($actions[$actionKey])) {
            return $actions[$actionKey];
        }

        $lastSegment = str_contains($actionKey, '.')
            ? substr($actionKey, strrpos($actionKey, '.') + 1)
            : $actionKey;

        if (isset($actions[$lastSegment]) && is_string($actions[$lastSegment])) {
            return $actions[$lastSegment];
        }

        return null;
    }

    private static function dictionaryValue(string $section, string $key): ?string
    {
        $dictionary = self::dictionary($section);
        $value = $dictionary[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function dictionary(string $section): array
    {
        $dictionary = Lang::get("permissions.{$section}", [], 'ru');

        return is_array($dictionary) ? $dictionary : [];
    }

    private static function cleanFallback(?string $fallback, string $key): ?string
    {
        if (!is_string($fallback)) {
            return null;
        }

        $value = trim($fallback);

        if ($value === '' || $value === $key || str_starts_with($value, 'permissions.')) {
            return null;
        }

        return $value;
    }

    private static function unknownGroupLabel(): string
    {
        return 'Модуль доступа';
    }

    private static function unknownInterfaceLabel(): string
    {
        return 'Интерфейс доступа';
    }
}
