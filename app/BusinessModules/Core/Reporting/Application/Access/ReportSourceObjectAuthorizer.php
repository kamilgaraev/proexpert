<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;

final readonly class ReportSourceObjectAuthorizer
{
    public function __construct(
        private AuthorizationService $authorization,
        private UserProjectAccessService $projectAccess,
        private ReportSourceObjectReader $sources,
    ) {}

    private const PERMISSIONS = [
        'acceptance_checklist_item' => ['reports.project_readiness.view'],
        'acceptance_finding' => ['reports.project_readiness.view'],
        'acceptance_scope' => ['reports.project_readiness.view'],
        'baseline_schedule_variance' => ['contractor_marketplace.profile.view'],
        'change' => [
            'change-management.changes.create',
            'change-management.changes.approve',
            'change-management.changes.implement',
        ],
        'change_request' => [
            'change-management.changes.create',
            'change-management.changes.approve',
            'change-management.changes.implement',
        ],
        'constraint' => ['schedule.view'],
        'customer_issue' => ['customer.issues.view'],
        'customer_request' => ['customer.requests.view'],
        'handover_document' => ['reports.project_readiness.view'],
        'document' => ['reports.project_readiness.view'],
        'inspection' => ['reports.project_readiness.view'],
        'marketplace_review' => ['contractor_marketplace.profile.view'],
        'quality_defect' => ['quality-control.defects.view'],
        'quality_defect_flow' => ['contractor_marketplace.profile.view'],
        'rfi' => ['change-management.rfi.create', 'change-management.rfi.answer'],
        'safety_incident_actions' => ['contractor_marketplace.profile.view'],
        'supply_reliability' => ['contractor_marketplace.profile.view'],
    ];

    public function availability(
        ReportExecutionContext $context,
        string $sourceType,
        int|string $sourceId,
        int $organizationId,
        ?int $projectId,
    ): string {
        if (
            $organizationId !== $context->scope->organizationId
            || ($projectId !== null
                && $context->scope->projectIds !== []
                && ! in_array($projectId, $context->scope->projectIds, true))
        ) {
            return 'forbidden';
        }

        $permissions = self::PERMISSIONS[$sourceType] ?? [];
        if ($permissions === [] || ! $this->hasAnyPermission(
            $context,
            $permissions,
            $sourceType,
            $sourceId,
            $organizationId,
            $projectId,
        )) {
            return 'forbidden';
        }

        return $this->sources->exists($sourceType, $sourceId, $organizationId, $projectId)
            ? 'available'
            : 'missing';
    }

    private function hasAnyPermission(
        ReportExecutionContext $context,
        array $permissions,
        string $sourceType,
        int|string $sourceId,
        int $organizationId,
        ?int $projectId,
    ): bool {
        $actor = $this->sources->actor($context->actor->id);
        if (! $actor instanceof User) {
            return false;
        }
        if ($projectId !== null) {
            $project = $this->sources->project($projectId);
            if (
                ! $project instanceof Project
                || ! $this->projectAccess->canAccessProject($actor, $project, $organizationId)
            ) {
                return false;
            }
        }
        $authorizationContext = [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ];
        foreach ($permissions as $permission) {
            if (
                in_array($permission, $context->actor->permissionSlugs, true)
                && $this->authorization->can($actor, $permission, $authorizationContext)
            ) {
                return true;
            }
        }

        return false;
    }
}
