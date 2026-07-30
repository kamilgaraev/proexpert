<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Access;

use Illuminate\Support\Facades\DB;

final class ProductionReportScopedResourceAuthorizers
{
    public function handlers(): array
    {
        return [
            $this->direct('quality_defect', 'quality_defects'),
            $this->scheduleTask('schedule_task'),
            $this->scheduleTask('task'),
            $this->contractor(),
            $this->qualityPhoto(),
            $this->qualityStatusComment(),
            $this->direct('safety_incident', 'safety_incidents'),
            $this->direct('safety_violation', 'safety_violations'),
            $this->direct('safety_corrective_action', 'safety_corrective_actions'),
            $this->direct('safety_site', 'safety_sites'),
            $this->workforceAssignment(),
            $this->workforceEmployee(),
            $this->workforceSnapshotRelation(),
            $this->workforceSnapshotEvidence(),
            $this->direct('incident_closure', 'safety_incidents'),
            $this->direct('incident_cancellation', 'safety_incidents'),
            $this->violationResolution(),
            $this->direct('corrective_action_verification', 'safety_corrective_actions'),
            $this->workforceEvidence('employee_requirement', 'safety_employee_requirements'),
            $this->workforceEvidence('training', 'safety_training_records'),
            $this->workforceEvidence('medical_exam', 'safety_medical_exams'),
            $this->workforceEvidence('ppe', 'safety_ppe_issues'),
            $this->briefingEvidence(),
        ];
    }

    private function direct(string $kind, string $table): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            $kind,
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table($table)
                ->where('id', $id)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->exists(),
        );
    }

    private function scheduleTask(string $kind): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            $kind,
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('schedule_tasks as task')
                ->join('project_schedules as schedule', 'schedule.id', '=', 'task.schedule_id')
                ->where('task.id', $id)
                ->where('task.organization_id', $organizationId)
                ->where('schedule.organization_id', $organizationId)
                ->where('schedule.project_id', $projectId)
                ->exists(),
        );
    }

    private function contractor(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'contractor',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('contractors')
                ->where('id', $id)
                ->where('organization_id', $organizationId)
                ->exists()
                && (
                    DB::table('quality_defects')
                        ->where('organization_id', $organizationId)
                        ->where('project_id', $projectId)
                        ->where('contractor_id', $id)
                        ->exists()
                    || self::safetySubjectUsesContractor(
                        'safety_incidents',
                        $organizationId,
                        $projectId,
                        $id,
                    )
                    || self::safetySubjectUsesContractor(
                        'safety_violations',
                        $organizationId,
                        $projectId,
                        $id,
                    )
                    || self::safetySubjectUsesContractor(
                        'safety_corrective_actions',
                        $organizationId,
                        $projectId,
                        $id,
                    )
                ),
        );
    }

    private function qualityPhoto(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'quality_defect_photo',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('quality_defect_photos as photo')
                ->join('quality_defects as defect', 'defect.id', '=', 'photo.quality_defect_id')
                ->where('photo.id', $id)
                ->where('photo.organization_id', $organizationId)
                ->where('defect.organization_id', $organizationId)
                ->where('defect.project_id', $projectId)
                ->exists(),
        );
    }

    private function qualityStatusComment(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'status_comment',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('quality_defect_status_history as history')
                ->join('quality_defects as defect', 'defect.id', '=', 'history.quality_defect_id')
                ->where('history.id', $id)
                ->whereNotNull('history.comment')
                ->where('defect.organization_id', $organizationId)
                ->where('defect.project_id', $projectId)
                ->exists(),
        );
    }

    private function workforceEmployee(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'workforce_employee',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('workforce_employees as employee')
                ->join('safety_site_workforce_assignments as mapping', 'mapping.employee_id', '=', 'employee.id')
                ->join('workforce_employee_assignments as assignment', 'assignment.id', '=', 'mapping.workforce_assignment_id')
                ->join('safety_sites as site', 'site.id', '=', 'mapping.safety_site_id')
                ->where('employee.id', $id)
                ->where('employee.organization_id', $organizationId)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
                ->where('assignment.organization_id', $organizationId)
                ->where('assignment.project_id', $projectId)
                ->where('assignment.employee_id', $id)
                ->where('assignment.status', 'active')
                ->whereNull('assignment.deleted_at')
                ->whereDate('mapping.valid_from', '<=', now()->toDateString())
                ->where(static fn ($query) => $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', now()->toDateString()))
                ->where('site.organization_id', $organizationId)
                ->where('site.project_id', $projectId)
                ->where('site.is_active', true)
                ->exists(),
        );
    }

    private function workforceAssignment(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'workforce_assignment',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('workforce_employee_assignments as assignment')
                ->join(
                    'safety_site_workforce_assignments as mapping',
                    'mapping.workforce_assignment_id',
                    '=',
                    'assignment.id',
                )
                ->join('safety_sites as site', 'site.id', '=', 'mapping.safety_site_id')
                ->where('assignment.id', $id)
                ->where('assignment.organization_id', $organizationId)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
                ->where('assignment.project_id', $projectId)
                ->where('assignment.status', 'active')
                ->whereNull('assignment.deleted_at')
                ->whereDate('mapping.valid_from', '<=', now()->toDateString())
                ->where(static fn ($query) => $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', now()->toDateString()))
                ->where('site.organization_id', $organizationId)
                ->where('site.project_id', $projectId)
                ->where('site.is_active', true)
                ->exists(),
        );
    }

    private function workforceEvidence(string $kind, string $table): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            $kind,
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table($table.' as evidence')
                ->join('safety_site_workforce_assignments as mapping', 'mapping.employee_id', '=', 'evidence.employee_id')
                ->join('workforce_employee_assignments as assignment', 'assignment.id', '=', 'mapping.workforce_assignment_id')
                ->join('safety_sites as site', 'site.id', '=', 'mapping.safety_site_id')
                ->where('evidence.id', $id)
                ->where('evidence.organization_id', $organizationId)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
                ->where('assignment.organization_id', $organizationId)
                ->where('assignment.project_id', $projectId)
                ->whereColumn('assignment.employee_id', 'evidence.employee_id')
                ->where('assignment.status', 'active')
                ->whereNull('assignment.deleted_at')
                ->whereDate('mapping.valid_from', '<=', now()->toDateString())
                ->where(static fn ($query) => $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', now()->toDateString()))
                ->where('site.organization_id', $organizationId)
                ->where('site.project_id', $projectId)
                ->where('site.is_active', true)
                ->exists(),
        );
    }

    private function workforceSnapshotRelation(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'workforce_assignment_site',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('safety_site_workforce_assignments as mapping')
                ->join('workforce_employee_assignments as assignment', 'assignment.id', '=', 'mapping.workforce_assignment_id')
                ->join('safety_sites as site', 'site.id', '=', 'mapping.safety_site_id')
                ->where('mapping.id', $id)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
                ->whereDate('mapping.valid_from', '<=', now()->toDateString())
                ->where(static fn ($query) => $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', now()->toDateString()))
                ->where('assignment.organization_id', $organizationId)
                ->where('assignment.project_id', $projectId)
                ->where('assignment.status', 'active')
                ->whereNull('assignment.deleted_at')
                ->where('site.organization_id', $organizationId)
                ->where('site.project_id', $projectId)
                ->where('site.is_active', true)
                ->exists(),
        );
    }

    private function workforceSnapshotEvidence(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'workforce_snapshot_evidence',
            static fn (int $organizationId, int $projectId, int $id): bool => self::workforceSnapshotEvidenceExists(
                $organizationId,
                $projectId,
                $id,
            ),
        );
    }

    private static function workforceSnapshotEvidenceExists(
        int $organizationId,
        int $projectId,
        int $rowId,
    ): bool {
        $row = DB::table('safety_admission_rows')
            ->where('id', $rowId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->first([
                'employee_id',
                'evidence_id',
                'evidence_type',
                'safety_site_id',
                'site_assignment_id',
                'workforce_assignment_id',
            ]);
        if ($row === null
            || ! DB::table('safety_site_workforce_assignments as mapping')
                ->join('workforce_employee_assignments as assignment', 'assignment.id', '=', 'mapping.workforce_assignment_id')
                ->join('safety_sites as site', 'site.id', '=', 'mapping.safety_site_id')
                ->where('mapping.id', $row->site_assignment_id)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
                ->where('mapping.safety_site_id', $row->safety_site_id)
                ->where('mapping.workforce_assignment_id', $row->workforce_assignment_id)
                ->where('mapping.employee_id', $row->employee_id)
                ->whereDate('mapping.valid_from', '<=', now()->toDateString())
                ->where(static fn ($query) => $query->whereNull('mapping.valid_to')->orWhereDate('mapping.valid_to', '>=', now()->toDateString()))
                ->where('assignment.organization_id', $organizationId)
                ->where('assignment.project_id', $projectId)
                ->where('assignment.employee_id', $row->employee_id)
                ->where('assignment.status', 'active')
                ->whereNull('assignment.deleted_at')
                ->where('site.organization_id', $organizationId)
                ->where('site.project_id', $projectId)
                ->where('site.is_active', true)
                ->exists()) {
            return false;
        }
        if ($row->evidence_id === null) {
            return $row->evidence_type === null;
        }
        if ($row->evidence_type === 'briefing') {
            return DB::table('safety_briefing_participants as participant')
                ->join('safety_briefings as briefing', 'briefing.id', '=', 'participant.briefing_id')
                ->where('participant.id', $row->evidence_id)
                ->where('participant.employee_id', $row->employee_id)
                ->where('briefing.organization_id', $organizationId)
                ->where('briefing.project_id', $projectId)
                ->exists();
        }
        $table = match ($row->evidence_type) {
            'employee_requirement' => 'safety_employee_requirements',
            'training' => 'safety_training_records',
            'medical_exam' => 'safety_medical_exams',
            'ppe' => 'safety_ppe_issues',
            default => null,
        };

        return $table !== null && DB::table($table)
            ->where('id', $row->evidence_id)
            ->where('organization_id', $organizationId)
            ->where('employee_id', $row->employee_id)
            ->exists();
    }

    private function briefingEvidence(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'briefing',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('safety_briefing_participants as participant')
                ->join('safety_briefings as briefing', 'briefing.id', '=', 'participant.briefing_id')
                ->where('participant.id', $id)
                ->where('briefing.organization_id', $organizationId)
                ->where('briefing.project_id', $projectId)
                ->exists(),
        );
    }

    private function violationResolution(): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            'violation_resolution',
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table('safety_violations')
                ->where('id', $id)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->where('status', 'resolved')
                ->whereNotNull('resolved_at')
                ->whereNotNull('resolution_comment')
                ->whereRaw("btrim(resolution_comment) <> ''")
                ->exists(),
        );
    }

    private static function safetySubjectUsesContractor(
        string $table,
        int $organizationId,
        int $projectId,
        int $contractorId,
    ): bool {
        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('metadata->contractor_id', $contractorId)
            ->exists();
    }
}
