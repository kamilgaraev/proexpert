<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Domain\Authorization\Services\RoleScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolesComparisonController extends Controller
{
    protected RoleScanner $roleScanner;

    public function __construct(RoleScanner $roleScanner)
    {
        $this->roleScanner = $roleScanner;
    }

    /**
     * Получить таблицу сравнения всех ролей
     * GET /api/v1/landing/roles/comparison
     */
    public function comparison(Request $request): JsonResponse
    {
        $allRoles = $this->roleScanner->getAllRoles();
        
        $comparison = [];
        
        foreach ($allRoles as $roleSlug => $roleData) {
            $comparison[] = $this->formatRoleForComparison($roleSlug, $roleData);
        }
        
        // Сортируем по контексту и названию
        usort($comparison, function($a, $b) {
            $contextOrder = ['system' => 1, 'organization' => 2, 'project' => 3];
            $contextDiff = ($contextOrder[$a['context_slug']] ?? 999) - ($contextOrder[$b['context_slug']] ?? 999);
            if ($contextDiff !== 0) {
                return $contextDiff;
            }
            return strcmp($a['name'], $b['name']);
        });
        
        return response()->json([
            'success' => true,
            'data' => [
                'roles' => $comparison,
                'total' => count($comparison),
                'last_updated' => now()->toIso8601String()
            ]
        ]);
    }

    /**
     * Форматировать роль для сравнения
     */
    protected function formatRoleForComparison(string $roleSlug, array $roleData): array
    {
        $systemPermissions = $roleData['system_permissions'] ?? [];
        $hasBillingAccess = $this->hasBillingAccess($systemPermissions);
        $canManageRoles = $this->getCanManageRoles($roleData);
        $timeRestrictions = $this->getTimeRestrictions($roleData);
        
        return [
            'slug' => $roleSlug,
            'name' => $roleData['name'] ?? $roleSlug,
            'description' => $roleData['description'] ?? '',
            'context' => $this->translateContext($roleData['context'] ?? 'unknown'),
            'context_slug' => $roleData['context'] ?? 'unknown',
            'interfaces' => $this->translateInterfaces($roleData['interface_access'] ?? []),
            'interfaces_slugs' => $roleData['interface_access'] ?? [],
            'billing_access' => $hasBillingAccess,
            'can_manage_roles' => $canManageRoles['can'],
            'cannot_manage_roles' => $canManageRoles['cannot'],
            'time_restrictions' => $timeRestrictions,
            'system_permissions_count' => count($systemPermissions),
            'module_permissions_count' => $this->countModulePermissions($roleData['module_permissions'] ?? []),
            'has_all_permissions' => in_array('*', $systemPermissions),
            'has_all_modules' => isset($roleData['module_permissions']['*']),
        ];
    }

    /**
     * Проверить доступ к биллингу
     */
    protected function hasBillingAccess(array $systemPermissions): bool
    {
        // Проверяем наличие прав биллинга
        $billingPermissions = [
            'billing.*',
            'billing.manage',
            'billing.view',
            'billing.edit',
            'organization.billing',
            'modules.billing', // Доступ к модулю биллинга
        ];
        
        if (in_array('*', $systemPermissions)) {
            return true;
        }
        
        foreach ($billingPermissions as $permission) {
            if (in_array($permission, $systemPermissions)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Получить информацию о том, какие роли может управлять
     */
    protected function getCanManageRoles(array $roleData): array
    {
        $hierarchy = $roleData['hierarchy'] ?? [];
        $canManage = $hierarchy['can_manage_roles'] ?? [];
        $cannotManage = $hierarchy['cannot_manage'] ?? [];
        
        return [
            'can' => $this->translateRoleSlugs($canManage),
            'cannot' => $this->translateRoleSlugs($cannotManage),
            'can_all' => in_array('*', $canManage),
            'cannot_all' => in_array('*', $cannotManage),
        ];
    }

    /**
     * Получить временные ограничения
     */
    protected function getTimeRestrictions(array $roleData): array
    {
        $conditions = $roleData['conditions'] ?? [];
        $timeConditions = $conditions['time'] ?? [];
        
        if (empty($timeConditions)) {
            return [
                'has_restrictions' => false,
                'working_hours' => null,
                'working_days' => null,
            ];
        }
        
        return [
            'has_restrictions' => true,
            'working_hours' => $timeConditions['working_hours'] ?? null,
            'working_days' => $this->translateWorkingDays($timeConditions['working_days'] ?? null),
        ];
    }

    /**
     * Подсчитать количество модульных прав
     */
    protected function countModulePermissions(array $modulePermissions): int
    {
        $count = 0;
        foreach ($modulePermissions as $module => $permissions) {
            if ($module === '*' && is_array($permissions) && in_array('*', $permissions)) {
                return 999; // Все модули и все права
            }
            if (is_array($permissions)) {
                $count += count($permissions);
            }
        }
        return $count;
    }

    /**
     * Перевести контекст на русский
     */
    protected function translateContext(string $context): string
    {
        return match($context) {
            'system' => 'Система',
            'organization' => 'Организация',
            'project' => 'Проект',
            default => $context,
        };
    }

    /**
     * Перевести интерфейсы на русский
     */
    protected function translateInterfaces(array $interfaces): array
    {
        $translations = [
            'admin' => 'Админ-панель',
            'lk' => 'Личный кабинет',
            'mobile' => 'Мобильное приложение',
        ];
        
        return array_map(function($interface) use ($translations) {
            return $translations[$interface] ?? $interface;
        }, $interfaces);
    }

    /**
     * Перевести слаги ролей в названия
     */
    protected function translateRoleSlugs(array $roleSlugs): array
    {
        if (empty($roleSlugs)) {
            return [];
        }
        
        if (in_array('*', $roleSlugs)) {
            return ['Все роли'];
        }
        
        $allRoles = $this->roleScanner->getAllRoles();
        $translated = [];
        
        foreach ($roleSlugs as $slug) {
            $role = $allRoles->get($slug);
            if ($role) {
                $translated[] = $role['name'] ?? $slug;
            } else {
                $translated[] = $slug;
            }
        }
        
        return $translated;
    }

    /**
     * Перевести рабочие дни на русский
     */
    protected function translateWorkingDays($days): ?array
    {
        if ($days === null) {
            return null;
        }
        
        if (is_array($days)) {
            $dayNames = [
                1 => 'Понедельник',
                2 => 'Вторник',
                3 => 'Среда',
                4 => 'Четверг',
                5 => 'Пятница',
                6 => 'Суббота',
                7 => 'Воскресенье',
                'monday' => 'Понедельник',
                'tuesday' => 'Вторник',
                'wednesday' => 'Среда',
                'thursday' => 'Четверг',
                'friday' => 'Пятница',
                'saturday' => 'Суббота',
                'sunday' => 'Воскресенье',
            ];
            
            return array_map(function($day) use ($dayNames) {
                return $dayNames[$day] ?? $day;
            }, $days);
        }
        
        return null;
    }
}

