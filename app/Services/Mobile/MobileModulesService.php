<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Modules\Core\AccessController;
use DomainException;

class MobileModulesService
{
    private const CATALOG = [
        [
            'slug' => 'site-requests',
            'icon' => 'clipboard',
            'route' => 'site_requests',
            'supported_on_mobile' => true,
            'order' => 10,
        ],
        [
            'slug' => 'basic-warehouse',
            'icon' => 'warehouse',
            'route' => 'warehouse',
            'supported_on_mobile' => true,
            'order' => 20,
        ],
        [
            'slug' => 'schedule-management',
            'icon' => 'timeline',
            'route' => 'schedule',
            'supported_on_mobile' => true,
            'order' => 30,
        ],
        [
            'slug' => 'ai-assistant',
            'icon' => 'spark',
            'route' => 'ai_assistant',
            'supported_on_mobile' => true,
            'order' => 35,
        ],
        [
            'slug' => 'workflow-management',
            'icon' => 'hub',
            'route' => 'workflow-management',
            'supported_on_mobile' => true,
            'order' => 40,
        ],
        [
            'slug' => 'time-tracking',
            'icon' => 'timer',
            'route' => 'time-tracking',
            'supported_on_mobile' => true,
            'order' => 50,
        ],
        [
            'slug' => 'construction-journal',
            'permission_slug' => 'budget-estimates',
            'access_slug' => 'budget-estimates',
            'icon' => 'journal',
            'route' => 'construction_journal',
            'supported_on_mobile' => true,
            'order' => 58,
        ],
        [
            'slug' => 'budget-estimates',
            'icon' => 'calculate',
            'route' => 'budget-estimates',
            'supported_on_mobile' => true,
            'order' => 60,
        ],
        [
            'slug' => 'quality-control',
            'icon' => 'quality',
            'route' => 'quality-control',
            'supported_on_mobile' => true,
            'order' => 62,
        ],
        [
            'slug' => 'safety-management',
            'icon' => 'shield-check',
            'route' => 'safety-management',
            'supported_on_mobile' => true,
            'order' => 64,
        ],
        [
            'slug' => 'machinery-operations',
            'icon' => 'machinery',
            'route' => 'machinery-operations',
            'supported_on_mobile' => true,
            'order' => 66,
        ],
        [
            'slug' => 'production-labor',
            'icon' => 'engineer',
            'route' => 'production-labor',
            'supported_on_mobile' => true,
            'order' => 68,
        ],
        [
            'slug' => 'workforce-management',
            'icon' => 'workforce',
            'route' => 'workforce-management',
            'supported_on_mobile' => true,
            'order' => 70,
        ],
        [
            'slug' => 'handover-acceptance',
            'icon' => 'handover',
            'route' => 'handover-acceptance',
            'supported_on_mobile' => true,
            'order' => 72,
        ],
        [
            'slug' => 'procurement',
            'icon' => 'procurement',
            'route' => 'procurement',
            'supported_on_mobile' => true,
            'order' => 80,
        ],
        [
            'slug' => 'contract-management',
            'icon' => 'contract',
            'route' => null,
            'supported_on_mobile' => false,
            'order' => 90,
        ],
        [
            'slug' => 'change-management',
            'icon' => 'change',
            'route' => null,
            'supported_on_mobile' => false,
            'order' => 100,
        ],
        [
            'slug' => 'executive-documentation',
            'icon' => 'documents',
            'route' => null,
            'supported_on_mobile' => false,
            'order' => 110,
        ],
        [
            'slug' => 'project-management',
            'icon' => 'project',
            'route' => null,
            'supported_on_mobile' => false,
            'order' => 120,
        ],
        [
            'slug' => 'catalog-management',
            'icon' => 'catalog',
            'route' => null,
            'supported_on_mobile' => false,
            'order' => 130,
        ],
        [
            'slug' => 'brigades',
            'icon' => 'brigades',
            'route' => null,
            'supported_on_mobile' => false,
            'order' => 140,
        ],
        [
            'slug' => 'video-monitoring',
            'icon' => 'video',
            'route' => null,
            'supported_on_mobile' => false,
            'order' => 150,
        ],
    ];

    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly AccessController $accessController
    ) {
    }

    public function build(User $user): array
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_modules.errors.no_organization'));
        }

        $context = AuthorizationContext::getOrganizationContext($organizationId);
        $permissions = $this->authorizationService->getUserPermissionsStructured($user, $context);
        $modulePermissions = $permissions['modules'] ?? [];
        $modules = [];

        foreach (self::CATALOG as $config) {
            $slug = $config['slug'];
            $permissionSlug = $config['permission_slug'] ?? $slug;
            $accessSlug = $config['access_slug'] ?? $slug;
            $grantedPermissions = $modulePermissions[$permissionSlug] ?? null;

            if (!is_array($grantedPermissions) || $grantedPermissions === []) {
                continue;
            }

            if (!$this->accessController->hasModuleAccess($organizationId, $accessSlug)) {
                continue;
            }

            $modules[] = [
                'slug' => $slug,
                'title' => trans_message('mobile_modules.modules.' . $slug . '.title'),
                'description' => trans_message('mobile_modules.modules.' . $slug . '.description'),
                'icon' => $config['icon'],
                'route' => $config['route'],
                'supported_on_mobile' => $config['supported_on_mobile'],
                'order' => $config['order'],
                'permissions' => array_values($grantedPermissions),
            ];
        }

        usort($modules, static fn(array $left, array $right): int => $left['order'] <=> $right['order']);

        return [
            'modules' => array_values($modules),
            'meta' => [
                'organization_id' => $organizationId,
                'count' => count($modules),
            ],
        ];
    }
}
