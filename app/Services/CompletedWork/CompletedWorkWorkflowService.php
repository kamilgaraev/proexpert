<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ProjectOrganizationRole;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Logging\LoggingService;
use App\Services\Project\ProjectContextService;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class CompletedWorkWorkflowService
{
    public function __construct(
        private readonly ProjectContextService $projectContextService,
        private readonly ContractAuditedMutationService $contractMutations,
        private readonly LoggingService $logging,
        private readonly CompletedWorkScopeResolver $scopeResolver,
        private readonly CompletedWorkFactReadiness $factReadiness,
    ) {}

    public function confirm(CompletedWork $work, User $actor): CompletedWork
    {
        return DB::transaction(function () use ($work, $actor): CompletedWork {
            /** @var CompletedWork|null $lockedWork */
            $lockedWork = CompletedWork::query()
                ->with(['project', 'journalEntry'])
                ->whereKey($work->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedWork) {
                throw new BusinessLogicException(trans_message('completed_work.not_found'), 404);
            }
            $this->scopeResolver->assertCorrection($lockedWork, $actor);

            $actor->loadMissing('currentOrganization');
            $context = $actor->currentOrganization
                ? $this->projectContextService->getContext($lockedWork->project, $actor->currentOrganization)
                : null;
            if (! $context || $context->projectId !== (int) $lockedWork->project_id || ! $this->canConfirm($context->role)) {
                throw new BusinessLogicException(trans_message('completed_work.forbidden'), 403);
            }

            if ($lockedWork->status === CompletedWork::STATUS_CONFIRMED) {
                return $lockedWork->fresh(['materials.measurementUnit', 'project', 'journalEntry']);
            }

            if (in_array($lockedWork->status, [CompletedWork::STATUS_CANCELLED, CompletedWork::STATUS_REJECTED], true)) {
                throw new BusinessLogicException(trans_message('completed_work.confirm_invalid_status'), 422);
            }

            $this->assertOriginReadyForConfirmation($lockedWork);
            $this->factReadiness->assertReady($lockedWork);

            $previousStatus = $lockedWork->status;
            $lockedWork->forceFill(['status' => CompletedWork::STATUS_CONFIRMED])->save();

            if ($lockedWork->contract_id) {
                $contract = Contract::query()->find($lockedWork->contract_id);
                if ($contract) {
                    $this->contractMutations->syncCompletionStatus($contract, $actor->id, [
                        'origin' => 'completed_work_confirmation',
                    ]);
                }
            }

            $this->logging->business('completed_work.confirmed', [
                'work_id' => $lockedWork->id,
                'project_id' => $lockedWork->project_id,
                'organization_id' => $lockedWork->organization_id,
                'previous_status' => $previousStatus,
                'performed_by' => $actor->id,
            ]);
            $this->logging->audit('completed_work.confirmed', [
                'work_id' => $lockedWork->id,
                'project_id' => $lockedWork->project_id,
                'organization_id' => $lockedWork->organization_id,
                'performed_by' => $actor->id,
            ]);

            return $lockedWork->fresh(['materials.measurementUnit', 'project', 'journalEntry']);
        });
    }

    private function canConfirm(ProjectOrganizationRole $role): bool
    {
        return in_array($role, [
            ProjectOrganizationRole::OWNER,
            ProjectOrganizationRole::GENERAL_CONTRACTOR,
        ], true);
    }

    private function assertOriginReadyForConfirmation(CompletedWork $work): void
    {
        if (! in_array($work->work_origin_type, [
            CompletedWork::ORIGIN_MANUAL,
            CompletedWork::ORIGIN_SCHEDULE,
            CompletedWork::ORIGIN_JOURNAL,
        ], true)) {
            throw new BusinessLogicException(trans_message('completed_work.invalid_origin'), 422);
        }

        if ($work->work_origin_type === CompletedWork::ORIGIN_MANUAL && $work->journal_entry_id !== null) {
            throw new BusinessLogicException(trans_message('completed_work.invalid_origin'), 422);
        }

        if ($work->work_origin_type === CompletedWork::ORIGIN_SCHEDULE && $work->schedule_task_id === null) {
            throw new BusinessLogicException(trans_message('completed_work.schedule_origin_requires_task'), 422);
        }

        if ($work->work_origin_type === CompletedWork::ORIGIN_JOURNAL
            && ($work->journal_entry_id === null || $work->journalEntry?->status !== JournalEntryStatusEnum::APPROVED)) {
            throw new BusinessLogicException(trans_message('completed_work.journal_not_approved'), 422);
        }
    }
}
