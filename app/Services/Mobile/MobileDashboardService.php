<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\AIAssistant\Models\Conversation as AiConversation;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrder;
use App\BusinessModules\Features\Procurement\Enums\ProcurementApprovalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\User;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class MobileDashboardService
{
    private const STATUS_OK = 'ok';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_ATTENTION = 'attention';
    private const STATUS_CRITICAL = 'critical';

    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly AccessController $accessController
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
        $modules = is_array($permissions['modules'] ?? null) ? $permissions['modules'] : [];
        $widgets = [];
        $unavailableWidgets = [];

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'project_overview',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId, $roles): ?array {
                if (!$this->canShowProjectOverview($modules, $organizationId)) {
                    return null;
                }

                return $this->buildProjectOverviewWidget($modules, $roles);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'site_requests',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId, $user): ?array {
                if (!$this->canShowSiteRequests($modules, $organizationId)) {
                    return null;
                }

                return $this->buildSiteRequestsWidget($organizationId, (int) $user->id);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'site_request_approvals',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowApprovals($modules, $organizationId)) {
                    return null;
                }

                return $this->buildApprovalsWidget($organizationId);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'warehouse',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowModule($modules, $organizationId, 'basic-warehouse', [
                    'warehouse.view',
                    'warehouse.receipts',
                    'warehouse.manage_stock',
                    'warehouse.advanced.view',
                ])) {
                    return null;
                }

                return $this->buildWarehouseWidget($organizationId);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'schedule',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowModule($modules, $organizationId, 'schedule-management', [
                    'schedule-management.view',
                    'schedule-management.notifications',
                    'schedule-management.approve',
                    'schedule.view',
                    'schedule.notifications',
                    'schedule.approve',
                ])) {
                    return null;
                }

                return $this->buildScheduleWidget($organizationId);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'ai_assistant',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId, $user): ?array {
                if (!$this->canShowModule($modules, $organizationId, 'ai-assistant')) {
                    return null;
                }

                return $this->buildAiAssistantWidget($organizationId, (int) $user->id);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'construction_journal',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowModule(
                    $modules,
                    $organizationId,
                    'budget-estimates',
                    ['construction-journal.view', 'construction-journal.create', 'construction-journal.approve'],
                    'budget-estimates'
                )) {
                    return null;
                }

                return $this->buildConstructionJournalWidget($organizationId);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'quality_control',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowModule($modules, $organizationId, 'quality-control', [
                    'quality-control.view',
                    'quality-control.defects.view',
                ])) {
                    return null;
                }

                return $this->buildQualityControlWidget($organizationId);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'safety_management',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowModule($modules, $organizationId, 'safety-management', [
                    'safety-management.view',
                ])) {
                    return null;
                }

                return $this->buildSafetyManagementWidget($organizationId);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'machinery_operations',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowModule($modules, $organizationId, 'machinery-operations', [
                    'machinery-operations.view',
                ])) {
                    return null;
                }

                return $this->buildMachineryOperationsWidget($organizationId);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'production_labor',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowModule($modules, $organizationId, 'production-labor', [
                    'production-labor.view',
                ])) {
                    return null;
                }

                return $this->buildProductionLaborWidget($organizationId);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'workforce_management',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowModule($modules, $organizationId, 'workforce-management', [
                    'workforce.view',
                ])) {
                    return null;
                }

                return $this->buildWorkforceManagementWidget($organizationId);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'handover_acceptance',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowModule($modules, $organizationId, 'handover-acceptance', [
                    'handover-acceptance.view',
                ])) {
                    return null;
                }

                return $this->buildHandoverAcceptanceWidget($organizationId);
            }
        );

        $this->appendWidget(
            $widgets,
            $unavailableWidgets,
            'procurement',
            $organizationId,
            (int) $user->id,
            function () use ($modules, $organizationId): ?array {
                if (!$this->canShowModule($modules, $organizationId, 'procurement', [
                    'procurement.view',
                    'procurement.purchase_requests.view',
                    'procurement.purchase_orders.view',
                    'procurement.approvals.view',
                ])) {
                    return null;
                }

                return $this->buildProcurementWidget($organizationId);
            }
        );

        return [
            'widgets' => $widgets,
            'meta' => [
                'organization_id' => $organizationId,
                'roles' => $roles,
                'modules' => array_keys($modules),
                'partial' => $unavailableWidgets !== [],
                'unavailable_widgets' => $unavailableWidgets,
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function canShowProjectOverview(array $modules, int $organizationId): bool
    {
        return $this->canShowModule($modules, $organizationId, 'project-management', [
            'projects.view',
            'projects.view_assigned',
            'projects.update_progress',
        ]);
    }

    private function canShowSiteRequests(array $modules, int $organizationId): bool
    {
        return $this->canShowModule($modules, $organizationId, 'site-requests', [
            'site_requests.view',
            'site_requests.create',
            'site_requests.edit',
            'site_requests.files.upload',
        ]);
    }

    private function canShowApprovals(array $modules, int $organizationId): bool
    {
        return $this->canShowModule($modules, $organizationId, 'site-requests', [
            'site_requests.approve',
            'site_requests.assign',
            'site_requests.change_status',
            'site_requests.statistics',
        ]);
    }

    private function canShowModule(
        array $modules,
        int $organizationId,
        string $permissionSlug,
        array $expectedPermissions = [],
        ?string $accessSlug = null
    ): bool {
        $grantedPermissions = $modules[$permissionSlug] ?? null;

        if (!is_array($grantedPermissions) || $grantedPermissions === []) {
            return false;
        }

        if (!$this->accessController->hasModuleAccess($organizationId, $accessSlug ?? $permissionSlug)) {
            return false;
        }

        return $expectedPermissions === [] || $this->hasAnyPermission($grantedPermissions, $expectedPermissions);
    }

    private function buildProjectOverviewWidget(array $modules, array $roles): array
    {
        return $this->widget(
            slug: 'project_overview',
            status: self::STATUS_ACTIVE,
            primaryMetric: $this->metric('available_sections', count($modules)),
            secondaryMetric: $this->metric('roles', count($roles)),
            route: 'project_selection'
        );
    }

    private function buildSiteRequestsWidget(int $organizationId, int $userId): array
    {
        $query = SiteRequest::query()
            ->forOrganization($organizationId)
            ->forUser($userId);
        $activeCount = (int) (clone $query)->active()->count();
        $overdueCount = (int) (clone $query)->overdue()->count();

        return $this->widget(
            slug: 'site_requests',
            status: $overdueCount > 0
                ? self::STATUS_CRITICAL
                : ($activeCount > 0 ? self::STATUS_ATTENTION : self::STATUS_OK),
            primaryMetric: $this->metric('active_requests', $activeCount),
            secondaryMetric: $this->metric('overdue_requests', $overdueCount),
            route: 'site_requests',
            updatedAt: $this->latestIso([(clone $query)->max('updated_at')])
        );
    }

    private function buildApprovalsWidget(int $organizationId): array
    {
        $query = SiteRequest::query()->forOrganization($organizationId);
        $pendingCount = (int) (clone $query)->withStatus(SiteRequestStatusEnum::PENDING)->count();
        $inReviewCount = (int) (clone $query)->withStatus(SiteRequestStatusEnum::IN_REVIEW)->count();

        return $this->widget(
            slug: 'site_request_approvals',
            status: $pendingCount + $inReviewCount > 0 ? self::STATUS_ATTENTION : self::STATUS_OK,
            primaryMetric: $this->metric('pending_requests', $pendingCount),
            secondaryMetric: $this->metric('review_requests', $inReviewCount),
            route: 'site_request_approvals',
            updatedAt: $this->latestIso([(clone $query)->max('updated_at')])
        );
    }

    private function buildWarehouseWidget(int $organizationId): array
    {
        $warehousesQuery = OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->active();
        $balancesQuery = WarehouseBalance::query()->where('organization_id', $organizationId);
        $movementsQuery = WarehouseMovement::query()->where('organization_id', $organizationId);
        $warehouseCount = (int) (clone $warehousesQuery)->count();
        $lowStockCount = (int) (clone $balancesQuery)->lowStock()->count();
        $recentMovementsCount = (int) (clone $movementsQuery)
            ->where('movement_date', '>=', now()->subDays(7))
            ->count();

        return $this->widget(
            slug: 'warehouse',
            status: $lowStockCount > 0
                ? self::STATUS_ATTENTION
                : ($recentMovementsCount > 0 ? self::STATUS_ACTIVE : self::STATUS_OK),
            primaryMetric: $this->metric('warehouses', $warehouseCount),
            secondaryMetric: $this->metric('low_stock', $lowStockCount),
            route: 'warehouse',
            updatedAt: $this->latestIso([
                (clone $warehousesQuery)->max('updated_at'),
                (clone $balancesQuery)->max('last_movement_at'),
                (clone $movementsQuery)->max('updated_at'),
            ])
        );
    }

    private function buildScheduleWidget(int $organizationId): array
    {
        $today = now()->toDateString();
        $upcomingTo = now()->addDays(7)->toDateString();
        $activeStatuses = ['scheduled', 'in_progress'];
        $query = ProjectEvent::query()->where('organization_id', $organizationId);
        $upcomingCount = (int) (clone $query)
            ->whereBetween('event_date', [$today, $upcomingTo])
            ->whereIn('status', $activeStatuses)
            ->count();
        $blockingCount = (int) (clone $query)
            ->whereBetween('event_date', [$today, $upcomingTo])
            ->whereIn('status', $activeStatuses)
            ->where('is_blocking', true)
            ->count();

        return $this->widget(
            slug: 'schedule',
            status: $blockingCount > 0
                ? self::STATUS_CRITICAL
                : ($upcomingCount > 0 ? self::STATUS_ATTENTION : self::STATUS_OK),
            primaryMetric: $this->metric('upcoming_events', $upcomingCount),
            secondaryMetric: $this->metric('blocking_events', $blockingCount),
            route: 'schedule',
            updatedAt: $this->latestIso([(clone $query)->max('updated_at')])
        );
    }

    private function buildAiAssistantWidget(int $organizationId, int $userId): array
    {
        $query = AiConversation::query()
            ->forOrganization($organizationId)
            ->forUser($userId);
        $conversationCount = (int) (clone $query)->count();
        $recentConversationCount = (int) (clone $query)
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();

        return $this->widget(
            slug: 'ai_assistant',
            status: $conversationCount > 0 ? self::STATUS_ACTIVE : self::STATUS_OK,
            primaryMetric: $this->metric('conversations', $conversationCount),
            secondaryMetric: $this->metric('recent_conversations', $recentConversationCount),
            route: 'ai_assistant',
            updatedAt: $this->latestIso([(clone $query)->max('updated_at')])
        );
    }

    private function buildConstructionJournalWidget(int $organizationId): array
    {
        $journalQuery = ConstructionJournal::query()->byOrganization($organizationId);
        $entryQuery = ConstructionJournalEntry::query()
            ->whereHas('journal', static function ($query) use ($organizationId): void {
                $query->byOrganization($organizationId);
            });
        $activeJournalsCount = (int) (clone $journalQuery)
            ->where('status', JournalStatusEnum::ACTIVE->value)
            ->count();
        $submittedEntriesCount = (int) (clone $entryQuery)
            ->where('status', JournalEntryStatusEnum::SUBMITTED->value)
            ->count();

        return $this->widget(
            slug: 'construction_journal',
            status: $submittedEntriesCount > 0
                ? self::STATUS_ATTENTION
                : ($activeJournalsCount > 0 ? self::STATUS_ACTIVE : self::STATUS_OK),
            primaryMetric: $this->metric('active_journals', $activeJournalsCount),
            secondaryMetric: $this->metric('submitted_entries', $submittedEntriesCount),
            route: 'construction_journal',
            updatedAt: $this->latestIso([
                (clone $journalQuery)->max('updated_at'),
                (clone $entryQuery)->max('updated_at'),
            ])
        );
    }

    private function buildQualityControlWidget(int $organizationId): array
    {
        $openStatuses = [
            QualityDefectStatusEnum::OPEN->value,
            QualityDefectStatusEnum::ASSIGNED->value,
            QualityDefectStatusEnum::IN_PROGRESS->value,
            QualityDefectStatusEnum::READY_FOR_REVIEW->value,
            QualityDefectStatusEnum::REJECTED->value,
        ];
        $query = QualityDefect::query()->forOrganization($organizationId);
        $openCount = (int) (clone $query)->whereIn('status', $openStatuses)->count();
        $overdueCount = (int) (clone $query)
            ->whereIn('status', $openStatuses)
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        return $this->widget(
            slug: 'quality_control',
            status: $overdueCount > 0
                ? self::STATUS_CRITICAL
                : ($openCount > 0 ? self::STATUS_ATTENTION : self::STATUS_OK),
            primaryMetric: $this->metric('open_defects', $openCount),
            secondaryMetric: $this->metric('overdue_defects', $overdueCount),
            route: 'quality-control',
            updatedAt: $this->latestIso([(clone $query)->max('updated_at')])
        );
    }

    private function buildSafetyManagementWidget(int $organizationId): array
    {
        $permitsQuery = SafetyWorkPermit::query()->forOrganization($organizationId);
        $incidentsQuery = SafetyIncident::query()->forOrganization($organizationId);
        $violationsQuery = SafetyViolation::query()->forOrganization($organizationId);
        $activePermitsCount = (int) (clone $permitsQuery)
            ->whereIn('status', ['approved', 'active', 'suspended'])
            ->count();
        $openIncidentsCount = (int) (clone $incidentsQuery)
            ->whereIn('status', ['reported', 'triage', 'investigation', 'corrective_actions'])
            ->count();
        $openViolationsCount = (int) (clone $violationsQuery)
            ->whereIn('status', ['open'])
            ->count();
        $openItemsCount = $openIncidentsCount + $openViolationsCount;

        return $this->widget(
            slug: 'safety_management',
            status: $openItemsCount > 0
                ? self::STATUS_CRITICAL
                : ($activePermitsCount > 0 ? self::STATUS_ATTENTION : self::STATUS_OK),
            primaryMetric: $this->metric('active_permits', $activePermitsCount),
            secondaryMetric: $this->metric('open_hse_items', $openItemsCount),
            route: 'safety-management',
            updatedAt: $this->latestIso([
                (clone $permitsQuery)->max('updated_at'),
                (clone $incidentsQuery)->max('updated_at'),
                (clone $violationsQuery)->max('updated_at'),
            ])
        );
    }

    private function buildMachineryOperationsWidget(int $organizationId): array
    {
        $assetsQuery = MachineryAsset::query()->forOrganization($organizationId);
        $shiftsQuery = MachineryShiftReport::query()->forOrganization($organizationId);
        $activeAssetsCount = (int) (clone $assetsQuery)
            ->whereIn('status', ['assigned', 'in_operation'])
            ->count();
        $submittedShiftsCount = (int) (clone $shiftsQuery)
            ->where('status', 'submitted')
            ->count();
        $unavailableAssetsCount = (int) (clone $assetsQuery)
            ->whereIn('status', ['maintenance', 'unavailable'])
            ->count();

        return $this->widget(
            slug: 'machinery_operations',
            status: $unavailableAssetsCount > 0
                ? self::STATUS_CRITICAL
                : ($activeAssetsCount + $submittedShiftsCount > 0 ? self::STATUS_ATTENTION : self::STATUS_OK),
            primaryMetric: $this->metric('active_assets', $activeAssetsCount),
            secondaryMetric: $this->metric('submitted_shifts', $submittedShiftsCount),
            route: 'machinery-operations',
            updatedAt: $this->latestIso([
                (clone $assetsQuery)->max('updated_at'),
                (clone $shiftsQuery)->max('updated_at'),
            ])
        );
    }

    private function buildProductionLaborWidget(int $organizationId): array
    {
        $query = ProductionLaborWorkOrder::query()->forOrganization($organizationId);
        $openOrdersCount = (int) (clone $query)
            ->whereIn('status', ['draft', 'issued', 'in_progress', 'returned'])
            ->count();
        $submittedOrdersCount = (int) (clone $query)
            ->where('status', 'submitted')
            ->count();

        return $this->widget(
            slug: 'production_labor',
            status: $submittedOrdersCount > 0
                ? self::STATUS_ATTENTION
                : ($openOrdersCount > 0 ? self::STATUS_ACTIVE : self::STATUS_OK),
            primaryMetric: $this->metric('open_work_orders', $openOrdersCount),
            secondaryMetric: $this->metric('submitted_work_orders', $submittedOrdersCount),
            route: 'production-labor',
            updatedAt: $this->latestIso([(clone $query)->max('updated_at')])
        );
    }

    private function buildWorkforceManagementWidget(int $organizationId): array
    {
        $query = WorkforceEmployee::query()->where('organization_id', $organizationId);
        $employeesCount = (int) (clone $query)->count();
        $activeEmployeesCount = (int) (clone $query)->where('employment_status', 'active')->count();

        return $this->widget(
            slug: 'workforce_management',
            status: $activeEmployeesCount > 0 ? self::STATUS_ACTIVE : self::STATUS_OK,
            primaryMetric: $this->metric('employees', $employeesCount),
            secondaryMetric: $this->metric('active_employees', $activeEmployeesCount),
            route: 'workforce-management',
            updatedAt: $this->latestIso([(clone $query)->max('updated_at')])
        );
    }

    private function buildHandoverAcceptanceWidget(int $organizationId): array
    {
        $scopesQuery = AcceptanceScope::query()->where('organization_id', $organizationId);
        $findingsQuery = AcceptanceFinding::query()->where('organization_id', $organizationId);
        $activeScopesCount = (int) (clone $scopesQuery)
            ->whereIn('status', ['draft', 'inspection', 'pending_customer', 'returned'])
            ->count();
        $openFindingsCount = (int) (clone $findingsQuery)
            ->whereIn('status', ['open', 'in_progress', 'rejected'])
            ->count();

        return $this->widget(
            slug: 'handover_acceptance',
            status: $openFindingsCount > 0
                ? self::STATUS_CRITICAL
                : ($activeScopesCount > 0 ? self::STATUS_ATTENTION : self::STATUS_OK),
            primaryMetric: $this->metric('active_scopes', $activeScopesCount),
            secondaryMetric: $this->metric('open_findings', $openFindingsCount),
            route: 'handover-acceptance',
            updatedAt: $this->latestIso([
                (clone $scopesQuery)->max('updated_at'),
                (clone $findingsQuery)->max('updated_at'),
            ])
        );
    }

    private function buildProcurementWidget(int $organizationId): array
    {
        $requestsQuery = PurchaseRequest::query()->forOrganization($organizationId);
        $ordersQuery = PurchaseOrder::query()->forOrganization($organizationId);
        $approvalsQuery = ProcurementApproval::query()->forOrganization($organizationId);
        $pendingRequestsCount = (int) (clone $requestsQuery)
            ->where('status', PurchaseRequestStatusEnum::PENDING->value)
            ->count();
        $receivableOrdersCount = (int) (clone $ordersQuery)
            ->whereIn('status', [
                PurchaseOrderStatusEnum::CONFIRMED->value,
                PurchaseOrderStatusEnum::IN_DELIVERY->value,
                PurchaseOrderStatusEnum::PARTIALLY_DELIVERED->value,
            ])
            ->count();
        $pendingApprovalsCount = (int) (clone $approvalsQuery)
            ->where('status', ProcurementApprovalStatusEnum::PENDING->value)
            ->count();

        return $this->widget(
            slug: 'procurement',
            status: $pendingApprovalsCount + $receivableOrdersCount > 0
                ? self::STATUS_ATTENTION
                : ($pendingRequestsCount > 0 ? self::STATUS_ACTIVE : self::STATUS_OK),
            primaryMetric: $this->metric('pending_procurement_approvals', $pendingApprovalsCount),
            secondaryMetric: $this->metric('receivable_purchase_orders', $receivableOrdersCount),
            route: 'procurement',
            updatedAt: $this->latestIso([
                (clone $requestsQuery)->max('updated_at'),
                (clone $ordersQuery)->max('updated_at'),
                (clone $approvalsQuery)->max('updated_at'),
            ])
        );
    }

    private function widget(
        string $slug,
        string $status,
        array $primaryMetric,
        array $secondaryMetric,
        string $route,
        ?string $updatedAt = null
    ): array {
        return [
            'slug' => $slug,
            'title' => trans_message('mobile_dashboard.widgets.' . $slug . '.title'),
            'status' => $status,
            'primary_metric' => $primaryMetric,
            'secondary_metric' => $secondaryMetric,
            'route' => $route,
            'updated_at' => $updatedAt ?? now()->toIso8601String(),
        ];
    }

    private function metric(string $key, int|float|string $value): array
    {
        return [
            'label' => trans_message('mobile_dashboard.metrics.' . $key),
            'value' => $value,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $widgets
     * @param list<string> $unavailableWidgets
     * @param callable(): array<string, mixed>|null $builder
     */
    private function appendWidget(
        array &$widgets,
        array &$unavailableWidgets,
        string $slug,
        int $organizationId,
        int $userId,
        callable $builder
    ): void {
        try {
            $widget = $builder();

            if ($widget !== null) {
                $widgets[] = $widget;
            }
        } catch (Throwable $exception) {
            $unavailableWidgets[] = $slug;

            Log::warning('mobile.dashboard.widget_unavailable', [
                'widget' => $slug,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'error' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
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

    private function latestIso(array $values): string
    {
        $latest = null;

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $date = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value);

            if ($latest === null || $date->greaterThan($latest)) {
                $latest = $date;
            }
        }

        return ($latest ?? now())->toIso8601String();
    }
}
