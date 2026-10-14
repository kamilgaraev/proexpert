<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrCandidate;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrCandidateEvent;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrChecklistItem;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrDevelopmentRecord;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\HrVacancy;
use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class HrLifecycleService
{
    public function __construct(private readonly HrAccess $access, private readonly HrDocumentService $documents) {}

    public function recruiting(int $organizationId, ?User $actor): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.recruitment.manage');
        return ['vacancies' => HrVacancy::query()->where('organization_id', $organizationId)->latest()->limit(200)->get(),
            'candidates' => HrCandidate::query()->where('organization_id', $organizationId)->latest()->limit(200)->get()];
    }

    public function vacancy(int $organizationId, array $payload, ?User $actor): HrVacancy
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.recruitment.manage');
        $data = Validator::make($payload, [
            'title' => ['required', 'string', 'max:255'], 'owner_id' => ['required', 'integer'], 'target_date' => ['nullable', 'date'],
            'stage_pipeline' => ['required', 'array', 'min:2', 'max:12'], 'stage_pipeline.*' => ['required', 'string', 'max:80', 'distinct'],
        ])->validate();
        $this->access->owner($organizationId, (int) $data['owner_id']);
        return HrVacancy::create($data + ['organization_id' => $organizationId, 'status' => 'open']);
    }

    public function candidate(int $organizationId, array $payload, ?User $actor): HrCandidate
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.recruitment.manage');
        $data = Validator::make($payload, ['vacancy_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:80'], 'owner_id' => ['required', 'integer']])->validate();
        $this->access->owner($organizationId, (int) $data['owner_id']);
        $vacancy = HrVacancy::query()->where('organization_id', $organizationId)->where('status', 'open')->findOrFail($data['vacancy_id']);
        return HrCandidate::create($data + ['organization_id' => $organizationId, 'stage' => $vacancy->stage_pipeline[0]]);
    }

    public function moveCandidate(int $organizationId, int $candidateId, array $payload, ?User $actor): HrCandidate
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.recruitment.manage');
        $data = Validator::make($payload, ['stage' => ['required', 'string', 'max:80'], 'expected_stage' => ['required', 'string', 'max:80'], 'reason' => ['required', 'string', 'max:500']])->validate();
        return DB::transaction(function () use ($organizationId, $candidateId, $data, $actor): HrCandidate {
            $candidate = HrCandidate::query()->where('organization_id', $organizationId)->lockForUpdate()->findOrFail($candidateId);
            $vacancy = HrVacancy::query()->where('organization_id', $organizationId)->findOrFail($candidate->vacancy_id);
            if ($vacancy->status !== 'open' || $candidate->stage !== $data['expected_stage'] || ! in_array($data['stage'], $vacancy->stage_pipeline, true)) {
                throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
            }
            if ($candidate->stage === $data['stage']) {
                return $candidate;
            }
            HrCandidateEvent::create(['organization_id' => $organizationId, 'candidate_id' => $candidateId, 'from_stage' => $candidate->stage,
                'to_stage' => $data['stage'], 'reason' => $data['reason'], 'recorded_by' => $actor->id, 'recorded_at' => now()]);
            $candidate->update(['stage' => $data['stage']]);
            // Candidate stage changes never create employee records or make an employment decision.
            return $candidate->refresh();
        });
    }

    public function closeVacancy(int $organizationId, int $vacancyId, ?User $actor): HrVacancy
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.recruitment.manage');
        $vacancy = HrVacancy::query()->where('organization_id', $organizationId)->findOrFail($vacancyId);
        $vacancy->update(['status' => 'closed']);
        return $vacancy->refresh();
    }

    public function employeeLifecycle(int $organizationId, int $employeeId, ?User $actor): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.lifecycle.manage');
        $this->employee($organizationId, $employeeId);
        return ['checklist' => HrChecklistItem::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->orderBy('due_date')->get(),
            'development' => HrDevelopmentRecord::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->orderByDesc('effective_date')->get()];
    }

    public function checklist(int $organizationId, int $employeeId, array $payload, ?User $actor): HrChecklistItem
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.lifecycle.manage');
        $this->employee($organizationId, $employeeId);
        $data = Validator::make($payload, ['lifecycle' => ['required', 'in:onboarding,offboarding'], 'title' => ['required', 'string', 'max:255'],
            'owner_id' => ['required', 'integer'], 'due_date' => ['required', 'date']])->validate();
        $this->access->owner($organizationId, (int) $data['owner_id']);
        return HrChecklistItem::create($data + ['organization_id' => $organizationId, 'employee_id' => $employeeId]);
    }

    public function completeChecklist(int $organizationId, int $employeeId, int $itemId, string $reference, ?User $actor): HrChecklistItem
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.lifecycle.manage');
        $this->employee($organizationId, $employeeId);
        Validator::make(['reference' => $reference], ['reference' => ['required', 'string', 'max:500']])->validate();
        return DB::transaction(function () use ($organizationId, $employeeId, $itemId, $reference, $actor): HrChecklistItem {
            $item = HrChecklistItem::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->lockForUpdate()->findOrFail($itemId);
            if ($item->completed_at === null) {
                $item->update(['completed_at' => now(), 'completed_by' => $actor->id, 'completion_reference' => $reference]);
            }
            return $item->refresh();
        });
    }

    public function development(int $organizationId, int $employeeId, array $payload, ?User $actor): HrDevelopmentRecord
    {
        $this->access->assert($organizationId, $actor, 'workforce.hr.lifecycle.manage');
        $this->employee($organizationId, $employeeId);
        $data = Validator::make($payload, ['record_type' => ['required', 'in:training,qualification,assessment,goal,review'], 'title' => ['required', 'string', 'max:255'],
            'owner_id' => ['required', 'integer'], 'effective_date' => ['required', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'result' => ['nullable', 'string', 'max:1000'], 'source_file_id' => ['nullable', 'integer'], 'supersedes_id' => ['nullable', 'integer']])->validate();
        $this->access->owner($organizationId, (int) $data['owner_id']);
        if (! empty($data['source_file_id'])) {
            $this->documents->file($organizationId, (int) $data['source_file_id']);
        }
        return DB::transaction(function () use ($organizationId, $employeeId, $data, $actor): HrDevelopmentRecord {
            if (! empty($data['supersedes_id'])) {
                $previous = HrDevelopmentRecord::query()->where('organization_id', $organizationId)->where('employee_id', $employeeId)->lockForUpdate()->findOrFail($data['supersedes_id']);
                if ($previous->record_type !== $data['record_type'] || HrDevelopmentRecord::query()->where('supersedes_id', $previous->id)->exists()) {
                    throw new DomainException(trans_message('workforce.errors.workflow_transition_forbidden'));
                }
            }
            // Append-only goals/reviews never change employment status; occupational safety remains in SafetyManagement.
            return HrDevelopmentRecord::create($data + ['organization_id' => $organizationId, 'employee_id' => $employeeId, 'recorded_by' => $actor->id]);
        });
    }

    private function employee(int $organizationId, int $employeeId): void
    {
        WorkforceEmployee::query()->where('organization_id', $organizationId)->findOrFail($employeeId);
    }
}
