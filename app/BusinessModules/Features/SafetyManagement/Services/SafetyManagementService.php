<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Services;

use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceContext;
use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceResult;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefingParticipant;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyEmployeeRequirement;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspection;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionFinding;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionItem;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionTemplate;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyMedicalExam;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyPpeIssue;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyRequirementMatrix;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyTrainingRecord;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermitParticipant;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class SafetyManagementService
{
    public function __construct(
        private readonly SafetyComplianceService $complianceService,
    ) {
    }

    public function paginatePermits(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyWorkPermit::forOrganization($organizationId)
            ->with(['project:id,name', 'responsibleUser:id,name', 'participants.employee:id,last_name,first_name,middle_name'])
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function paginateIncidents(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyIncident::forOrganization($organizationId)
            ->with(['project:id,name', 'assignedUser:id,name'])
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(!empty($filters['reported_by_user_id']), fn ($query) => $query->where('reported_by_user_id', (int) $filters['reported_by_user_id']))
            ->orderByDesc('occurred_at')
            ->paginate($perPage);
    }

    public function paginateViolations(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyViolation::forOrganization($organizationId)
            ->with(['project:id,name', 'assignedUser:id,name'])
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(!empty($filters['assigned_to_user_id']), fn ($query) => $query->where(function ($scope) use ($filters): void {
                $scope->where('assigned_to_user_id', (int) $filters['assigned_to_user_id'])
                    ->orWhere('created_by_user_id', (int) $filters['assigned_to_user_id']);
            }))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function paginateBriefings(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyBriefing::forOrganization($organizationId)
            ->with(['project:id,name', 'conductedByUser:id,name', 'participants.user:id,name', 'participants.employee:id,last_name,first_name,middle_name', 'participants.signedByUser:id,name'])
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->orderByDesc('conducted_at')
            ->paginate($perPage);
    }

    public function paginateCorrectiveActions(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyCorrectiveAction::forOrganization($organizationId)
            ->with(['project:id,name', 'assignedUser:id,name'])
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(!empty($filters['incident_id']), fn ($query) => $query->where('incident_id', (int) $filters['incident_id']))
            ->when(!empty($filters['violation_id']), fn ($query) => $query->where('violation_id', (int) $filters['violation_id']))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function paginateRequirementMatrices(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyRequirementMatrix::forOrganization($organizationId)
            ->with(['project:id,name', 'workType:id,name,code'])
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['work_type_id']), fn ($query) => $query->where('work_type_id', (int) $filters['work_type_id']))
            ->when(!empty($filters['work_category']), fn ($query) => $query->where('work_category', (string) $filters['work_category']))
            ->when(!empty($filters['risk_level']), fn ($query) => $query->where('risk_level', (string) $filters['risk_level']))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('is_active')
            ->orderBy('work_category')
            ->paginate($perPage);
    }

    public function createRequirementMatrix(int $organizationId, array $data): SafetyRequirementMatrix
    {
        $this->assertOptionalProjectBelongsToOrganization($data['project_id'] ?? null, $organizationId);
        $this->assertOptionalWorkTypeBelongsToOrganization($data['work_type_id'] ?? null, $organizationId);

        return SafetyRequirementMatrix::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $data['project_id'] ?? null,
            'work_type_id' => $data['work_type_id'] ?? null,
            'position_name' => $data['position_name'] ?? null,
            'work_category' => $data['work_category'],
            'risk_level' => $data['risk_level'] ?? 'medium',
            'requirements' => $data['requirements'],
            'is_active' => $data['is_active'] ?? true,
            'effective_from' => $data['effective_from'] ?? now()->toDateString(),
            'effective_until' => $data['effective_until'] ?? null,
        ])->fresh(['project:id,name', 'workType:id,name,code']);
    }

    public function updateRequirementMatrix(SafetyRequirementMatrix $matrix, array $data): SafetyRequirementMatrix
    {
        $organizationId = (int) $matrix->organization_id;

        $this->assertOptionalProjectBelongsToOrganization($data['project_id'] ?? null, $organizationId);
        $this->assertOptionalWorkTypeBelongsToOrganization($data['work_type_id'] ?? null, $organizationId);

        $matrix->fill($data);
        $matrix->save();

        return $matrix->fresh(['project:id,name', 'workType:id,name,code']);
    }

    public function findRequirementMatrix(int $organizationId, int $id): ?SafetyRequirementMatrix
    {
        return SafetyRequirementMatrix::forOrganization($organizationId)
            ->with(['project:id,name', 'workType:id,name,code'])
            ->find($id);
    }

    public function deleteRequirementMatrix(SafetyRequirementMatrix $matrix): void
    {
        $matrix->delete();
    }

    public function paginateEmployeeRequirements(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyEmployeeRequirement::forOrganization($organizationId)
            ->with(['employee:id,last_name,first_name,middle_name', 'user:id,name', 'project:id,name', 'workType:id,name,code'])
            ->when(!empty($filters['employee_id']), fn ($query) => $query->where('employee_id', (int) $filters['employee_id']))
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['work_category']), fn ($query) => $query->where('work_category', (string) $filters['work_category']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderByDesc('valid_until')
            ->paginate($perPage);
    }

    public function createEmployeeRequirement(int $organizationId, array $data): SafetyEmployeeRequirement
    {
        $employee = $this->findEmployee($organizationId, (int) $data['employee_id']);
        $this->assertOptionalUserBelongsToOrganization($data['user_id'] ?? $employee->user_id, $organizationId);
        $this->assertOptionalProjectBelongsToOrganization($data['project_id'] ?? null, $organizationId);
        $this->assertOptionalWorkTypeBelongsToOrganization($data['work_type_id'] ?? null, $organizationId);

        return SafetyEmployeeRequirement::query()->create([
            'organization_id' => $organizationId,
            'employee_id' => $employee->id,
            'user_id' => $data['user_id'] ?? $employee->user_id,
            'project_id' => $data['project_id'] ?? null,
            'work_type_id' => $data['work_type_id'] ?? null,
            'work_category' => $data['work_category'],
            'requirement_code' => $data['requirement_code'],
            'requirement_type' => $data['requirement_type'],
            'source_type' => $data['source_type'] ?? 'manual',
            'source_id' => $data['source_id'] ?? null,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_until' => $data['valid_until'] ?? null,
            'status' => $data['status'] ?? 'valid',
            'metadata' => $data['metadata'] ?? null,
        ])->fresh(['employee:id,last_name,first_name,middle_name', 'user:id,name', 'project:id,name', 'workType:id,name,code']);
    }

    public function updateEmployeeRequirement(SafetyEmployeeRequirement $record, array $data): SafetyEmployeeRequirement
    {
        if (array_key_exists('employee_id', $data)) {
            $this->findEmployee((int) $record->organization_id, (int) $data['employee_id']);
        }

        $this->assertOptionalUserBelongsToOrganization($data['user_id'] ?? null, (int) $record->organization_id);
        $this->assertOptionalProjectBelongsToOrganization($data['project_id'] ?? null, (int) $record->organization_id);
        $this->assertOptionalWorkTypeBelongsToOrganization($data['work_type_id'] ?? null, (int) $record->organization_id);

        $record->fill($data);
        $record->save();

        return $record->fresh(['employee:id,last_name,first_name,middle_name', 'user:id,name', 'project:id,name', 'workType:id,name,code']);
    }

    public function findEmployeeRequirement(int $organizationId, int $id): ?SafetyEmployeeRequirement
    {
        return SafetyEmployeeRequirement::forOrganization($organizationId)
            ->with(['employee:id,last_name,first_name,middle_name', 'user:id,name', 'project:id,name', 'workType:id,name,code'])
            ->find($id);
    }

    public function deleteEmployeeRequirement(SafetyEmployeeRequirement $record): void
    {
        $record->delete();
    }

    public function paginateTrainingRecords(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyTrainingRecord::forOrganization($organizationId)
            ->with(['employee:id,last_name,first_name,middle_name', 'user:id,name'])
            ->when(!empty($filters['employee_id']), fn ($query) => $query->where('employee_id', (int) $filters['employee_id']))
            ->when(!empty($filters['program_code']), fn ($query) => $query->where('program_code', (string) $filters['program_code']))
            ->when(!empty($filters['result']), fn ($query) => $query->where('result', (string) $filters['result']))
            ->orderByDesc('valid_until')
            ->paginate($perPage);
    }

    public function createTrainingRecord(int $organizationId, array $data): SafetyTrainingRecord
    {
        $employee = $this->findEmployee($organizationId, (int) $data['employee_id']);
        $this->assertOptionalUserBelongsToOrganization($data['user_id'] ?? $employee->user_id, $organizationId);

        return SafetyTrainingRecord::query()->create([
            'organization_id' => $organizationId,
            'employee_id' => $employee->id,
            'user_id' => $data['user_id'] ?? $employee->user_id,
            'program_code' => $data['program_code'],
            'program_name' => $data['program_name'],
            'training_type' => $data['training_type'],
            'completed_at' => $data['completed_at'],
            'valid_until' => $data['valid_until'] ?? null,
            'result' => $data['result'] ?? 'passed',
            'document_number' => $data['document_number'] ?? null,
            'protocol_number' => $data['protocol_number'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ])->fresh(['employee:id,last_name,first_name,middle_name', 'user:id,name']);
    }

    public function updateTrainingRecord(SafetyTrainingRecord $record, array $data): SafetyTrainingRecord
    {
        if (array_key_exists('employee_id', $data)) {
            $this->findEmployee((int) $record->organization_id, (int) $data['employee_id']);
        }

        $this->assertOptionalUserBelongsToOrganization($data['user_id'] ?? null, (int) $record->organization_id);
        $record->fill($data);
        $record->save();

        return $record->fresh(['employee:id,last_name,first_name,middle_name', 'user:id,name']);
    }

    public function findTrainingRecord(int $organizationId, int $id): ?SafetyTrainingRecord
    {
        return SafetyTrainingRecord::forOrganization($organizationId)
            ->with(['employee:id,last_name,first_name,middle_name', 'user:id,name'])
            ->find($id);
    }

    public function deleteTrainingRecord(SafetyTrainingRecord $record): void
    {
        $record->delete();
    }

    public function paginateMedicalExams(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyMedicalExam::forOrganization($organizationId)
            ->with(['employee:id,last_name,first_name,middle_name'])
            ->when(!empty($filters['employee_id']), fn ($query) => $query->where('employee_id', (int) $filters['employee_id']))
            ->when(!empty($filters['exam_type']), fn ($query) => $query->where('exam_type', (string) $filters['exam_type']))
            ->when(!empty($filters['result']), fn ($query) => $query->where('result', (string) $filters['result']))
            ->orderByDesc('valid_until')
            ->paginate($perPage);
    }

    public function createMedicalExam(int $organizationId, array $data): SafetyMedicalExam
    {
        $employee = $this->findEmployee($organizationId, (int) $data['employee_id']);

        return SafetyMedicalExam::query()->create([
            'organization_id' => $organizationId,
            'employee_id' => $employee->id,
            'exam_type' => $data['exam_type'],
            'completed_at' => $data['completed_at'],
            'valid_until' => $data['valid_until'] ?? null,
            'result' => $data['result'] ?? 'fit',
            'restrictions' => $data['restrictions'] ?? null,
            'file_id' => $data['file_id'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ])->fresh(['employee:id,last_name,first_name,middle_name']);
    }

    public function updateMedicalExam(SafetyMedicalExam $record, array $data): SafetyMedicalExam
    {
        if (array_key_exists('employee_id', $data)) {
            $this->findEmployee((int) $record->organization_id, (int) $data['employee_id']);
        }

        $record->fill($data);
        $record->save();

        return $record->fresh(['employee:id,last_name,first_name,middle_name']);
    }

    public function findMedicalExam(int $organizationId, int $id): ?SafetyMedicalExam
    {
        return SafetyMedicalExam::forOrganization($organizationId)
            ->with(['employee:id,last_name,first_name,middle_name'])
            ->find($id);
    }

    public function deleteMedicalExam(SafetyMedicalExam $record): void
    {
        $record->delete();
    }

    public function paginatePpeIssues(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyPpeIssue::forOrganization($organizationId)
            ->with(['employee:id,last_name,first_name,middle_name'])
            ->when(!empty($filters['employee_id']), fn ($query) => $query->where('employee_id', (int) $filters['employee_id']))
            ->when(!empty($filters['ppe_code']), fn ($query) => $query->where('ppe_code', (string) $filters['ppe_code']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderByDesc('issued_at')
            ->paginate($perPage);
    }

    public function createPpeIssue(int $organizationId, array $data): SafetyPpeIssue
    {
        $employee = $this->findEmployee($organizationId, (int) $data['employee_id']);

        return SafetyPpeIssue::query()->create([
            'organization_id' => $organizationId,
            'employee_id' => $employee->id,
            'ppe_code' => $data['ppe_code'],
            'ppe_name' => $data['ppe_name'],
            'issued_at' => $data['issued_at'],
            'valid_until' => $data['valid_until'] ?? null,
            'quantity' => $data['quantity'] ?? 1,
            'status' => $data['status'] ?? 'issued',
            'warehouse_operation_id' => $data['warehouse_operation_id'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ])->fresh(['employee:id,last_name,first_name,middle_name']);
    }

    public function updatePpeIssue(SafetyPpeIssue $record, array $data): SafetyPpeIssue
    {
        if (array_key_exists('employee_id', $data)) {
            $this->findEmployee((int) $record->organization_id, (int) $data['employee_id']);
        }

        $record->fill($data);
        $record->save();

        return $record->fresh(['employee:id,last_name,first_name,middle_name']);
    }

    public function findPpeIssue(int $organizationId, int $id): ?SafetyPpeIssue
    {
        return SafetyPpeIssue::forOrganization($organizationId)
            ->with(['employee:id,last_name,first_name,middle_name'])
            ->find($id);
    }

    public function deletePpeIssue(SafetyPpeIssue $record): void
    {
        $record->delete();
    }

    public function dashboard(int $organizationId, array $filters = []): array
    {
        $projectId = $filters['project_id'] ?? null;
        $today = now()->toDateString();

        $permits = SafetyWorkPermit::forOrganization($organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', (int) $projectId));
        $incidents = SafetyIncident::forOrganization($organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', (int) $projectId));
        $violations = SafetyViolation::forOrganization($organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', (int) $projectId));
        $actions = SafetyCorrectiveAction::forOrganization($organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', (int) $projectId));
        $inspections = SafetyInspection::forOrganization($organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', (int) $projectId));
        $findings = SafetyInspectionFinding::forOrganization($organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', (int) $projectId));
        $briefings = SafetyBriefing::forOrganization($organizationId)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', (int) $projectId));
        $briefingParticipants = SafetyBriefingParticipant::query()
            ->whereHas('briefing', static function ($query) use ($organizationId, $projectId): void {
                $query->forOrganization($organizationId)
                    ->when($projectId !== null, fn ($scope) => $scope->where('project_id', (int) $projectId));
            });

        return [
            'summary' => [
                'active_permits' => (clone $permits)->where('status', 'active')->count(),
                'permits_expiring_soon' => (clone $permits)
                    ->whereIn('status', ['approved', 'active', 'suspended'])
                    ->whereBetween('valid_until', [$today, now()->addDays(7)->toDateString()])
                    ->count(),
                'open_incidents' => (clone $incidents)->whereNotIn('status', ['closed', 'cancelled'])->count(),
                'open_violations' => (clone $violations)->where('status', 'open')->count(),
                'open_corrective_actions' => (clone $actions)->whereIn('status', ['open', 'resolved'])->count(),
                'open_inspections' => (clone $inspections)->whereIn('status', ['planned', 'in_progress'])->count(),
                'open_findings' => (clone $findings)->where('status', 'open')->count(),
                'briefings_awaiting_signatures' => (clone $briefings)->where('status', 'awaiting_signatures')->count(),
                'briefing_participants_pending' => (clone $briefingParticipants)->where('signature_status', 'pending')->count(),
                'briefing_participants_refused' => (clone $briefingParticipants)->where('signature_status', 'refused')->count(),
            ],
            'overdue' => [
                'violations' => (clone $violations)->where('status', 'open')->whereDate('due_date', '<', $today)->count(),
                'corrective_actions' => (clone $actions)->whereIn('status', ['open', 'resolved'])->whereDate('due_date', '<', $today)->count(),
                'inspection_findings' => (clone $findings)->where('status', 'open')->whereDate('due_date', '<', $today)->count(),
            ],
            'critical' => [
                'incidents' => (clone $incidents)->whereIn('severity', ['high', 'critical'])->whereNotIn('status', ['closed', 'cancelled'])->count(),
                'violations' => (clone $violations)->whereIn('severity', ['high', 'critical'])->where('status', 'open')->count(),
                'findings' => (clone $findings)->whereIn('severity', ['high', 'critical'])->where('status', 'open')->count(),
            ],
        ];
    }

    public function paginateInspectionTemplates(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyInspectionTemplate::forOrganization($organizationId)
            ->when(!empty($filters['inspection_type']), fn ($query) => $query->where('inspection_type', (string) $filters['inspection_type']))
            ->when(array_key_exists('is_active', $filters), fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->latest()
            ->paginate($perPage);
    }

    public function createInspectionTemplate(int $organizationId, array $data): SafetyInspectionTemplate
    {
        return SafetyInspectionTemplate::query()->create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'inspection_type' => $data['inspection_type'],
            'checklist_items' => $data['checklist_items'] ?? [],
            'is_active' => $data['is_active'] ?? true,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function paginateInspections(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyInspection::forOrganization($organizationId)
            ->with(['project:id,name', 'items', 'findings'])
            ->withCount('findings')
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(!empty($filters['inspection_type']), fn ($query) => $query->where('inspection_type', (string) $filters['inspection_type']))
            ->latest()
            ->paginate($perPage);
    }

    public function createInspection(int $organizationId, int $userId, array $data): SafetyInspection
    {
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);
        $template = empty($data['template_id'])
            ? null
            : $this->findInspectionTemplate($organizationId, (int) $data['template_id']);

        if (!empty($data['permit_id'])) {
            $this->assertPermitBelongsToOrganization((int) $data['permit_id'], $organizationId, (int) $data['project_id']);
        }

        $this->assertOptionalUserBelongsToOrganization($data['conducted_by_user_id'] ?? null, $organizationId);

        return DB::transaction(function () use ($organizationId, $userId, $data, $template): SafetyInspection {
            $inspection = SafetyInspection::query()->create([
                'organization_id' => $organizationId,
                'project_id' => (int) $data['project_id'],
                'template_id' => $template?->id,
                'permit_id' => $data['permit_id'] ?? null,
                'conducted_by_user_id' => $data['conducted_by_user_id'] ?? $userId,
                'inspection_number' => $this->nextNumber('HSE-CHK', $organizationId),
                'title' => $data['title'],
                'inspection_type' => $data['inspection_type'] ?? $template?->inspection_type ?? 'site_walk',
                'location_name' => $data['location_name'] ?? null,
                'risk_level' => $data['risk_level'] ?? 'medium',
                'status' => $data['status'] ?? 'planned',
                'planned_at' => $data['planned_at'] ?? null,
                'conducted_at' => $data['conducted_at'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            foreach ($this->inspectionItemsPayload($data['items'] ?? null, $template) as $item) {
                $inspection->items()->create($item);
            }

            return $this->freshInspection($inspection);
        });
    }

    public function completeInspection(SafetyInspection $inspection, int $userId, array $data): SafetyInspection
    {
        if (!in_array($inspection->status, ['planned', 'in_progress'], true)) {
            throw new DomainException(trans_message('safety_management.errors.inspection_complete_invalid_status'));
        }

        return DB::transaction(function () use ($inspection, $userId, $data): SafetyInspection {
            $hasFailedItems = false;

            foreach ($data['items'] ?? [] as $itemPayload) {
                $item = $this->findInspectionItem($inspection, $itemPayload);
                $status = (string) ($itemPayload['status'] ?? $item->status);

                $item->update([
                    'status' => $status,
                    'comment' => $itemPayload['comment'] ?? $item->comment,
                    'evidence_files' => $itemPayload['evidence_files'] ?? $item->evidence_files,
                    'metadata' => $itemPayload['metadata'] ?? $item->metadata,
                ]);

                if ($status === 'non_compliant') {
                    $hasFailedItems = true;
                    $this->createFindingFromInspectionItem($inspection, $item, $userId, $itemPayload);
                }
            }

            $inspection->update([
                'status' => 'completed',
                'conducted_at' => $data['conducted_at'] ?? now(),
                'result' => $data['result'] ?? ($hasFailedItems ? 'failed' : 'passed'),
                'summary' => $data['summary'] ?? null,
            ]);

            return $this->freshInspection($inspection);
        });
    }

    public function paginateInspectionFindings(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyInspectionFinding::forOrganization($organizationId)
            ->with(['project:id,name', 'assignedUser:id,name'])
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->when(!empty($filters['assigned_to_user_id']), fn ($query) => $query->where('assigned_to_user_id', (int) $filters['assigned_to_user_id']))
            ->latest()
            ->paginate($perPage);
    }

    public function createInspectionFinding(int $organizationId, int $userId, array $data): SafetyInspectionFinding
    {
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);
        $this->assertOptionalUserBelongsToOrganization($data['assigned_to_user_id'] ?? null, $organizationId);
        $this->assertInspectionReferenceBelongsToProject($organizationId, (int) $data['project_id'], $data);

        return SafetyInspectionFinding::query()->create([
            'organization_id' => $organizationId,
            'project_id' => (int) $data['project_id'],
            'inspection_id' => $data['inspection_id'] ?? null,
            'inspection_item_id' => $data['inspection_item_id'] ?? null,
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'created_by_user_id' => $userId,
            'finding_number' => $this->nextNumber('HSE-F', $organizationId),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'severity' => $data['severity'] ?? 'major',
            'status' => 'open',
            'due_date' => $data['due_date'] ?? null,
            'evidence_files' => $data['evidence_files'] ?? [],
            'metadata' => $data['metadata'] ?? null,
        ])->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function resolveInspectionFinding(SafetyInspectionFinding $finding, int $userId, string $comment): SafetyInspectionFinding
    {
        if ($finding->status !== 'open') {
            throw new DomainException(trans_message('safety_management.errors.finding_resolve_invalid_status'));
        }

        if (trim($comment) === '') {
            throw new DomainException(trans_message('safety_management.errors.finding_resolution_required'));
        }

        $finding->update([
            'status' => 'resolved',
            'resolved_by_user_id' => $userId,
            'resolved_at' => now(),
            'resolution_comment' => trim($comment),
        ]);

        return $finding->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function createPermit(int $organizationId, int $userId, array $data): SafetyWorkPermit
    {
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);
        $this->assertOptionalUserBelongsToOrganization($data['responsible_user_id'] ?? null, $organizationId);

        return DB::transaction(function () use ($organizationId, $userId, $data): SafetyWorkPermit {
            $permit = SafetyWorkPermit::query()->create([
                'organization_id' => $organizationId,
                'project_id' => (int) $data['project_id'],
                'created_by_user_id' => $userId,
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
                'permit_number' => $this->nextNumber('HSE-P', $organizationId),
                'title' => $data['title'],
                'permit_type' => $data['permit_type'],
                'location_name' => $data['location_name'] ?? null,
                'risk_level' => $data['risk_level'] ?? 'medium',
                'valid_from' => $data['valid_from'],
                'valid_until' => $data['valid_until'],
                'required_controls' => $data['required_controls'] ?? [],
                'status' => 'draft',
                'metadata' => $data['metadata'] ?? null,
            ]);

            if (!empty($data['participants'])) {
                $this->replacePermitParticipants($permit, $data['participants']);
            }

            return $this->freshPermit($permit);
        });
    }

    public function mobilePermitsForUser(int $organizationId, int $userId, array $filters = []): array
    {
        $employee = $this->employeeForUser($organizationId, $userId);

        return SafetyWorkPermit::forOrganization($organizationId)
            ->with(['project:id,name', 'responsibleUser:id,name', 'participants.employee:id,last_name,first_name,middle_name'])
            ->where(function ($query) use ($userId, $employee): void {
                $query->where('responsible_user_id', $userId)
                    ->orWhereHas('participants', static function ($relation) use ($userId, $employee): void {
                        $relation->where('user_id', $userId);

                        if ($employee instanceof WorkforceEmployee) {
                            $relation->orWhere('employee_id', $employee->id);
                        }
                    });
            })
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderBy('valid_until')
            ->get()
            ->all();
    }

    public function mobileBriefingsForUser(int $organizationId, int $userId, array $filters = []): array
    {
        $employee = $this->employeeForUser($organizationId, $userId);

        return $this->mobileBriefingsQuery($organizationId, $userId, $employee, $filters)
            ->with([
                'project:id,name',
                'conductedByUser:id,name',
                'completedByUser:id,name',
                'cancelledByUser:id,name',
                'participants.user:id,name',
                'participants.employee:id,last_name,first_name,middle_name',
                'participants.signedByUser:id,name',
            ])
            ->orderByDesc('conducted_at')
            ->get()
            ->all();
    }

    public function mobileDashboardForUser(int $organizationId, int $userId, array $filters = []): array
    {
        $dashboard = $this->dashboard($organizationId, $filters);
        $employee = $this->employeeForUser($organizationId, $userId);

        $permits = SafetyWorkPermit::forOrganization($organizationId)
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->where(function ($query) use ($userId, $employee): void {
                $query->where('responsible_user_id', $userId)
                    ->orWhereHas('participants', static function ($relation) use ($userId, $employee): void {
                        $relation->where('user_id', $userId);

                        if ($employee instanceof WorkforceEmployee) {
                            $relation->orWhere('employee_id', $employee->id);
                        }
                    });
            });

        $violations = SafetyViolation::forOrganization($organizationId)
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->where(function ($query) use ($userId): void {
                $query->where('assigned_to_user_id', $userId)
                    ->orWhere('created_by_user_id', $userId);
            });

        $findings = SafetyInspectionFinding::forOrganization($organizationId)
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->where('assigned_to_user_id', $userId);

        $dashboard['mine'] = [
            'employee_id' => $employee?->id,
            'open_permits' => (clone $permits)->whereIn('status', ['approved', 'active', 'suspended'])->count(),
            'open_violations' => (clone $violations)->where('status', 'open')->count(),
            'open_findings' => (clone $findings)->where('status', 'open')->count(),
            'briefings_to_sign' => (clone $this->mobileBriefingsQuery($organizationId, $userId, $employee, $filters))
                ->where('status', 'awaiting_signatures')
                ->whereHas('participants', static function ($relation) use ($userId, $employee): void {
                    $relation->where('signature_status', 'pending')
                        ->where(function ($participantScope) use ($userId, $employee): void {
                            $participantScope->where('user_id', $userId);

                            if ($employee instanceof WorkforceEmployee) {
                                $participantScope->orWhere('employee_id', $employee->id);
                            }
                        });
                })
                ->count(),
        ];

        return $dashboard;
    }

    public function findMobileBriefing(int $organizationId, int $userId, int $id): ?SafetyBriefing
    {
        $employee = $this->employeeForUser($organizationId, $userId);

        return $this->mobileBriefingsQuery($organizationId, $userId, $employee)
            ->with([
                'project:id,name',
                'conductedByUser:id,name',
                'completedByUser:id,name',
                'cancelledByUser:id,name',
                'participants.user:id,name',
                'participants.employee:id,last_name,first_name,middle_name',
                'participants.signedByUser:id,name',
            ])
            ->find($id);
    }

    public function signMobileBriefingParticipant(
        int $organizationId,
        int $userId,
        int $briefingId,
        int $participantId
    ): ?SafetyBriefing {
        $briefing = $this->findMobileBriefing($organizationId, $userId, $briefingId);

        if (!$briefing instanceof SafetyBriefing) {
            return null;
        }

        $participant = $this->findBriefingParticipant($briefing, $participantId);
        $employee = $this->employeeForUser($organizationId, $userId);
        $matchesUser = (int) $participant->user_id === $userId;
        $matchesEmployee = $employee instanceof WorkforceEmployee
            && $participant->employee_id !== null
            && (int) $participant->employee_id === (int) $employee->id;

        if (!$matchesUser && !$matchesEmployee) {
            throw new DomainException(trans_message('safety_management.errors.briefing_participant_not_found'));
        }

        return $this->signBriefingParticipant($briefing, $participantId, $userId, 'mobile', [
            'source' => 'mobile_app',
        ]);
    }

    public function mobileAdmissionForUser(int $organizationId, int $userId, array $filters = []): ?SafetyComplianceResult
    {
        $employee = $this->employeeForUser($organizationId, $userId);

        if (!$employee instanceof WorkforceEmployee) {
            return null;
        }

        $metadata = is_array($employee->metadata) ? $employee->metadata : [];

        return $this->complianceService->check(new SafetyComplianceContext(
            organizationId: $organizationId,
            employeeId: (int) $employee->id,
            userId: $userId,
            projectId: isset($filters['project_id']) ? (int) $filters['project_id'] : null,
            workTypeId: isset($filters['work_type_id']) ? (int) $filters['work_type_id'] : null,
            workCategory: (string) ($filters['work_category'] ?? 'general'),
            date: isset($filters['work_date']) ? CarbonImmutable::parse((string) $filters['work_date']) : CarbonImmutable::today(),
            positionName: (string) ($filters['position_name'] ?? $metadata['position_name'] ?? '') ?: null,
        ));
    }

    public function findMobilePermit(int $organizationId, int $userId, int $id): ?SafetyWorkPermit
    {
        $employee = $this->employeeForUser($organizationId, $userId);

        return SafetyWorkPermit::forOrganization($organizationId)
            ->with(['project:id,name', 'responsibleUser:id,name', 'participants.employee:id,last_name,first_name,middle_name'])
            ->where(function ($query) use ($userId, $employee): void {
                $query->where('responsible_user_id', $userId)
                    ->orWhereHas('participants', static function ($relation) use ($userId, $employee): void {
                        $relation->where('user_id', $userId);

                        if ($employee instanceof WorkforceEmployee) {
                            $relation->orWhere('employee_id', $employee->id);
                        }
                    });
            })
            ->find($id);
    }

    public function findPermit(int $organizationId, int $id): ?SafetyWorkPermit
    {
        return SafetyWorkPermit::forOrganization($organizationId)
            ->with(['project:id,name', 'responsibleUser:id,name', 'participants.employee:id,last_name,first_name,middle_name'])
            ->find($id);
    }

    public function syncPermitParticipants(SafetyWorkPermit $permit, array $participants): SafetyWorkPermit
    {
        if (in_array($permit->status, ['rejected', 'closed', 'cancelled'], true)) {
            throw new DomainException(trans_message('safety_management.errors.permit_participants_invalid_status'));
        }

        return DB::transaction(function () use ($permit, $participants): SafetyWorkPermit {
            $this->replacePermitParticipants($permit, $participants);

            return $this->freshPermit($permit);
        });
    }

    public function submitPermit(SafetyWorkPermit $permit): SafetyWorkPermit
    {
        if ($permit->status !== 'draft') {
            throw new DomainException(trans_message('safety_management.errors.permit_submit_invalid_status'));
        }

        $permit->update([
            'status' => 'pending_approval',
            'submitted_at' => now(),
        ]);

        return $this->freshPermit($permit);
    }

    public function approvePermit(SafetyWorkPermit $permit, int $userId, ?string $comment): SafetyWorkPermit
    {
        if ($permit->status !== 'pending_approval') {
            throw new DomainException(trans_message('safety_management.errors.permit_approve_invalid_status'));
        }

        $permit->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by_user_id' => $userId,
            'approval_comment' => $comment,
        ]);

        return $this->freshPermit($permit);
    }

    public function rejectPermit(SafetyWorkPermit $permit, int $userId, string $reason): SafetyWorkPermit
    {
        if ($permit->status !== 'pending_approval') {
            throw new DomainException(trans_message('safety_management.errors.permit_reject_invalid_status'));
        }

        $permit->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejected_by_user_id' => $userId,
            'rejection_reason' => trim($reason),
        ]);

        return $this->freshPermit($permit);
    }

    public function activatePermit(SafetyWorkPermit $permit): SafetyWorkPermit
    {
        if ($permit->status !== 'approved') {
            throw new DomainException(trans_message('safety_management.errors.permit_activate_invalid_status'));
        }

        if ($permit->valid_until->isPast()) {
            throw new DomainException(trans_message('safety_management.errors.permit_expired'));
        }

        $this->assertPermitParticipantsAdmitted($permit);

        $permit->update([
            'status' => 'active',
            'activated_at' => now(),
        ]);

        return $this->freshPermit($permit);
    }

    public function suspendPermit(SafetyWorkPermit $permit, int $userId, string $reason): SafetyWorkPermit
    {
        if (!in_array($permit->status, ['approved', 'active'], true)) {
            throw new DomainException(trans_message('safety_management.errors.permit_suspend_invalid_status'));
        }

        $permit->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspended_by_user_id' => $userId,
            'suspension_reason' => trim($reason),
        ]);

        return $this->freshPermit($permit);
    }

    public function resumePermit(SafetyWorkPermit $permit): SafetyWorkPermit
    {
        if ($permit->status !== 'suspended') {
            throw new DomainException(trans_message('safety_management.errors.permit_resume_invalid_status'));
        }

        if ($permit->valid_until->isPast()) {
            throw new DomainException(trans_message('safety_management.errors.permit_expired'));
        }

        $this->assertPermitParticipantsAdmitted($permit);

        $permit->update([
            'status' => 'active',
            'suspended_at' => null,
            'suspended_by_user_id' => null,
            'suspension_reason' => null,
        ]);

        return $this->freshPermit($permit);
    }

    public function closePermit(SafetyWorkPermit $permit, int $userId, string $comment): SafetyWorkPermit
    {
        if (!in_array($permit->status, ['approved', 'active', 'suspended'], true)) {
            throw new DomainException(trans_message('safety_management.errors.permit_close_invalid_status'));
        }

        $permit->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by_user_id' => $userId,
            'close_comment' => trim($comment),
        ]);

        return $this->freshPermit($permit);
    }

    public function createIncident(int $organizationId, int $userId, array $data): SafetyIncident
    {
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);

        return SafetyIncident::query()->create([
            'organization_id' => $organizationId,
            'project_id' => (int) $data['project_id'],
            'reported_by_user_id' => $userId,
            'incident_number' => $this->nextNumber('HSE-I', $organizationId),
            'title' => $data['title'],
            'incident_type' => $data['incident_type'],
            'severity' => $data['severity'],
            'status' => 'reported',
            'occurred_at' => $data['occurred_at'],
            'location_name' => $data['location_name'] ?? null,
            'description' => $data['description'] ?? null,
            'immediate_actions' => $data['immediate_actions'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ])->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function findIncident(int $organizationId, int $id): ?SafetyIncident
    {
        return SafetyIncident::forOrganization($organizationId)
            ->with(['project:id,name', 'assignedUser:id,name'])
            ->find($id);
    }

    public function triageIncident(SafetyIncident $incident, int $userId, ?string $comment): SafetyIncident
    {
        if ($incident->status !== 'reported') {
            throw new DomainException(trans_message('safety_management.errors.incident_triage_invalid_status'));
        }

        $incident->update([
            'status' => 'triage',
            'triaged_by_user_id' => $userId,
            'triaged_at' => now(),
            'triage_comment' => $comment,
        ]);

        return $incident->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function startIncidentInvestigation(SafetyIncident $incident, int $assigneeId): SafetyIncident
    {
        if ($incident->status !== 'triage') {
            throw new DomainException(trans_message('safety_management.errors.incident_start_invalid_status'));
        }

        $this->assertOptionalUserBelongsToOrganization($assigneeId, (int) $incident->organization_id);

        $incident->update([
            'status' => 'investigation',
            'assigned_to_user_id' => $assigneeId,
            'investigation_started_at' => now(),
        ]);

        return $incident->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function startCorrectiveActions(SafetyIncident $incident, ?string $rootCause): SafetyIncident
    {
        if ($incident->status !== 'investigation') {
            throw new DomainException(trans_message('safety_management.errors.incident_corrective_actions_invalid_status'));
        }

        $incident->update([
            'status' => 'corrective_actions',
            'root_cause' => trim((string) $rootCause) ?: $incident->root_cause,
            'corrective_actions_started_at' => now(),
        ]);

        return $incident->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function cancelIncident(SafetyIncident $incident, int $userId, string $reason): SafetyIncident
    {
        if (!in_array($incident->status, ['reported', 'triage', 'investigation'], true)) {
            throw new DomainException(trans_message('safety_management.errors.incident_cancel_invalid_status'));
        }

        $incident->update([
            'status' => 'cancelled',
            'cancelled_by_user_id' => $userId,
            'cancelled_at' => now(),
            'cancellation_reason' => trim($reason),
        ]);

        return $incident->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function closeIncident(SafetyIncident $incident, int $userId, array $data): SafetyIncident
    {
        if ($incident->status !== 'corrective_actions') {
            throw new DomainException(trans_message('safety_management.errors.incident_close_invalid_status'));
        }

        $rootCause = trim((string) ($data['root_cause'] ?? $incident->root_cause ?? ''));
        $correctiveActions = trim((string) ($data['corrective_actions'] ?? $incident->corrective_actions ?? ''));

        if ($rootCause === '') {
            throw new DomainException(trans_message('safety_management.errors.incident_close_evidence_required'));
        }

        if (in_array($incident->severity, ['major', 'critical', 'high'], true)) {
            $openActions = SafetyCorrectiveAction::query()
                ->where('incident_id', $incident->id)
                ->whereIn('status', ['open', 'resolved'])
                ->exists();

            $hasVerifiedActions = SafetyCorrectiveAction::query()
                ->where('incident_id', $incident->id)
                ->where('status', 'verified')
                ->exists();

            if ($openActions || !$hasVerifiedActions) {
                throw new DomainException(trans_message('safety_management.errors.incident_close_corrective_actions_required'));
            }
        }

        $incident->update([
            'status' => 'closed',
            'root_cause' => $rootCause,
            'corrective_actions' => $correctiveActions,
            'closed_at' => now(),
            'closed_by_user_id' => $userId,
        ]);

        return $incident->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function createViolation(int $organizationId, int $userId, array $data): SafetyViolation
    {
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);
        $this->assertOptionalUserBelongsToOrganization($data['assigned_to_user_id'] ?? null, $organizationId);

        return SafetyViolation::query()->create([
            'organization_id' => $organizationId,
            'project_id' => (int) $data['project_id'],
            'created_by_user_id' => $userId,
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'violation_number' => $this->nextNumber('HSE-V', $organizationId),
            'title' => $data['title'],
            'severity' => $data['severity'],
            'status' => 'open',
            'location_name' => $data['location_name'] ?? null,
            'description' => $data['description'] ?? null,
            'corrective_action' => $data['corrective_action'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ])->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function findViolation(int $organizationId, int $id): ?SafetyViolation
    {
        return SafetyViolation::forOrganization($organizationId)
            ->with(['project:id,name', 'assignedUser:id,name'])
            ->find($id);
    }

    public function resolveViolation(SafetyViolation $violation, int $userId, string $comment): SafetyViolation
    {
        if ($violation->status !== 'open') {
            throw new DomainException(trans_message('safety_management.errors.violation_resolve_invalid_status'));
        }

        if (trim($comment) === '') {
            throw new DomainException(trans_message('safety_management.errors.violation_resolution_required'));
        }

        $violation->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by_user_id' => $userId,
            'resolution_comment' => trim($comment),
        ]);

        return $violation->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function createBriefing(int $organizationId, int $userId, array $data): SafetyBriefing
    {
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);

        return DB::transaction(function () use ($organizationId, $userId, $data): SafetyBriefing {
            $briefing = SafetyBriefing::query()->create([
                'organization_id' => $organizationId,
                'project_id' => (int) $data['project_id'],
                'conducted_by_user_id' => $userId,
                'briefing_number' => $this->nextNumber('HSE-B', $organizationId),
                'title' => $data['title'],
                'briefing_type' => $data['briefing_type'],
                'location_name' => $data['location_name'] ?? null,
                'conducted_at' => $data['conducted_at'],
                'status' => 'awaiting_signatures',
                'started_at' => $data['conducted_at'],
                'signature_deadline_at' => $data['signature_deadline_at'] ?? null,
                'signature_summary' => $this->emptyBriefingSignatureSummary(),
                'topics' => $data['topics'] ?? [],
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            foreach ($data['participants'] ?? [] as $participant) {
                $this->createBriefingParticipant($briefing, $participant);
            }

            $this->refreshBriefingSignatureSummary($briefing);

            return $this->freshBriefing($briefing);
        });
    }

    public function findBriefing(int $organizationId, int $id): ?SafetyBriefing
    {
        return SafetyBriefing::forOrganization($organizationId)
            ->with([
                'project:id,name',
                'conductedByUser:id,name',
                'completedByUser:id,name',
                'cancelledByUser:id,name',
                'participants.user:id,name',
                'participants.employee:id,last_name,first_name,middle_name',
                'participants.signedByUser:id,name',
            ])
            ->find($id);
    }

    public function addBriefingParticipants(SafetyBriefing $briefing, array $participants): SafetyBriefing
    {
        $this->assertBriefingCanCollectSignatures($briefing);

        return DB::transaction(function () use ($briefing, $participants): SafetyBriefing {
            foreach ($participants as $participant) {
                $this->createBriefingParticipant($briefing, $participant);
            }

            $this->refreshBriefingSignatureSummary($briefing);

            return $this->freshBriefing($briefing);
        });
    }

    public function signBriefingParticipant(
        SafetyBriefing $briefing,
        int $participantId,
        int $actorUserId,
        string $method = 'admin',
        array $metadata = []
    ): SafetyBriefing {
        $this->assertBriefingCanCollectSignatures($briefing);

        return DB::transaction(function () use ($briefing, $participantId, $actorUserId, $method, $metadata): SafetyBriefing {
            $participant = $this->findBriefingParticipant($briefing, $participantId);

            if (in_array($participant->signature_status, ['absent', 'refused'], true)) {
                throw new DomainException(trans_message('safety_management.errors.briefing_participant_signature_locked'));
            }

            $participant->update([
                'signature_status' => 'signed',
                'signed_at' => now(),
                'signed_by_user_id' => $actorUserId,
                'signature_method' => trim($method) !== '' ? trim($method) : 'admin',
                'refusal_reason' => null,
                'absence_reason' => null,
                'signature_metadata' => $metadata,
            ]);

            $this->refreshBriefingSignatureSummary($briefing);

            return $this->freshBriefing($briefing);
        });
    }

    public function markBriefingParticipantAbsent(SafetyBriefing $briefing, int $participantId, string $reason): SafetyBriefing
    {
        $this->assertBriefingCanCollectSignatures($briefing);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(trans_message('safety_management.errors.briefing_absence_reason_required'));
        }

        return DB::transaction(function () use ($briefing, $participantId, $reason): SafetyBriefing {
            $participant = $this->findBriefingParticipant($briefing, $participantId);
            $participant->update([
                'signature_status' => 'absent',
                'signed_at' => null,
                'signed_by_user_id' => null,
                'signature_method' => null,
                'refusal_reason' => null,
                'absence_reason' => $reason,
                'signature_metadata' => null,
            ]);

            $this->refreshBriefingSignatureSummary($briefing);

            return $this->freshBriefing($briefing);
        });
    }

    public function markBriefingParticipantRefused(SafetyBriefing $briefing, int $participantId, string $reason): SafetyBriefing
    {
        $this->assertBriefingCanCollectSignatures($briefing);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(trans_message('safety_management.errors.briefing_refusal_reason_required'));
        }

        return DB::transaction(function () use ($briefing, $participantId, $reason): SafetyBriefing {
            $participant = $this->findBriefingParticipant($briefing, $participantId);
            $participant->update([
                'signature_status' => 'refused',
                'signed_at' => null,
                'signed_by_user_id' => null,
                'signature_method' => null,
                'refusal_reason' => $reason,
                'absence_reason' => null,
                'signature_metadata' => null,
            ]);

            $this->refreshBriefingSignatureSummary($briefing);

            return $this->freshBriefing($briefing);
        });
    }

    public function completeBriefing(SafetyBriefing $briefing, int $actorUserId): SafetyBriefing
    {
        $this->assertBriefingCanCollectSignatures($briefing);
        $summary = $this->refreshBriefingSignatureSummary($briefing);

        if (($summary['total'] ?? 0) === 0) {
            throw new DomainException(trans_message('safety_management.errors.briefing_participant_required'));
        }

        if (($summary['pending'] ?? 0) > 0) {
            throw new DomainException(trans_message('safety_management.errors.briefing_has_pending_signatures'));
        }

        $briefing->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => $actorUserId,
        ]);

        return $this->freshBriefing($briefing);
    }

    public function cancelBriefing(SafetyBriefing $briefing, int $actorUserId, string $reason): SafetyBriefing
    {
        if (in_array($briefing->status, ['completed', 'cancelled'], true)) {
            throw new DomainException(trans_message('safety_management.errors.briefing_cannot_be_changed'));
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(trans_message('safety_management.errors.briefing_cancellation_reason_required'));
        }

        $briefing->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $actorUserId,
            'cancellation_reason' => $reason,
        ]);

        return $this->freshBriefing($briefing);
    }

    public function createCorrectiveAction(int $organizationId, int $userId, array $data): SafetyCorrectiveAction
    {
        $incident = null;
        $violation = null;
        $hasIncident = !empty($data['incident_id']);
        $hasViolation = !empty($data['violation_id']);

        if ($hasIncident && $hasViolation) {
            throw new DomainException(trans_message('safety_management.errors.corrective_action_single_source_required'));
        }

        if ($hasIncident) {
            $incident = $this->findIncident($organizationId, (int) $data['incident_id']);
            if ($incident === null) {
                throw new DomainException(trans_message('safety_management.errors.incident_not_found'));
            }
        }

        if ($hasViolation) {
            $violation = $this->findViolation($organizationId, (int) $data['violation_id']);
            if ($violation === null) {
                throw new DomainException(trans_message('safety_management.errors.violation_not_found'));
            }
        }

        if ($incident === null && $violation === null) {
            throw new DomainException(trans_message('safety_management.errors.corrective_action_source_required'));
        }

        $projectId = (int) ($incident?->project_id ?? $violation?->project_id);
        $this->assertOptionalUserBelongsToOrganization($data['assigned_to_user_id'] ?? null, $organizationId);

        return SafetyCorrectiveAction::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'incident_id' => $incident?->id,
            'violation_id' => $violation?->id,
            'created_by_user_id' => $userId,
            'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
            'action_number' => $this->nextNumber('HSE-C', $organizationId),
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'source_type' => $incident !== null ? 'incident' : 'violation',
            'severity' => $data['severity'] ?? ($incident?->severity ?? $violation?->severity ?? 'major'),
            'status' => 'open',
            'due_date' => $data['due_date'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ])->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function findCorrectiveAction(int $organizationId, int $id): ?SafetyCorrectiveAction
    {
        return SafetyCorrectiveAction::forOrganization($organizationId)
            ->with(['project:id,name', 'assignedUser:id,name'])
            ->find($id);
    }

    public function resolveCorrectiveAction(SafetyCorrectiveAction $action, int $userId, string $comment): SafetyCorrectiveAction
    {
        if ($action->status !== 'open') {
            throw new DomainException(trans_message('safety_management.errors.corrective_action_resolve_invalid_status'));
        }

        if (trim($comment) === '') {
            throw new DomainException(trans_message('safety_management.errors.corrective_action_resolution_required'));
        }

        $action->update([
            'status' => 'resolved',
            'resolved_by_user_id' => $userId,
            'resolved_at' => now(),
            'resolution_comment' => trim($comment),
        ]);

        return $action->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function verifyCorrectiveAction(SafetyCorrectiveAction $action, int $userId, string $comment): SafetyCorrectiveAction
    {
        if ($action->status !== 'resolved') {
            throw new DomainException(trans_message('safety_management.errors.corrective_action_verify_invalid_status'));
        }

        $action->update([
            'status' => 'verified',
            'verified_by_user_id' => $userId,
            'verified_at' => now(),
            'verification_comment' => trim($comment),
        ]);

        return $action->fresh(['project:id,name', 'assignedUser:id,name']);
    }

    public function findInspection(int $organizationId, int $id): ?SafetyInspection
    {
        return SafetyInspection::forOrganization($organizationId)
            ->with(['project:id,name', 'items', 'findings.assignedUser:id,name'])
            ->withCount('findings')
            ->find($id);
    }

    public function findInspectionFinding(int $organizationId, int $id): ?SafetyInspectionFinding
    {
        return SafetyInspectionFinding::forOrganization($organizationId)
            ->with(['project:id,name', 'assignedUser:id,name'])
            ->find($id);
    }

    private function replacePermitParticipants(SafetyWorkPermit $permit, array $participants): void
    {
        $permit->participants()->delete();

        foreach ($participants as $participant) {
            $permit->participants()->create($this->normalizePermitParticipant((int) $permit->organization_id, (array) $participant));
        }
    }

    private function normalizePermitParticipant(int $organizationId, array $participant): array
    {
        $employee = null;
        $employeeId = $participant['employee_id'] ?? null;
        $userId = $participant['user_id'] ?? null;
        $externalName = trim((string) ($participant['external_name'] ?? ''));

        if ($employeeId !== null && $employeeId !== '') {
            $employee = $this->findEmployee($organizationId, (int) $employeeId);
            $userId = $userId ?? $employee->user_id;
        }

        if ($userId !== null && $userId !== '') {
            $this->assertOptionalUserBelongsToOrganization($userId, $organizationId);
        }

        if ($employee === null && ($userId === null || $userId === '') && $externalName === '') {
            throw new DomainException(trans_message('safety_management.errors.permit_participant_required'));
        }

        return [
            'organization_id' => $organizationId,
            'employee_id' => $employee?->id,
            'user_id' => $userId === null || $userId === '' ? null : (int) $userId,
            'external_name' => $externalName !== '' ? $externalName : null,
            'company_name' => $participant['company_name'] ?? null,
            'role_name' => $participant['role_name'] ?? null,
            'position_name' => $participant['position_name'] ?? null,
            'work_category' => $participant['work_category'] ?? null,
            'admission_status' => $employee === null ? 'external' : 'pending',
            'admission_blockers' => null,
            'admission_warnings' => null,
            'metadata' => $participant['metadata'] ?? null,
        ];
    }

    private function assertPermitParticipantsAdmitted(SafetyWorkPermit $permit): void
    {
        $hasBlockedParticipants = false;
        $participants = $permit->participants()->with('employee')->get();

        foreach ($participants as $participant) {
            if (!$participant instanceof SafetyWorkPermitParticipant || $participant->employee_id === null) {
                continue;
            }

            $result = $this->complianceService->check(new SafetyComplianceContext(
                organizationId: (int) $permit->organization_id,
                employeeId: (int) $participant->employee_id,
                userId: $participant->user_id === null ? null : (int) $participant->user_id,
                projectId: (int) $permit->project_id,
                workTypeId: null,
                workCategory: $participant->work_category ?? $permit->permit_type,
                date: CarbonImmutable::today(),
                positionName: $participant->position_name,
                permitId: (int) $permit->id,
                workOrderLineId: null,
            ));

            $participant->update([
                'admission_status' => $result->status,
                'admission_checked_at' => now(),
                'admission_blockers' => $result->blockers,
                'admission_warnings' => $result->warnings,
            ]);

            if ($result->blocked) {
                $hasBlockedParticipants = true;
            }
        }

        if ($hasBlockedParticipants) {
            throw new DomainException(trans_message('safety_management.errors.permit_participant_not_admitted'));
        }
    }

    private function findEmployee(int $organizationId, int $employeeId): WorkforceEmployee
    {
        $employee = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->find($employeeId);

        if (!$employee instanceof WorkforceEmployee) {
            throw new DomainException(trans_message('safety_management.errors.employee_not_found'));
        }

        return $employee;
    }

    private function employeeForUser(int $organizationId, int $userId): ?WorkforceEmployee
    {
        return WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();
    }

    private function mobileBriefingsQuery(
        int $organizationId,
        int $userId,
        ?WorkforceEmployee $employee,
        array $filters = []
    ): Builder {
        return SafetyBriefing::forOrganization($organizationId)
            ->whereHas('participants', static function ($relation) use ($userId, $employee): void {
                $relation->where('user_id', $userId);

                if ($employee instanceof WorkforceEmployee) {
                    $relation->orWhere('employee_id', $employee->id);
                }
            })
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']));
    }

    private function createBriefingParticipant(SafetyBriefing $briefing, array $participant): SafetyBriefingParticipant
    {
        $employeeId = $participant['employee_id'] ?? null;
        $employee = null;

        if ($employeeId !== null && $employeeId !== '') {
            $employee = $this->findEmployee((int) $briefing->organization_id, (int) $employeeId);
        }

        $userIdValue = $participant['user_id'] ?? $employee?->user_id;
        $externalName = trim((string) ($participant['external_name'] ?? ''));

        if ($employee === null && $userIdValue === null && $externalName === '') {
            throw new DomainException(trans_message('safety_management.errors.briefing_participant_required'));
        }

        $this->assertOptionalUserBelongsToOrganization($userIdValue, (int) $briefing->organization_id);

        if ($employee instanceof WorkforceEmployee && $userIdValue !== null && $employee->user_id !== null && (int) $employee->user_id !== (int) $userIdValue) {
            throw new DomainException(trans_message('safety_management.errors.briefing_participant_user_mismatch'));
        }

        return $briefing->participants()->create([
            'employee_id' => $employee?->id,
            'user_id' => $userIdValue,
            'external_name' => $externalName !== '' ? $externalName : null,
            'company_name' => $participant['company_name'] ?? null,
            'role_name' => $participant['role_name'] ?? null,
            'signature_status' => 'pending',
            'signed_at' => null,
            'signed_by_user_id' => null,
            'signature_method' => null,
            'metadata' => $participant['metadata'] ?? null,
        ]);
    }

    private function findBriefingParticipant(SafetyBriefing $briefing, int $participantId): SafetyBriefingParticipant
    {
        $participant = $briefing->participants()
            ->where('id', $participantId)
            ->first();

        if (!$participant instanceof SafetyBriefingParticipant) {
            throw new DomainException(trans_message('safety_management.errors.briefing_participant_not_found'));
        }

        return $participant;
    }

    private function assertBriefingCanCollectSignatures(SafetyBriefing $briefing): void
    {
        if (in_array($briefing->status, ['completed', 'cancelled'], true)) {
            throw new DomainException(trans_message('safety_management.errors.briefing_cannot_be_changed'));
        }
    }

    private function refreshBriefingSignatureSummary(SafetyBriefing $briefing): array
    {
        $counts = $briefing->participants()
            ->selectRaw('signature_status, count(*) as aggregate')
            ->groupBy('signature_status')
            ->pluck('aggregate', 'signature_status');
        $total = (int) $counts->sum();
        $signed = (int) ($counts['signed'] ?? 0);
        $pending = (int) ($counts['pending'] ?? 0);
        $absent = (int) ($counts['absent'] ?? 0);
        $refused = (int) ($counts['refused'] ?? 0);
        $resolved = $signed + $absent + $refused;
        $summary = [
            'total' => $total,
            'signed' => $signed,
            'pending' => $pending,
            'absent' => $absent,
            'refused' => $refused,
            'resolved' => $resolved,
            'completion_percent' => $total === 0 ? 0 : round(($resolved / $total) * 100, 2),
            'all_resolved' => $total > 0 && $pending === 0,
        ];

        $briefing->forceFill(['signature_summary' => $summary])->save();

        return $summary;
    }

    private function emptyBriefingSignatureSummary(): array
    {
        return [
            'total' => 0,
            'signed' => 0,
            'pending' => 0,
            'absent' => 0,
            'refused' => 0,
            'resolved' => 0,
            'completion_percent' => 0,
            'all_resolved' => false,
        ];
    }

    private function freshBriefing(SafetyBriefing $briefing): SafetyBriefing
    {
        return $briefing->fresh([
            'project:id,name',
            'conductedByUser:id,name',
            'completedByUser:id,name',
            'cancelledByUser:id,name',
            'participants.user:id,name',
            'participants.employee:id,last_name,first_name,middle_name',
            'participants.signedByUser:id,name',
        ]);
    }

    private function freshPermit(SafetyWorkPermit $permit): SafetyWorkPermit
    {
        return $permit->fresh([
            'project:id,name',
            'responsibleUser:id,name',
            'participants.employee:id,last_name,first_name,middle_name',
        ]);
    }

    private function findInspectionTemplate(int $organizationId, int $id): SafetyInspectionTemplate
    {
        $template = SafetyInspectionTemplate::forOrganization($organizationId)->find($id);

        if (!$template instanceof SafetyInspectionTemplate) {
            throw new DomainException(trans_message('safety_management.errors.inspection_template_not_found'));
        }

        return $template;
    }

    private function assertPermitBelongsToOrganization(int $permitId, int $organizationId, int $projectId): void
    {
        $exists = SafetyWorkPermit::forOrganization($organizationId)
            ->where('project_id', $projectId)
            ->whereKey($permitId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('safety_management.errors.permit_not_found'));
        }
    }

    private function inspectionItemsPayload(?array $items, ?SafetyInspectionTemplate $template): array
    {
        $source = $items;

        if ($source === null || $source === []) {
            $source = $template?->checklist_items ?? [];
        }

        $normalized = [];

        foreach ($source as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? $item['label'] ?? ''));

            if ($title === '') {
                continue;
            }

            $normalized[] = [
                'item_code' => (string) ($item['item_code'] ?? $item['code'] ?? 'item_' . ($index + 1)),
                'title' => $title,
                'requirement_text' => $item['requirement_text'] ?? $item['description'] ?? null,
                'severity' => $item['severity'] ?? 'major',
                'status' => $item['status'] ?? 'not_checked',
                'comment' => $item['comment'] ?? null,
                'evidence_files' => $item['evidence_files'] ?? [],
                'metadata' => $item['metadata'] ?? null,
            ];
        }

        return $normalized;
    }

    private function findInspectionItem(SafetyInspection $inspection, array $payload): SafetyInspectionItem
    {
        $query = $inspection->items();

        if (!empty($payload['id'])) {
            $query->whereKey((int) $payload['id']);
        } elseif (!empty($payload['item_code'])) {
            $query->where('item_code', (string) $payload['item_code']);
        } else {
            throw new DomainException(trans_message('safety_management.errors.inspection_item_not_found'));
        }

        $item = $query->first();

        if (!$item instanceof SafetyInspectionItem) {
            throw new DomainException(trans_message('safety_management.errors.inspection_item_not_found'));
        }

        return $item;
    }

    private function createFindingFromInspectionItem(
        SafetyInspection $inspection,
        SafetyInspectionItem $item,
        int $userId,
        array $payload
    ): void {
        $this->assertOptionalUserBelongsToOrganization($payload['assigned_to_user_id'] ?? null, (int) $inspection->organization_id);

        $exists = SafetyInspectionFinding::query()
            ->where('inspection_item_id', $item->id)
            ->where('status', 'open')
            ->exists();

        if ($exists) {
            return;
        }

        SafetyInspectionFinding::query()->create([
            'organization_id' => $inspection->organization_id,
            'project_id' => $inspection->project_id,
            'inspection_id' => $inspection->id,
            'inspection_item_id' => $item->id,
            'assigned_to_user_id' => $payload['assigned_to_user_id'] ?? null,
            'created_by_user_id' => $userId,
            'finding_number' => $this->nextNumber('HSE-F', (int) $inspection->organization_id),
            'title' => $payload['finding_title'] ?? $item->title,
            'description' => $payload['finding_description'] ?? $item->comment ?? $item->requirement_text,
            'severity' => $payload['severity'] ?? $item->severity,
            'status' => 'open',
            'due_date' => $payload['due_date'] ?? now()->addDays(7)->toDateString(),
            'evidence_files' => $payload['evidence_files'] ?? $item->evidence_files ?? [],
            'metadata' => $payload['finding_metadata'] ?? null,
        ]);
    }

    private function freshInspection(SafetyInspection $inspection): SafetyInspection
    {
        return $inspection->fresh([
            'project:id,name',
            'items',
            'findings.assignedUser:id,name',
        ])->loadCount('findings');
    }

    private function assertInspectionReferenceBelongsToProject(int $organizationId, int $projectId, array $data): void
    {
        if (!empty($data['inspection_id'])) {
            $inspection = SafetyInspection::forOrganization($organizationId)
                ->where('project_id', $projectId)
                ->whereKey((int) $data['inspection_id'])
                ->first();

            if (!$inspection instanceof SafetyInspection) {
                throw new DomainException(trans_message('safety_management.errors.inspection_not_found'));
            }

            if (!empty($data['inspection_item_id'])) {
                $exists = $inspection->items()
                    ->whereKey((int) $data['inspection_item_id'])
                    ->exists();

                if (!$exists) {
                    throw new DomainException(trans_message('safety_management.errors.inspection_item_not_found'));
                }
            }

            return;
        }

        if (!empty($data['inspection_item_id'])) {
            throw new DomainException(trans_message('safety_management.errors.inspection_not_found'));
        }
    }

    private function assertProjectBelongsToOrganization(int $projectId, int $organizationId): void
    {
        $exists = Project::query()
            ->where('id', $projectId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('safety_management.errors.project_not_found'));
        }
    }

    private function assertOptionalUserBelongsToOrganization(mixed $userId, int $organizationId): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $exists = User::query()
            ->where('id', (int) $userId)
            ->where(function ($query) use ($organizationId): void {
                $query->where('current_organization_id', $organizationId)
                    ->orWhereHas('organizations', static function ($relation) use ($organizationId): void {
                        $relation->where('organizations.id', $organizationId);
                    });
            })
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('safety_management.errors.user_not_found'));
        }
    }

    private function assertOptionalProjectBelongsToOrganization(mixed $projectId, int $organizationId): void
    {
        if ($projectId === null || $projectId === '') {
            return;
        }

        $this->assertProjectBelongsToOrganization((int) $projectId, $organizationId);
    }

    private function assertOptionalWorkTypeBelongsToOrganization(mixed $workTypeId, int $organizationId): void
    {
        if ($workTypeId === null || $workTypeId === '') {
            return;
        }

        $exists = WorkType::query()
            ->where('id', (int) $workTypeId)
            ->where('organization_id', $organizationId)
            ->exists();

        if (!$exists) {
            throw new DomainException(trans_message('safety_management.errors.work_type_not_found'));
        }
    }

    private function nextNumber(string $prefix, int $organizationId): string
    {
        return sprintf('%s-%d-%s', $prefix, $organizationId, now()->format('YmdHisv'));
    }
}
