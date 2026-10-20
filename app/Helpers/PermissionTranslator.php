<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Support\Facades\Lang;

class PermissionTranslator
{
    private array $dictionaries = [];

    public static function translateSystemPermissions(array $permissions): array
    {
        return (new self)->systemPermissions($permissions);
    }

    public static function translateModulePermissions(array $modulePermissions): array
    {
        return (new self)->modulePermissions($modulePermissions);
    }

    public static function translateInterfaceAccess(array $interfaces): array
    {
        return (new self)->interfaceAccess($interfaces);
    }

    public static function getPermissionTranslation(
        string $permission,
        ?string $module = null,
        ?string $fallback = null
    ): string {
        return (new self)->permissionTranslation($permission, $module, $fallback);
    }

    public static function getGroupTranslation(string $group): string
    {
        return (new self)->groupTranslation($group);
    }

    private function systemPermissions(array $permissions): array
    {
        $translations = [];

        foreach ($permissions as $permission => $description) {
            $key = is_numeric($permission) ? $description : $permission;

            if (!is_string($key)) {
                continue;
            }

            $fallback = is_string($description) ? $description : null;
            $translations[$key] = $this->permissionTranslation($key, null, $fallback);
        }

        return $translations;
    }

    private function modulePermissions(array $modulePermissions): array
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

                $translations[$module][$permission] = $this->permissionTranslation($permission, (string) $module);
            }
        }

        return $translations;
    }

    private function interfaceAccess(array $interfaces): array
    {
        $translations = [];

        foreach ($interfaces as $interface => $description) {
            $key = is_numeric($interface) ? $description : $interface;

            if (!is_string($key)) {
                continue;
            }

            $translation = $this->dictionaryValue('interfaces', $key);
            $translations[$key] = $translation ?? $this->cleanFallback(
                is_string($description) ? $description : null,
                $key
            ) ?? $this->unknownInterfaceLabel();
        }

        return $translations;
    }

    public static function translatePermissionsData(array $data): array
    {
        $translator = new self;
        $result = [];

        if (isset($data['system_permissions'])) {
            $result['system_permissions'] = $translator->systemPermissions($data['system_permissions']);
        }

        if (isset($data['module_permissions'])) {
            $result['module_permissions'] = $translator->modulePermissions($data['module_permissions']);
            $result['module_groups'] = [];

            foreach (array_keys($data['module_permissions']) as $module) {
                $result['module_groups'][$module] = $translator->groupTranslation((string) $module);
            }
        }

        if (isset($data['interface_access'])) {
            $result['interface_access'] = $translator->interfaceAccess($data['interface_access']);
        }

        return $result;
    }

    private function permissionTranslation(
        string $permission,
        ?string $module = null,
        ?string $fallback = null
    ): string {
        $fallbackLabel = $this->cleanFallback($fallback, $permission);

        return $this->dictionaryValue('values', $permission)
            ?? $this->modulePermissionTranslation($permission, $module)
            ?? $this->systemPermissionTranslation($permission)
            ?? $fallbackLabel
            ?? $this->buildReadablePermissionLabel($permission);
    }

    private function groupTranslation(string $group): string
    {
        $normalizedGroup = str_replace('-', '_', $group);

        return $this->dictionaryValue('groups', $normalizedGroup)
            ?? $this->dictionaryValue('groups', $group)
            ?? $this->unknownGroupLabel();
    }

    private function modulePermissionTranslation(string $permission, ?string $module): ?string
    {
        if (!$module) {
            return null;
        }

        $normalizedModule = str_replace('-', '_', $module);
        $moduleTranslations = $this->dictionary('modules');
        $moduleValues = $moduleTranslations[$normalizedModule] ?? $moduleTranslations[$module] ?? null;

        if (is_array($moduleValues) && isset($moduleValues[$permission]) && is_string($moduleValues[$permission])) {
            return $moduleValues[$permission];
        }

        return null;
    }

    private function systemPermissionTranslation(string $permission): ?string
    {
        $parts = explode('.', $permission);

        if (count($parts) !== 2) {
            return null;
        }

        [$group, $action] = $parts;
        $systemTranslations = $this->dictionary('system');
        $groupTranslations = $systemTranslations[$group] ?? null;

        if (is_array($groupTranslations) && isset($groupTranslations[$action]) && is_string($groupTranslations[$action])) {
            return $groupTranslations[$action];
        }

        return null;
    }

    private function buildReadablePermissionLabel(string $permission): string
    {
        $subject = $this->findSubject($permission);
        $action = $this->findAction($permission, $subject['key'] ?? null);

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

    private function findSubject(string $permission): array
    {
        $subjects = $this->dictionary('subjects');
        $subjectKey = $permission;

        while (true) {
            $label = $subjects[$subjectKey] ?? null;
            if (is_string($label)) {
                $key = array_key_first([$subjectKey => null]);
                if ($permission === $key || str_starts_with($permission, "{$key}.")) {
                    return ['key' => $key, 'label' => $label];
                }
            }

            $separator = strrpos($subjectKey, '.');
            if ($separator === false) {
                break;
            }

            $subjectKey = substr($subjectKey, 0, $separator);
        }

        return ['key' => null, 'label' => null];
    }

    private function findAction(string $permission, ?string $subjectKey): ?string
    {
        $actionKey = $permission;

        if ($subjectKey && str_starts_with($permission, "{$subjectKey}.")) {
            $actionKey = substr($permission, strlen($subjectKey) + 1);
        }

        $actions = $this->dictionary('actions');

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

    private function dictionaryValue(string $section, string $key): ?string
    {
        $dictionary = $this->dictionary($section);
        $value = $dictionary[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function dictionary(string $section): array
    {
        if (!array_key_exists($section, $this->dictionaries)) {
            $dictionary = Lang::get("permissions.{$section}", [], 'ru');
            $this->dictionaries[$section] = is_array($dictionary) ? $dictionary : [];
        }

        return $this->dictionaries[$section];
    }

    private function cleanFallback(?string $fallback, string $key): ?string
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

    private function unknownGroupLabel(): string
    {
        return 'Модуль доступа';
    }

    private function unknownInterfaceLabel(): string
    {
        return 'Интерфейс доступа';
    }
}
