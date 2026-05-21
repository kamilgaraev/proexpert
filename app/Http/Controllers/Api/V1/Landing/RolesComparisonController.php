<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Domain\Authorization\Services\RoleScanner;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function trans_message;

class RolesComparisonController extends Controller
{
    public function __construct(
        protected RoleScanner $roleScanner
    ) {
    }

    public function comparison(Request $request): JsonResponse
    {
        $allRoles = $this->roleScanner->getAllRoles();
        $comparison = [];

        foreach ($allRoles as $roleSlug => $roleData) {
            $comparison[] = $this->formatRoleForComparison($roleSlug, $roleData);
        }

        usort($comparison, function ($a, $b) {
            $contextOrder = ['system' => 1, 'organization' => 2, 'project' => 3];
            $contextDiff = ($contextOrder[$a['context_slug']] ?? 999) - ($contextOrder[$b['context_slug']] ?? 999);

            if ($contextDiff !== 0) {
                return $contextDiff;
            }

            return strcmp($a['name'], $b['name']);
        });

        return LandingResponse::success([
            'roles' => $comparison,
            'total' => count($comparison),
            'last_updated' => now()->toIso8601String(),
        ], trans_message('landing.roles.loaded'));
    }

    protected function formatRoleForComparison(string $roleSlug, array $roleData): array
    {
        $systemPermissions = $roleData['system_permissions'] ?? [];
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
            'billing_access' => $this->hasBillingAccess($systemPermissions),
            'can_manage_roles' => $canManageRoles['can'],
            'cannot_manage_roles' => $canManageRoles['cannot'],
            'time_restrictions' => $timeRestrictions,
            'system_permissions_count' => count($systemPermissions),
            'module_permissions_count' => $this->countModulePermissions($roleData['module_permissions'] ?? []),
            'has_all_permissions' => in_array('*', $systemPermissions, true),
            'has_all_modules' => isset($roleData['module_permissions']['*']),
        ];
    }

    protected function hasBillingAccess(array $systemPermissions): bool
    {
        $billingPermissions = [
            'billing.*',
            'billing.manage',
            'billing.view',
            'billing.edit',
            'organization.billing',
            'modules.billing',
        ];

        if (in_array('*', $systemPermissions, true)) {
            return true;
        }

        foreach ($billingPermissions as $permission) {
            if (in_array($permission, $systemPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    protected function getCanManageRoles(array $roleData): array
    {
        $hierarchy = $roleData['hierarchy'] ?? [];
        $canManage = $hierarchy['can_manage_roles'] ?? [];
        $cannotManage = $hierarchy['cannot_manage'] ?? [];

        return [
            'can' => $this->translateRoleSlugs($canManage),
            'cannot' => $this->translateRoleSlugs($cannotManage),
            'can_all' => in_array('*', $canManage, true),
            'cannot_all' => in_array('*', $cannotManage, true),
        ];
    }

    protected function getTimeRestrictions(array $roleData): array
    {
        $timeConditions = $roleData['conditions']['time'] ?? [];

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

    protected function countModulePermissions(array $modulePermissions): int
    {
        $count = 0;

        foreach ($modulePermissions as $module => $permissions) {
            if ($module === '*' && is_array($permissions) && in_array('*', $permissions, true)) {
                return 999;
            }

            if (is_array($permissions)) {
                $count += count($permissions);
            }
        }

        return $count;
    }

    protected function translateContext(string $context): string
    {
        return match ($context) {
            'system' => trans_message('landing.roles.contexts.system'),
            'organization' => trans_message('landing.roles.contexts.organization'),
            'project' => trans_message('landing.roles.contexts.project'),
            default => $context,
        };
    }

    protected function translateInterfaces(array $interfaces): array
    {
        $translations = [
            'admin' => trans_message('landing.roles.interfaces.admin'),
            'lk' => trans_message('landing.roles.interfaces.lk'),
            'mobile' => trans_message('landing.roles.interfaces.mobile'),
        ];

        return array_map(function ($interface) use ($translations) {
            return $translations[$interface] ?? $interface;
        }, $interfaces);
    }

    protected function translateRoleSlugs(array $roleSlugs): array
    {
        if (empty($roleSlugs)) {
            return [];
        }

        if (in_array('*', $roleSlugs, true)) {
            return [trans_message('landing.roles.all_roles')];
        }

        $allRoles = $this->roleScanner->getAllRoles();
        $translated = [];

        foreach ($roleSlugs as $slug) {
            $role = $allRoles->get($slug);
            $translated[] = $role ? ($role['name'] ?? $slug) : $slug;
        }

        return $translated;
    }

    protected function translateWorkingDays($days): ?array
    {
        if ($days === null) {
            return null;
        }

        if (!is_array($days)) {
            return null;
        }

        $dayNames = [
            1 => trans_message('landing.roles.days.1'),
            2 => trans_message('landing.roles.days.2'),
            3 => trans_message('landing.roles.days.3'),
            4 => trans_message('landing.roles.days.4'),
            5 => trans_message('landing.roles.days.5'),
            6 => trans_message('landing.roles.days.6'),
            7 => trans_message('landing.roles.days.7'),
            'monday' => trans_message('landing.roles.days.monday'),
            'tuesday' => trans_message('landing.roles.days.tuesday'),
            'wednesday' => trans_message('landing.roles.days.wednesday'),
            'thursday' => trans_message('landing.roles.days.thursday'),
            'friday' => trans_message('landing.roles.days.friday'),
            'saturday' => trans_message('landing.roles.days.saturday'),
            'sunday' => trans_message('landing.roles.days.sunday'),
        ];

        return array_map(function ($day) use ($dayNames) {
            return $dayNames[$day] ?? $day;
        }, $days);
    }
}
