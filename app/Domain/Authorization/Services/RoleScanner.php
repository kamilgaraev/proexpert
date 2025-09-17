<?php

namespace App\Domain\Authorization\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Сервис для сканирования и загрузки JSON ролей
 */
class RoleScanner
{
    private const ROLES_PATH = 'config/RoleDefinitions';
    private const CACHE_KEY = 'authorization_roles';
    private const CACHE_TTL = 3600; // 1 час

    /**
     * Получить все роли с кешированием
     */
    public function getAllRoles(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->scanRoles();
        });
    }

    /**
     * Получить роль по слагу
     */
    public function getRole(string $slug): ?array
    {
        $roles = $this->getAllRoles();
        $role = $roles->get($slug);
        
        Log::info('[RoleScanner] DEBUG: Getting role', [
            'slug' => $slug,
            'role_found' => $role !== null,
            'total_roles' => $roles->count(),
            'available_roles' => $roles->keys()->toArray()
        ]);
        
        return $role;
    }

    /**
     * Получить роли по контексту
     */
    public function getRolesByContext(string $context): Collection
    {
        return $this->getAllRoles()->filter(function ($role) use ($context) {
            return $role['context'] === $context;
        });
    }

    /**
     * Получить роли по интерфейсу
     */
    public function getRolesByInterface(string $interface): Collection
    {
        return $this->getAllRoles()->filter(function ($role) use ($interface) {
            return in_array($interface, $role['interface_access'] ?? []);
        });
    }

    /**
     * Проверить существование роли
     */
    public function roleExists(string $slug): bool
    {
        return $this->getAllRoles()->has($slug);
    }

    /**
     * Получить системные права роли
     */
    public function getSystemPermissions(string $roleSlug): array
    {
        $role = $this->getRole($roleSlug);
        $permissions = $role['system_permissions'] ?? [];
        
        Log::info('[RoleScanner] DEBUG: Getting system permissions', [
            'role_slug' => $roleSlug,
            'role_found' => $role !== null,
            'permissions_count' => count($permissions),
            'permissions' => $permissions,
            'role_data_keys' => $role ? array_keys($role) : []
        ]);
        
        return $permissions;
    }

    /**
     * Получить модульные права роли
     */
    public function getModulePermissions(string $roleSlug): array
    {
        $role = $this->getRole($roleSlug);
        return $role['module_permissions'] ?? [];
    }

    /**
     * Проверить, может ли роль управлять другой ролью
     */
    public function canManageRole(string $managerRole, string $targetRole): bool
    {
        $role = $this->getRole($managerRole);
        
        if (!$role) {
            return false;
        }

        $hierarchy = $role['hierarchy'] ?? [];
        
        // Проверяем, может ли управлять
        $canManage = $hierarchy['can_manage_roles'] ?? [];
        if (in_array('*', $canManage) || in_array($targetRole, $canManage)) {
            // Проверяем исключения
            $cannotManage = $hierarchy['cannot_manage'] ?? [];
            return !in_array('*', $cannotManage) && !in_array($targetRole, $cannotManage);
        }

        return false;
    }

    /**
     * Получить доступные интерфейсы для роли
     */
    public function getInterfaceAccess(string $roleSlug): array
    {
        $role = $this->getRole($roleSlug);
        return $role['interface_access'] ?? [];
    }

    /**
     * Очистить кеш ролей
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Перезагрузить роли
     */
    public function reload(): Collection
    {
        $this->clearCache();
        return $this->getAllRoles();
    }

    /**
     * Валидировать структуру роли
     */
    public function validateRole(array $role): array
    {
        $errors = [];
        
        $required = ['name', 'slug', 'context', 'interface'];
        foreach ($required as $field) {
            if (!isset($role[$field]) || empty($role[$field])) {
                $errors[] = "Поле '$field' обязательно";
            }
        }

        if (isset($role['context']) && !in_array($role['context'], ['system', 'organization', 'project'])) {
            $errors[] = "Недопустимый контекст: {$role['context']}";
        }

        if (isset($role['interface_access']) && !is_array($role['interface_access'])) {
            $errors[] = "interface_access должен быть массивом";
        }

        if (isset($role['system_permissions']) && !is_array($role['system_permissions'])) {
            $errors[] = "system_permissions должен быть массивом";
        }

        if (isset($role['module_permissions']) && !is_array($role['module_permissions'])) {
            $errors[] = "module_permissions должен быть массивом";
        }

        return $errors;
    }

    /**
     * Сканировать все JSON файлы ролей
     */
    protected function scanRoles(): Collection
    {
        $roles = collect();
        $basePath = base_path(self::ROLES_PATH);

        if (!File::exists($basePath)) {
            throw new InvalidArgumentException("Папка ролей не найдена: $basePath");
        }

        $directories = ['system', 'lk', 'admin', 'mobile', 'project'];

        foreach ($directories as $dir) {
            $dirPath = "$basePath/$dir";
            
            if (!File::exists($dirPath)) {
                continue;
            }

            $files = File::files($dirPath);
            
            foreach ($files as $file) {
                if ($file->getExtension() === 'json') {
                    $content = File::get($file->getPathname());
                    $roleData = json_decode($content, true);
                    
                    if (json_last_error() === JSON_ERROR_NONE && isset($roleData['slug'])) {
                        // Валидация роли
                        $errors = $this->validateRole($roleData);
                        if (empty($errors)) {
                            $roles->put($roleData['slug'], $roleData);
                        } else {
                            Log::warning("Ошибка в роли {$file->getFilename()}: " . implode(', ', $errors));
                        }
                    } else {
                        Log::warning("Неверный JSON в файле роли: {$file->getFilename()}");
                    }
                }
            }
        }

        return $roles;
    }

    /**
     * Получить статистику ролей
     */
    public function getStats(): array
    {
        $roles = $this->getAllRoles();
        
        $stats = [
            'total' => $roles->count(),
            'by_context' => [],
            'by_interface' => [],
        ];

        foreach ($roles as $role) {
            // Статистика по контексту
            $context = $role['context'] ?? 'unknown';
            $stats['by_context'][$context] = ($stats['by_context'][$context] ?? 0) + 1;
            
            // Статистика по интерфейсам
            $interfaces = $role['interface_access'] ?? [];
            foreach ($interfaces as $interface) {
                $stats['by_interface'][$interface] = ($stats['by_interface'][$interface] ?? 0) + 1;
            }
        }

        return $stats;
    }
}
