<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportSourceObjectReader
{
    public function actor(int $actorId): ?User
    {
        return User::query()->find($actorId);
    }

    public function project(int $projectId): ?Project
    {
        return Project::query()->find($projectId);
    }

    public function exists(
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
