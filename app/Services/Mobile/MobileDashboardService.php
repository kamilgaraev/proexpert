<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use DomainException;

class MobileDashboardService
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly MobileWarehouseService $warehouseService,
        private readonly MobileScheduleService $scheduleService
    ) {
    }

    public function build(User $user): array
    {
        $organizationId = (int) $user->current_organization_id;

        if ($organizationId <= 0) {
            throw new DomainException(trans_message('mobile_dashboard.errors.no_organization'));
        }

        $context = AuthorizationContext::getOrganizationContext($organizationId);
        $permissions = $this->authorizationService->getUserPermissionsStructured($user, $context);
        $roles = $this->authorizationService->getUserRoles($user, $context)
            ->pluck('role_slug')
            ->values()
            ->all();
        $modules = $permissions['modules'] ?? [];
        $widgets = [];

        if ($this->canShowProjectOverview($modules)) {
            $widgets[] = [
                'type' => 'project_overview',
                'order' => 10,
                'title' => trans_message('mobile_dashboard.widgets.project_overview.title'),
                'description' => trans_message('mobile_dashboard.widgets.project_overview.description'),
                'route' => null,
                'badge' => null,
                'payload' => [
                    'show_selected_project' => true,
                ],
            ];
        }

        if ($this->canShowSiteRequests($modules)) {
            $requestSummary = $this->buildOwnRequestsSummary($organizationId, (int) $user->id);

            $widgets[] = [
                'type' => 'site_requests',
                'order' => 20,
                'title' => trans_message('mobile_dashboard.widgets.site_requests.title'),
                'description' => trans_message('mobile_dashboard.widgets.site_requests.description', [
                    'active' => $requestSummary['active_count'],
                    'overdue' => $requestSummary['overdue_count'],
                ]),
                'route' => 'site_requests',
                'badge' => $requestSummary['active_count'] > 0 ? (string) $requestSummary['active_count'] : null,
                'payload' => $requestSummary,
            ];
        }

        if ($this->canShowApprovals($modules)) {
            $approvalSummary = $this->buildApprovalSummary($organizationId);

            $widgets[] = [
                'type' => 'site_request_approvals',
                'order' => 30,
                'title' => trans_message('mobile_dashboard.widgets.site_request_approvals.title'),
                'description' => trans_message('mobile_dashboard.widgets.site_request_approvals.description', [
                    'pending' => $approvalSummary['pending_count'],
                    'review' => $approvalSummary['in_review_count'],
                ]),
                'route' => 'site_requests',
                'badge' => $approvalSummary['pending_count'] > 0 ? (string) $approvalSummary['pending_count'] : null,
                'payload' => $approvalSummary,
            ];
        }

        if ($this->canShowWarehouse($modules)) {
            $warehouseWidget = $this->warehouseService->buildWidget($user);

            $widgets[] = [
                'type' => 'warehouse',
                'order' => 40,
                'title' => trans_message('mobile_dashboard.widgets.warehouse.title'),
                'description' => $warehouseWidget['description'],
                'route' => 'warehouse',
                'badge' => $warehouseWidget['badge'],
                'payload' => $warehouseWidget['payload'],
            ];
        }

        if ($this->canShowSchedule($modules)) {
            $scheduleWidget = $this->scheduleService->buildWidget($user);

            $widgets[] = [
                'type' => 'schedule',
                'order' => 50,
                'title' => trans_message('mobile_dashboard.widgets.schedule.title'),
                'description' => $scheduleWidget['description'],
                'route' => 'schedule',
                'badge' => $scheduleWidget['badge'],
                'payload' => $scheduleWidget['payload'],
            ];
        }

        usort($widgets, static fn(array $left, array $right): int => $left['order'] <=> $right['order']);

        return [
            'widgets' => array_values($widgets),
            'meta' => [
                'organization_id' => $organizationId,
                'roles' => $roles,
                'modules' => array_keys($modules),
            ],
        ];
    }

    private function canShowProjectOverview(array $modules): bool
    {
        return isset($modules['projects']) || isset($modules['project-management']);
    }

    private function canShowSiteRequests(array $modules): bool
    {
        return $this->hasAnyPermission($modules['site-requests'] ?? [], [
            'site_requests.view',
            'site_requests.create',
            'site_requests.edit',
            'site_requests.files.upload',
        ]);
    }

    private function canShowApprovals(array $modules): bool
    {
        return $this->hasAnyPermission($modules['site-requests'] ?? [], [
            'site_requests.approve',
            'site_requests.assign',
            'site_requests.change_status',
            'site_requests.statistics',
        ]);
    }

    private function canShowWarehouse(array $modules): bool
    {
        return $this->hasAnyPermission($modules['basic-warehouse'] ?? [], [
            'warehouse.view',
            'warehouse.receipts',
            'warehouse.manage_stock',
            'warehouse.advanced.view',
        ]);
    }

    private function canShowSchedule(array $modules): bool
    {
        return $this->hasAnyPermission($modules['schedule-management'] ?? [], [
            'schedule-management.view',
            'schedule-management.notifications',
            'schedule-management.approve',
            'schedule.view',
            'schedule.notifications',
            'schedule.approve',
        ]);
    }

    private function hasAnyPermission(array $grantedPermissions, array $expectedPermissions): bool
    {
        foreach ($expectedPermissions as $expectedPermission) {
            if ($this->hasPermission($grantedPermissions, $expectedPermission)) {
                return true;
            }
        }

        return false;
    }

    private function hasPermission(array $grantedPermissions, string $expectedPermission): bool
    {
        foreach ($grantedPermissions as $grantedPermission) {
            if (!is_string($grantedPermission) || $grantedPermission === '') {
                continue;
            }

            if ($grantedPermission === '*' || $grantedPermission === $expectedPermission) {
                return true;
            }

            if (str_ends_with($grantedPermission, '.*')) {
                $prefix = substr($grantedPermission, 0, -1);

                if (str_starts_with($expectedPermission, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildOwnRequestsSummary(int $organizationId, int $userId): array
    {
        $query = SiteRequest::query()
            ->forOrganization($organizationId)
            ->forUser($userId);

        $activeCount = (clone $query)->active()->count();
        $overdueCount = (clone $query)->overdue()->count();
        $draftCount = (clone $query)->withStatus(SiteRequestStatusEnum::DRAFT)->count();

        return [
            'active_count' => $activeCount,
            'overdue_count' => $overdueCount,
            'draft_count' => $draftCount,
        ];
    }

    private function buildApprovalSummary(int $organizationId): array
    {
        $query = SiteRequest::query()->forOrganization($organizationId);

        $pendingCount = (clone $query)->withStatus(SiteRequestStatusEnum::PENDING)->count();
        $inReviewCount = (clone $query)->withStatus(SiteRequestStatusEnum::IN_REVIEW)->count();
        $approvedCount = (clone $query)->withStatus(SiteRequestStatusEnum::APPROVED)->count();

        return [
            'pending_count' => $pendingCount,
            'in_review_count' => $inReviewCount,
            'approved_count' => $approvedCount,
        ];
    }
}
