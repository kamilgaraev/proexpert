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
     * РџРѕР»СѓС‡РёС‚СЊ С‚Р°Р±Р»РёС†Сѓ СЃСЂР°РІРЅРµРЅРёСЏ РІСЃРµС… СЂРѕР»РµР№
     * GET /api/v1/landing/roles/comparison
     */
    public function comparison(Request $request): JsonResponse
    {
        $allRoles = $this->roleScanner->getAllRoles();
        
        $comparison = [];
        
        foreach ($allRoles as $roleSlug => $roleData) {
            $comparison[] = $this->formatRoleForComparison($roleSlug, $roleData);
        }
        
        // РЎРѕСЂС‚РёСЂСѓРµРј РїРѕ РєРѕРЅС‚РµРєСЃС‚Сѓ Рё РЅР°Р·РІР°РЅРёСЋ
        usort($comparison, function($a, $b) {
            $contextOrder = ['system' => 1, 'organization' => 2, 'project' => 3];
            $contextDiff = ($contextOrder[$a['context_slug']] ?? 999) - ($contextOrder[$b['context_slug']] ?? 999);
            if ($contextDiff !== 0) {
                return $contextDiff;
            }
            return strcmp($a['name'], $b['name']);
        });
        
        return \App\Http\Responses\LandingResponse::fromPayload([
            'success' => true,
            'data' => [
                'roles' => $comparison,
                'total' => count($comparison),
                'last_updated' => now()->toIso8601String()
            ]
        ]);
    }

    /**
     * Р¤РѕСЂРјР°С‚РёСЂРѕРІР°С‚СЊ СЂРѕР»СЊ РґР»СЏ СЃСЂР°РІРЅРµРЅРёСЏ
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
     * РџСЂРѕРІРµСЂРёС‚СЊ РґРѕСЃС‚СѓРї Рє Р±РёР»Р»РёРЅРіСѓ
     */
    protected function hasBillingAccess(array $systemPermissions): bool
    {
        // РџСЂРѕРІРµСЂСЏРµРј РЅР°Р»РёС‡РёРµ РїСЂР°РІ Р±РёР»Р»РёРЅРіР°
        $billingPermissions = [
            'billing.*',
            'billing.manage',
            'billing.view',
            'billing.edit',
            'organization.billing',
            'modules.billing', // Р”РѕСЃС‚СѓРї Рє РјРѕРґСѓР»СЋ Р±РёР»Р»РёРЅРіР°
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
     * РџРѕР»СѓС‡РёС‚СЊ РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ С‚РѕРј, РєР°РєРёРµ СЂРѕР»Рё РјРѕР¶РµС‚ СѓРїСЂР°РІР»СЏС‚СЊ
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
     * РџРѕР»СѓС‡РёС‚СЊ РІСЂРµРјРµРЅРЅС‹Рµ РѕРіСЂР°РЅРёС‡РµРЅРёСЏ
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
     * РџРѕРґСЃС‡РёС‚Р°С‚СЊ РєРѕР»РёС‡РµСЃС‚РІРѕ РјРѕРґСѓР»СЊРЅС‹С… РїСЂР°РІ
     */
    protected function countModulePermissions(array $modulePermissions): int
    {
        $count = 0;
        foreach ($modulePermissions as $module => $permissions) {
            if ($module === '*' && is_array($permissions) && in_array('*', $permissions)) {
                return 999; // Р’СЃРµ РјРѕРґСѓР»Рё Рё РІСЃРµ РїСЂР°РІР°
            }
            if (is_array($permissions)) {
                $count += count($permissions);
            }
        }
        return $count;
    }

    /**
     * РџРµСЂРµРІРµСЃС‚Рё РєРѕРЅС‚РµРєСЃС‚ РЅР° СЂСѓСЃСЃРєРёР№
     */
    protected function translateContext(string $context): string
    {
        return match($context) {
            'system' => 'РЎРёСЃС‚РµРјР°',
            'organization' => 'РћСЂРіР°РЅРёР·Р°С†РёСЏ',
            'project' => 'РџСЂРѕРµРєС‚',
            default => $context,
        };
    }

    /**
     * РџРµСЂРµРІРµСЃС‚Рё РёРЅС‚РµСЂС„РµР№СЃС‹ РЅР° СЂСѓСЃСЃРєРёР№
     */
    protected function translateInterfaces(array $interfaces): array
    {
        $translations = [
            'admin' => 'РђРґРјРёРЅ-РїР°РЅРµР»СЊ',
            'lk' => 'Р›РёС‡РЅС‹Р№ РєР°Р±РёРЅРµС‚',
            'mobile' => 'РњРѕР±РёР»СЊРЅРѕРµ РїСЂРёР»РѕР¶РµРЅРёРµ',
        ];
        
        return array_map(function($interface) use ($translations) {
            return $translations[$interface] ?? $interface;
        }, $interfaces);
    }

    /**
     * РџРµСЂРµРІРµСЃС‚Рё СЃР»Р°РіРё СЂРѕР»РµР№ РІ РЅР°Р·РІР°РЅРёСЏ
     */
    protected function translateRoleSlugs(array $roleSlugs): array
    {
        if (empty($roleSlugs)) {
            return [];
        }
        
        if (in_array('*', $roleSlugs)) {
            return ['Р’СЃРµ СЂРѕР»Рё'];
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
     * РџРµСЂРµРІРµСЃС‚Рё СЂР°Р±РѕС‡РёРµ РґРЅРё РЅР° СЂСѓСЃСЃРєРёР№
     */
    protected function translateWorkingDays($days): ?array
    {
        if ($days === null) {
            return null;
        }
        
        if (is_array($days)) {
            $dayNames = [
                1 => 'РџРѕРЅРµРґРµР»СЊРЅРёРє',
                2 => 'Р’С‚РѕСЂРЅРёРє',
                3 => 'РЎСЂРµРґР°',
                4 => 'Р§РµС‚РІРµСЂРі',
                5 => 'РџСЏС‚РЅРёС†Р°',
                6 => 'РЎСѓР±Р±РѕС‚Р°',
                7 => 'Р’РѕСЃРєСЂРµСЃРµРЅСЊРµ',
                'monday' => 'РџРѕРЅРµРґРµР»СЊРЅРёРє',
                'tuesday' => 'Р’С‚РѕСЂРЅРёРє',
                'wednesday' => 'РЎСЂРµРґР°',
                'thursday' => 'Р§РµС‚РІРµСЂРі',
                'friday' => 'РџСЏС‚РЅРёС†Р°',
                'saturday' => 'РЎСѓР±Р±РѕС‚Р°',
                'sunday' => 'Р’РѕСЃРєСЂРµСЃРµРЅСЊРµ',
            ];
            
            return array_map(function($day) use ($dayNames) {
                return $dayNames[$day] ?? $day;
            }, $days);
        }
        
        return null;
    }
}

