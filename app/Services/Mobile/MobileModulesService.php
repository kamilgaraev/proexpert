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
            'slug' => 'workflow-management',
            'icon' => 'hub',
            'route' => null,
            'supported_on_mobile' => false,
            'order' => 40,
        ],
        [
            'slug' => 'time-tracking',
            'icon' => 'timer',
            'route' => null,
            'supported_on_mobile' => false,
            'order' => 50,
        ],
        [
            'slug' => 'budget-estimates',
            'icon' => 'calculate',
            'route' => null,
            'supported_on_mobile' => false,
            'order' => 60,
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
            $grantedPermissions = $modulePermissions[$slug] ?? null;

            if (!is_array($grantedPermissions) || $grantedPermissions === []) {
                continue;
            }

            if (!$this->accessController->hasModuleAccess($organizationId, $slug)) {
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
