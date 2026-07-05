<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Services;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionFinding;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyPpeIssue;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use DomainException;
use Illuminate\Support\Facades\Lang;

final class SafetyDocumentDraftService
{
    public function briefingJournal(int $organizationId, array $filters): array
    {
        $briefings = SafetyBriefing::forOrganization($organizationId)
            ->with(['project:id,name', 'conductedByUser:id,name', 'participants.user:id,name'])
            ->when(!empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(!empty($filters['briefing_type']), fn ($query) => $query->where('briefing_type', (string) $filters['briefing_type']))
            ->when(!empty($filters['date_from']), fn ($query) => $query->whereDate('conducted_at', '>=', (string) $filters['date_from']))
            ->when(!empty($filters['date_until']), fn ($query) => $query->whereDate('conducted_at', '<=', (string) $filters['date_until']))
            ->orderByDesc('conducted_at')
            ->limit(200)
            ->get();

        return [
            'document_type' => 'briefing_journal',
            'title' => trans_message('safety_management.documents.briefing_journal'),
            'generated_at' => now()->toIso8601String(),
            'source' => [
                'project_id' => $filters['project_id'] ?? null,
                'briefing_type' => $filters['briefing_type'] ?? null,
                'briefing_type_label' => self::briefingTypeLabel($filters['briefing_type'] ?? null),
                'date_from' => $filters['date_from'] ?? null,
                'date_until' => $filters['date_until'] ?? null,
                'entries_count' => $briefings->count(),
                'limit' => 200,
            ],
            'sections' => [[
                'title' => trans_message('safety_management.documents.sections.briefings'),
                'rows' => $briefings->map(static fn (SafetyBriefing $briefing): array => [
                    'briefing_number' => $briefing->briefing_number,
                    'briefing_type' => $briefing->briefing_type,
                    'briefing_type_label' => self::briefingTypeLabel($briefing->briefing_type),
                    'title' => $briefing->title,
                    'topic' => implode(', ', array_filter($briefing->topics ?? [])) ?: $briefing->title,
                    'topics' => array_values(array_filter($briefing->topics ?? [])),
                    'project' => $briefing->project?->name,
                    'location_name' => $briefing->location_name,
                    'conducted_by' => $briefing->conductedByUser?->name,
                    'conducted_at' => $briefing->conducted_at?->toIso8601String(),
                    'participants_count' => $briefing->participants->count(),
                    'participants' => $briefing->participants->map(static fn ($participant): array => [
                        'name' => $participant->user?->name ?? $participant->external_name,
                        'company_name' => $participant->company_name,
                        'role_name' => $participant->role_name,
                        'signed_at' => $participant->signed_at?->toIso8601String(),
                    ])->values()->all(),
                    'notes' => $briefing->notes,
                ])->values()->all(),
            ]],
        ];
    }

    public function ppeCard(int $organizationId, int $employeeId): array
    {
        $employee = WorkforceEmployee::query()
            ->where('organization_id', $organizationId)
            ->find($employeeId);

        if (!$employee instanceof WorkforceEmployee) {
            throw new DomainException(trans_message('safety_management.errors.employee_not_found'));
        }

        $issues = SafetyPpeIssue::forOrganization($organizationId)
            ->where('employee_id', $employeeId)
            ->orderByDesc('issued_at')
            ->limit(200)
            ->get();

        return [
            'document_type' => 'ppe_card',
            'title' => trans_message('safety_management.documents.ppe_card'),
            'generated_at' => now()->toIso8601String(),
            'source' => [
                'employee_id' => $employee->id,
                'employee_name' => trim(implode(' ', array_filter([
                    $employee->last_name,
                    $employee->first_name,
                    $employee->middle_name,
                ]))),
            ],
            'sections' => [[
                'title' => trans_message('safety_management.documents.sections.ppe_issues'),
                'rows' => $issues->map(static fn (SafetyPpeIssue $issue): array => [
                    'ppe_code' => $issue->ppe_code,
                    'ppe_name' => $issue->ppe_name,
                    'issued_at' => $issue->issued_at?->toDateString(),
                    'valid_until' => $issue->valid_until?->toDateString(),
                    'quantity' => $issue->quantity,
                    'status' => $issue->status,
                ])->values()->all(),
            ]],
        ];
    }

    public function violationAct(int $organizationId, array $data): array
    {
        $violation = null;
        $finding = null;

        if (!empty($data['violation_id'])) {
            $violation = SafetyViolation::forOrganization($organizationId)
                ->with(['project:id,name', 'assignedUser:id,name'])
                ->find((int) $data['violation_id']);

            if (!$violation instanceof SafetyViolation) {
                throw new DomainException(trans_message('safety_management.errors.violation_not_found'));
            }
        }

        if (!empty($data['finding_id'])) {
            $finding = SafetyInspectionFinding::forOrganization($organizationId)
                ->with(['project:id,name', 'assignedUser:id,name'])
                ->find((int) $data['finding_id']);

            if (!$finding instanceof SafetyInspectionFinding) {
                throw new DomainException(trans_message('safety_management.errors.finding_not_found'));
            }
        }

        if ($violation === null && $finding === null) {
            throw new DomainException(trans_message('safety_management.errors.document_source_required'));
        }

        return [
            'document_type' => 'violation_act',
            'title' => trans_message('safety_management.documents.violation_act'),
            'generated_at' => now()->toIso8601String(),
            'source' => [
                'violation_id' => $violation?->id,
                'finding_id' => $finding?->id,
                'project' => $violation?->project?->name ?? $finding?->project?->name,
            ],
            'sections' => [[
                'title' => trans_message('safety_management.documents.sections.summary'),
                'rows' => [[
                    'number' => $violation?->violation_number ?? $finding?->finding_number,
                    'title' => $violation?->title ?? $finding?->title,
                    'description' => $violation?->description ?? $finding?->description,
                    'severity' => $violation?->severity ?? $finding?->severity,
                    'status' => $violation?->status ?? $finding?->status,
                    'assigned_to' => $violation?->assignedUser?->name ?? $finding?->assignedUser?->name,
                    'due_date' => $violation?->due_date?->toDateString() ?? $finding?->due_date?->toDateString(),
                ]],
            ]],
        ];
    }

    private static function briefingTypeLabel(?string $briefingType): ?string
    {
        if ($briefingType === null || $briefingType === '') {
            return null;
        }

        $translationKey = "safety_management.briefing_types.{$briefingType}";

        return Lang::has($translationKey) ? trans_message($translationKey) : $briefingType;
    }
}
