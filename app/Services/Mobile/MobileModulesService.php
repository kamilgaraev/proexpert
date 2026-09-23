<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Project\UserProjectAccessService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

class MobileModulesService
{
    private const VIEW_PERMISSIONS = [
        'site-requests' => ['site_requests.view'],
        'basic-warehouse' => ['warehouse.view'],
        'schedule-management' => ['schedule-management.view'],
        'ai-assistant' => ['ai_assistant.usage.view'],
        'crm' => ['crm.view'],
        'tenders' => ['tenders.view'],
        'act-reporting' => ['act_reports.view'],
        'payments' => ['payments.invoice.view', 'payments.invoice.view_all'],
        'file-management' => ['report_files.view'],
        'report-templates' => ['report_templates.view'],
        'budgeting' => ['reports.project_control.view'],
        'workflow-management' => ['completed_works.view'],
        'time-tracking' => ['time_tracking.view'],
        'construction-journal' => ['estimates.view', 'estimates.view_all'],
        'budget-estimates' => ['estimates.view', 'estimates.view_all'],
        'quality-control' => ['quality-control.view'],
        'safety-management' => ['safety-management.view'],
        'machinery-operations' => ['machinery-operations.view'],
        'production-labor' => ['production-labor.view'],
        'workforce-management' => ['workforce.view'],
        'handover-acceptance' => ['handover-acceptance.view'],
        'procurement' => ['procurement.view'],
        'contract-management' => ['contracts.view'],
        'design-management' => ['design-management.view'],
        'change-management' => ['change-management.view'],
        'executive-documentation' => ['executive-documentation.view'],
        'project-management' => ['projects.view'],
        'catalog-management' => ['materials.view'],
        'brigades' => ['brigades.catalog.view', 'brigades.requests.view', 'brigades.invitations.view'],
        'contractor-marketplace' => ['contractor_marketplace.search.view'],
        'video-monitoring' => ['video-monitoring.view'],
        'one-c-basic-exchange' => ['one_c_exchange.view', 'one_c_exchange.history.view'],
        'access_recertification' => ['access_recertification.campaigns.view', 'access_recertification.reviews.view'],
        'rate-management' => ['rate_coefficients.view'],
        'system-logs' => ['system-logs.system.view'],
    ];

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
            'slug' => 'crm',
            'icon' => 'contacts',
            'route' => 'crm',
            'supported_on_mobile' => true,
            'order' => 36,
        ],
        [
            'slug' => 'tenders',
            'icon' => 'tenders',
            'route' => 'tenders',
            'supported_on_mobile' => true,
            'order' => 37,
        ],
        [
            'slug' => 'act-reporting',
            'icon' => 'acts',
            'route' => 'acts',
            'supported_on_mobile' => true,
            'order' => 38,
        ],
        [
            'slug' => 'payments',
            'icon' => 'payments',
            'route' => 'payments',
            'supported_on_mobile' => true,
            'order' => 39,
        ],
        [
            'slug' => 'file-management',
            'icon' => 'reports',
            'route' => 'published_reports',
            'supported_on_mobile' => true,
            'order' => 41,
        ],
        [
            'slug' => 'report-templates',
            'icon' => 'templates',
            'route' => 'template_library',
            'supported_on_mobile' => true,
            'order' => 42,
        ],
        [
            'slug' => 'budgeting',
            'permission_slug' => 'reports',
            'icon' => 'budgeting',
            'route' => 'budgeting',
            'supported_on_mobile' => true,
            'order' => 43,
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
            'route' => 'contract-management',
            'supported_on_mobile' => true,
            'order' => 90,
        ],
        [
            'slug' => 'design-management',
            'icon' => 'design',
            'route' => 'design-management',
            'supported_on_mobile' => true,
            'order' => 95,
        ],
        [
            'slug' => 'change-management',
            'icon' => 'change',
            'route' => 'change-management',
            'supported_on_mobile' => true,
            'order' => 100,
        ],
        [
            'slug' => 'executive-documentation',
            'icon' => 'documents',
            'route' => 'executive-documentation',
            'supported_on_mobile' => true,
            'order' => 110,
        ],
        [
            'slug' => 'project-management',
            'icon' => 'project',
            'route' => 'project-management',
            'supported_on_mobile' => true,
            'order' => 120,
        ],
        [
            'slug' => 'catalog-management',
            'icon' => 'catalog',
            'route' => 'catalog-management',
            'supported_on_mobile' => true,
            'order' => 130,
        ],
        [
            'slug' => 'brigades',
            'icon' => 'brigades',
            'route' => 'brigades',
            'supported_on_mobile' => true,
            'order' => 140,
        ],
        [
            'slug' => 'contractor-marketplace',
            'permission_slug' => 'contractor-portal',
            'icon' => 'contractors',
            'route' => 'contractors',
            'supported_on_mobile' => true,
            'order' => 145,
        ],
        [
            'slug' => 'video-monitoring',
            'icon' => 'video',
            'route' => 'video-monitoring',
            'supported_on_mobile' => true,
            'order' => 150,
        ],
        [
            'slug' => 'one-c-basic-exchange',
            'icon' => 'sync',
            'route' => 'one_c_exchange',
            'supported_on_mobile' => true,
            'order' => 160,
        ],
        [
            'slug' => 'access_recertification',
            'icon' => 'verified_user',
            'route' => 'access_recertification',
            'supported_on_mobile' => true,
            'order' => 162,
        ],
        [
            'slug' => 'rate-management',
            'icon' => 'functions',
            'route' => 'rate_coefficients',
            'supported_on_mobile' => true,
            'order' => 164,
        ],
        [
            'slug' => 'system-logs',
            'icon' => 'history',
            'route' => 'system_events',
            'supported_on_mobile' => true,
            'order' => 166,
        ],
    ];

    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly AccessController $accessController,
        private readonly UserProjectAccessService $projectAccess,
    ) {
    }

    public function build(User $user, ?int $projectId = null): array
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_modules.errors.no_organization'));
        }

        $organizationContext = AuthorizationContext::getOrganizationContext($organizationId);
        $permissions = $this->authorizationService->getUserPermissionsStructured($user, $organizationContext);
        $modulePermissions = $permissions['modules'] ?? [];

        if ($projectId !== null) {
            $project = Project::query()->find($projectId);
            if (!$project || !$this->projectAccess->canAccessProject($user, $project, $organizationId)) {
                throw new AuthorizationException(trans_message('mobile_modules.errors.no_project_access'));
            }

            $projectContext = AuthorizationContext::getProjectContext($projectId, $organizationId);
            $projectPermissions = $this->authorizationService->getUserPermissionsStructured($user, $projectContext);
            foreach (($projectPermissions['modules'] ?? []) as $module => $grants) {
                $modulePermissions[$module] = array_values(array_unique(array_merge(
                    $modulePermissions[$module] ?? [],
                    $grants,
                )));
            }
        }
        $modules = [];

        foreach (self::CATALOG as $config) {
            $slug = $config['slug'];
            $permissionSlug = $config['permission_slug'] ?? $slug;
            $accessSlug = $config['access_slug'] ?? $slug;
            $grantedPermissions = $modulePermissions[$permissionSlug] ?? null;

            if (!is_array($grantedPermissions) || $grantedPermissions === []) {
                continue;
            }

            if ($projectId !== null) {
                $grantedPermissions = array_values(array_filter(
                    $grantedPermissions,
                    fn (string $permission): bool => $this->allowsPermissionOnProject(
                        $user,
                        $permission,
                        $slug,
                        $organizationId,
                        $projectId,
                    ),
                ));
                if ($grantedPermissions === []) {
                    continue;
                }
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
                'project_id' => $projectId,
                'count' => count($modules),
            ],
        ];
    }

    private function allowsPermissionOnProject(
        User $user,
        string $permission,
        string $slug,
        int $organizationId,
        int $projectId,
    ): bool {
        $candidates = $permission === '*' ? self::VIEW_PERMISSIONS[$slug] : [$permission];

        foreach ($candidates as $candidate) {
            if ($this->authorizationService->can($user, $candidate, [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ])) {
                return true;
            }
        }

        return false;
    }
}
