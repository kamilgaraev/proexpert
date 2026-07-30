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
            $this->direct('incident_closure', 'safety_incidents'),
            $this->direct('incident_cancellation', 'safety_incidents'),
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
                && self::activeProject($organizationId, $projectId),
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
                ->where('employee.id', $id)
                ->where('employee.organization_id', $organizationId)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
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
                ->where('assignment.id', $id)
                ->where('assignment.organization_id', $organizationId)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
                ->exists(),
        );
    }

    private function workforceEvidence(string $kind, string $table): QueryReportScopedResourceAuthorizer
    {
        return new QueryReportScopedResourceAuthorizer(
            $kind,
            static fn (int $organizationId, int $projectId, int $id): bool => DB::table($table.' as evidence')
                ->join('safety_site_workforce_assignments as mapping', 'mapping.employee_id', '=', 'evidence.employee_id')
                ->where('evidence.id', $id)
                ->where('evidence.organization_id', $organizationId)
                ->where('mapping.organization_id', $organizationId)
                ->where('mapping.project_id', $projectId)
                ->exists(),
        );
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

    private static function activeProject(int $organizationId, int $projectId): bool
    {
        return DB::table('projects')
            ->where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->where('is_archived', false)
            ->exists();
    }
}
