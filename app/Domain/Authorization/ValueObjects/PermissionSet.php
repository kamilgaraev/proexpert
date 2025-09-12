<?php

namespace App\Domain\Authorization\ValueObjects;

/**
 * Value Object для набора прав
 */
class PermissionSet
{
    private array $systemPermissions;
    private array $modulePermissions;
    private array $allPermissions;

    public function __construct(array $systemPermissions = [], array $modulePermissions = [])
    {
        $this->systemPermissions = $systemPermissions;
        $this->modulePermissions = $modulePermissions;
        $this->allPermissions = $this->flattenPermissions();
    }

    /**
     * Создать из JSON роли
     */
    public static function fromJsonRole(array $role): self
    {
        return new self(
            $role['system_permissions'] ?? [],
            $role['module_permissions'] ?? []
        );
    }

    /**
     * Создать из кастомной роли
     */
    public static function fromCustomRole(\App\Domain\Authorization\Models\OrganizationCustomRole $role): self
    {
        return new self(
            $role->system_permissions ?? [],
            $role->module_permissions ?? []
        );
    }

    /**
     * Получить системные права
     */
    public function getSystemPermissions(): array
    {
        return $this->systemPermissions;
    }

    /**
     * Получить модульные права
     */
    public function getModulePermissions(): array
    {
        return $this->modulePermissions;
    }

    /**
     * Получить все права в плоском виде
     */
    public function getAllPermissions(): array
    {
        return $this->allPermissions;
    }

    /**
     * Проверить, есть ли системное право
     */
    public function hasSystemPermission(string $permission): bool
    {
        // Проверяем wildcard
        if (in_array('*', $this->systemPermissions)) {
            return true;
        }

        // Точное совпадение
        if (in_array($permission, $this->systemPermissions)) {
            return true;
        }

        // Проверяем wildcard с префиксом
        foreach ($this->systemPermissions as $rolePermission) {
            if ($this->matchesWildcard($permission, $rolePermission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверить, есть ли модульное право
     */
    public function hasModulePermission(string $module, string $permission): bool
    {
        if (!isset($this->modulePermissions[$module])) {
            return false;
        }

        $permissions = $this->modulePermissions[$module];

        // Wildcard для всего модуля
        if (in_array('*', $permissions)) {
            return true;
        }

        // Точное совпадение
        if (in_array($permission, $permissions)) {
            return true;
        }

        // Проверяем wildcard с префиксом
        foreach ($permissions as $rolePermission) {
            if ($this->matchesWildcard($permission, $rolePermission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверить, есть ли право (системное или модульное)
     */
    public function hasPermission(string $permission): bool
    {
        // Сначала проверяем системные права
        if ($this->hasSystemPermission($permission)) {
            return true;
        }

        // Затем модульные права
        $parts = explode('.', $permission, 2);
        if (count($parts) === 2) {
            [$module, $action] = $parts;
            return $this->hasModulePermission($module, $action);
        }

        return false;
    }

    /**
     * Объединить с другим набором прав
     */
    public function merge(PermissionSet $other): self
    {
        $mergedSystemPermissions = array_unique(array_merge(
            $this->systemPermissions,
            $other->getSystemPermissions()
        ));

        $mergedModulePermissions = $this->modulePermissions;
        foreach ($other->getModulePermissions() as $module => $permissions) {
            if (isset($mergedModulePermissions[$module])) {
                $mergedModulePermissions[$module] = array_unique(array_merge(
                    $mergedModulePermissions[$module],
                    $permissions
                ));
            } else {
                $mergedModulePermissions[$module] = $permissions;
            }
        }

        return new self($mergedSystemPermissions, $mergedModulePermissions);
    }

    /**
     * Получить пересечение с другим набором прав
     */
    public function intersect(PermissionSet $other): self
    {
        $intersectedSystemPermissions = array_intersect(
            $this->systemPermissions,
            $other->getSystemPermissions()
        );

        $intersectedModulePermissions = [];
        foreach ($this->modulePermissions as $module => $permissions) {
            $otherPermissions = $other->getModulePermissions()[$module] ?? [];
            $intersected = array_intersect($permissions, $otherPermissions);
            if (!empty($intersected)) {
                $intersectedModulePermissions[$module] = $intersected;
            }
        }

        return new self($intersectedSystemPermissions, $intersectedModulePermissions);
    }

    /**
     * Проверить, содержит ли все права из другого набора
     */
    public function contains(PermissionSet $other): bool
    {
        // Проверяем системные права
        foreach ($other->getSystemPermissions() as $permission) {
            if (!$this->hasSystemPermission($permission)) {
                return false;
            }
        }

        // Проверяем модульные права
        foreach ($other->getModulePermissions() as $module => $permissions) {
            foreach ($permissions as $permission) {
                if (!$this->hasModulePermission($module, $permission)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Получить количество прав
     */
    public function count(): int
    {
        return count($this->allPermissions);
    }

    /**
     * Проверить, пустой ли набор
     */
    public function isEmpty(): bool
    {
        return empty($this->systemPermissions) && empty($this->modulePermissions);
    }

    /**
     * Преобразовать в массив
     */
    public function toArray(): array
    {
        return [
            'system_permissions' => $this->systemPermissions,
            'module_permissions' => $this->modulePermissions,
            'all_permissions' => $this->allPermissions,
        ];
    }

    /**
     * Преобразовать в JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Строковое представление
     */
    public function __toString(): string
    {
        return implode(', ', $this->allPermissions);
    }

    /**
     * Преобразовать права в плоский массив
     */
    private function flattenPermissions(): array
    {
        $permissions = $this->systemPermissions;

        foreach ($this->modulePermissions as $module => $modulePerms) {
            foreach ($modulePerms as $permission) {
                if ($permission === '*') {
                    $permissions[] = "$module.*";
                } else {
                    $permissions[] = "$module.$permission";
                }
            }
        }

        return array_unique($permissions);
    }

    /**
     * Проверить соответствие wildcard паттерну
     */
    private function matchesWildcard(string $permission, string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return false;
        }

        $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
        return preg_match($regex, $permission) === 1;
    }
}
