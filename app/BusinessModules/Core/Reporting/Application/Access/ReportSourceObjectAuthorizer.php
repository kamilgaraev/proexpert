<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class ReportSourceObjectAuthorizer
{
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
        if ($permissions === [] || ! $this->hasAnyPermission($context, $permissions)) {
            return 'forbidden';
        }

        return $this->exists($sourceType, $sourceId, $organizationId, $projectId)
            ? 'available'
            : 'missing';
    }

    private function hasAnyPermission(ReportExecutionContext $context, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (in_array($permission, $context->actor->permissionSlugs, true)) {
                return true;
            }
        }

        return false;
    }

    private function exists(
        string $sourceType,
        int|string $sourceId,
        int $organizationId,
        ?int $projectId,
    ): bool {
        $query = match ($sourceType) {
            'acceptance_scope', 'inspection' => DB::table('acceptance_scopes')->where('id', $sourceId),
            'acceptance_checklist_item' => DB::table('acceptance_checklist_items as item')
                ->join('acceptance_checklists as checklist', 'checklist.id', '=', 'item.acceptance_checklist_id')
                ->where('item.id', $sourceId)
                ->select('checklist.organization_id', 'checklist.project_id'),
            'acceptance_finding' => DB::table('acceptance_findings')->where('id', $sourceId),
            'baseline_schedule_variance' => DB::table('baseline_schedule_variance_rows')
                ->join(
                    'project_schedules as schedule',
                    'schedule.id',
                    '=',
                    'baseline_schedule_variance_rows.schedule_id',
                )
                ->where('baseline_schedule_variance_rows.id', $sourceId),
            'change', 'change_request' => DB::table('change_management_change_requests')->where('id', $sourceId),
            'constraint' => DB::table('work_constraints')->where('id', $sourceId),
            'customer_issue' => DB::table('customer_issues')->where('id', $sourceId),
            'customer_request' => DB::table('customer_requests')->where('id', $sourceId),
            'handover_document', 'document' => DB::table('handover_package_documents as document')
                ->join('handover_packages as package', 'package.id', '=', 'document.handover_package_id')
                ->where('document.id', $sourceId)
                ->select('package.organization_id', 'package.project_id'),
            'marketplace_review' => DB::table('marketplace_hiring_offer_reviews')
                ->where('id', $sourceId)
                ->selectRaw('reviewer_organization_id AS organization_id, project_id'),
            'quality_defect' => DB::table('quality_defects')->where('id', $sourceId),
            'quality_defect_flow' => DB::table('quality_defect_flow_rows')->where('id', $sourceId),
            'rfi' => DB::table('change_management_rfis')->where('id', $sourceId),
            'safety_incident_actions' => DB::table('safety_incident_rows')->where('id', $sourceId),
            'supply_reliability' => DB::table('supply_reliability_rows')->where('id', $sourceId),
            default => null,
        };
        if (! $query instanceof Builder) {
            return false;
        }

        [$organizationColumn, $projectColumn] = $this->scopeColumns($sourceType);
        $query->where($organizationColumn, $organizationId);
        if ($projectId !== null && $projectColumn !== null) {
            $query->where($projectColumn, $projectId);
        }

        return $query->exists();
    }

    private function scopeColumns(string $sourceType): array
    {
        return match ($sourceType) {
            'acceptance_checklist_item' => ['checklist.organization_id', 'checklist.project_id'],
            'handover_document', 'document' => ['package.organization_id', 'package.project_id'],
            'marketplace_review' => ['reviewer_organization_id', 'project_id'],
            'baseline_schedule_variance' => [
                'baseline_schedule_variance_rows.organization_id',
                'schedule.project_id',
            ],
            default => ['organization_id', 'project_id'],
        };
    }
}
