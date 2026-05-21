<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Services;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyCorrectiveAction;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class SafetyManagementService
{
    public function paginatePermits(int $organizationId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        return SafetyWorkPermit::forOrganization($organizationId)
            ->with(['project:id,name', 'responsibleUser:id,name'])
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
            ->with(['project:id,name', 'conductedByUser:id,name', 'participants.user:id,name'])
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

    public function createPermit(int $organizationId, int $userId, array $data): SafetyWorkPermit
    {
        $this->assertProjectBelongsToOrganization((int) $data['project_id'], $organizationId);
        $this->assertOptionalUserBelongsToOrganization($data['responsible_user_id'] ?? null, $organizationId);

        return SafetyWorkPermit::query()->create([
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
        ])->fresh(['project:id,name', 'responsibleUser:id,name']);
    }

    public function mobilePermitsForUser(int $organizationId, int $userId, array $filters = []): array
    {
        return SafetyWorkPermit::forOrganization($organizationId)
            ->with(['project:id,name', 'responsibleUser:id,name'])
            ->where(function ($query) use ($userId): void {
                $query->whereNull('responsible_user_id')
                    ->orWhere('responsible_user_id', $userId);
            })
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderBy('valid_until')
            ->get()
            ->all();
    }

    public function findMobilePermit(int $organizationId, int $userId, int $id): ?SafetyWorkPermit
    {
        return SafetyWorkPermit::forOrganization($organizationId)
            ->with(['project:id,name', 'responsibleUser:id,name'])
            ->where(function ($query) use ($userId): void {
                $query->whereNull('responsible_user_id')
                    ->orWhere('responsible_user_id', $userId);
            })
            ->find($id);
    }

    public function findPermit(int $organizationId, int $id): ?SafetyWorkPermit
    {
        return SafetyWorkPermit::forOrganization($organizationId)
            ->with(['project:id,name', 'responsibleUser:id,name'])
            ->find($id);
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

        return $permit->fresh(['project:id,name', 'responsibleUser:id,name']);
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

        return $permit->fresh(['project:id,name', 'responsibleUser:id,name']);
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

        return $permit->fresh(['project:id,name', 'responsibleUser:id,name']);
    }

    public function activatePermit(SafetyWorkPermit $permit): SafetyWorkPermit
    {
        if ($permit->status !== 'approved') {
            throw new DomainException(trans_message('safety_management.errors.permit_activate_invalid_status'));
        }

        if ($permit->valid_until->isPast()) {
            throw new DomainException(trans_message('safety_management.errors.permit_expired'));
        }

        $permit->update([
            'status' => 'active',
            'activated_at' => now(),
        ]);

        return $permit->fresh(['project:id,name', 'responsibleUser:id,name']);
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

        return $permit->fresh(['project:id,name', 'responsibleUser:id,name']);
    }

    public function resumePermit(SafetyWorkPermit $permit): SafetyWorkPermit
    {
        if ($permit->status !== 'suspended') {
            throw new DomainException(trans_message('safety_management.errors.permit_resume_invalid_status'));
        }

        $permit->update([
            'status' => 'active',
            'suspended_at' => null,
            'suspended_by_user_id' => null,
            'suspension_reason' => null,
        ]);

        return $permit->fresh(['project:id,name', 'responsibleUser:id,name']);
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

        return $permit->fresh(['project:id,name', 'responsibleUser:id,name']);
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
                'topics' => $data['topics'] ?? [],
                'notes' => $data['notes'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            foreach ($data['participants'] ?? [] as $participant) {
                $userIdValue = $participant['user_id'] ?? null;
                $externalName = trim((string) ($participant['external_name'] ?? ''));

                if ($userIdValue === null && $externalName === '') {
                    throw new DomainException(trans_message('safety_management.errors.briefing_participant_required'));
                }

                $this->assertOptionalUserBelongsToOrganization($userIdValue, $organizationId);

                $briefing->participants()->create([
                    'user_id' => $userIdValue,
                    'external_name' => $externalName !== '' ? $externalName : null,
                    'company_name' => $participant['company_name'] ?? null,
                    'role_name' => $participant['role_name'] ?? null,
                    'signed_at' => $participant['signed_at'] ?? now(),
                    'metadata' => $participant['metadata'] ?? null,
                ]);
            }

            return $briefing->fresh(['project:id,name', 'conductedByUser:id,name', 'participants.user:id,name']);
        });
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

    private function nextNumber(string $prefix, int $organizationId): string
    {
        return sprintf('%s-%d-%s', $prefix, $organizationId, now()->format('YmdHisv'));
    }
}
