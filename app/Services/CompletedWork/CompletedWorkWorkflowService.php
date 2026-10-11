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
use Illuminate\Support\Str;

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

    public function confirm(CompletedWork $work, User $actor, ?string $comment = null, string $action = 'confirm'): CompletedWork
    {
        return $this->transition($work, $actor, $action === 'approve' ? 'approve' : 'confirm', $comment);
    }

    public function reject(CompletedWork $work, User $actor, string $reason): CompletedWork
    {
        return $this->transition($work, $actor, 'reject', $reason);
    }

    public function requestChanges(CompletedWork $work, User $actor, string $comment): CompletedWork
    {
        return $this->transition($work, $actor, 'request_changes', $comment);
    }

    public function submit(CompletedWork $work, User $actor): CompletedWork
    {
        return $this->transition($work, $actor, 'submit', null);
    }

    public function workflowData(CompletedWork $work): array
    {
        $workflow = data_get($work->additional_info, 'mobile_workflow', []);

        return [
            'status_history' => array_values(is_array($workflow['status_history'] ?? null) ? $workflow['status_history'] : []),
            'comments' => array_values(is_array($workflow['comments'] ?? null) ? $workflow['comments'] : []),
        ];
    }

    public function assertCommentAllowed(CompletedWork $work, User $actor): void
    {
        $this->assertActorScope($work, $actor);
        if ($work->work_origin_type === CompletedWork::ORIGIN_JOURNAL || $work->journal_entry_id !== null || $work->status === CompletedWork::STATUS_CANCELLED) {
            throw new BusinessLogicException(trans_message('completed_work.workflow_invalid_status'), 422);
        }
    }

    public function assertEditable(CompletedWork $work, User $actor): void
    {
        $this->assertActorScope($work, $actor);
        app(CompletedWorkMutationGuard::class)->assertMutable($work);
        if ($work->status === CompletedWork::STATUS_CANCELLED) {
            throw new BusinessLogicException(trans_message('completed_work.workflow_invalid_status'), 422);
        }
    }

    public function assertDeletable(CompletedWork $work, User $actor): void
    {
        $this->scopeResolver->assertDelete($work, $actor);
        $this->assertOwnWork($work, $actor);
        app(CompletedWorkMutationGuard::class)->assertMutable($work);
    }

    public function availableActions(User $actor, CompletedWork $work): array
    {
        $deleteActions = [];
        try {
            $this->assertDeletable($work, $actor);
            $deleteActions[] = 'delete';
        } catch (BusinessLogicException) {
        }
        try {
            $this->assertEditable($work, $actor);
        } catch (BusinessLogicException) {
            return $deleteActions;
        }
        $actions = ['edit'];
        $context = $this->projectContextService->getContext($work->project, $actor->currentOrganization);
        $reviewable = in_array($work->status, ['draft', 'pending', 'in_review'], true);
        if ($reviewable && $this->canConfirm($context->role)) {
            $actions[] = 'reject';
        }
        try {
            $this->assertOriginReadyForConfirmation($work);
            $this->factReadiness->assertReady($work);
        } catch (BusinessLogicException) {
            return [...$actions, ...$deleteActions];
        }
        if ($reviewable && $this->canConfirm($context->role)) {
            array_unshift($actions, 'confirm');
            if (in_array($work->status, ['draft', 'pending'], true)) {
                $actions[] = 'request_changes';
            }
        }
        if (in_array($work->status, ['draft', 'pending', 'rejected'], true)) {
            $actions[] = 'submit';
        }

        return [...$actions, ...$deleteActions];
    }

    private function assertActorScope(CompletedWork $work, User $actor): void
    {
        $organizationId = (int) $actor->current_organization_id;
        if (! $actor->organizations()->where('organizations.id', $organizationId)
            ->where('organizations.is_active', true)->wherePivot('is_active', true)->exists()) {
            throw new BusinessLogicException(trans_message('completed_work.forbidden'), 403);
        }
        $actor->setRelation('currentOrganization', \App\Models\Organization::query()->findOrFail($organizationId));
        $this->scopeResolver->assertCorrection($work, $actor);
        $this->assertOwnWork($work, $actor);
    }

    private function assertOwnWork(CompletedWork $work, User $actor): void
    {
        $organizationId = (int) $actor->current_organization_id;
        $context = $this->projectContextService->getContext($work->project, $actor->currentOrganization()->first());
        if ($organizationId !== (int) $work->organization_id
            && in_array($context->role, [ProjectOrganizationRole::CONTRACTOR, ProjectOrganizationRole::SUBCONTRACTOR], true)
            && ! \App\Models\Contractor::query()->whereKey($work->contractor_id)
                ->where('organization_id', $work->organization_id)->where('source_organization_id', $organizationId)->exists()) {
            throw new BusinessLogicException(trans_message('completed_work.forbidden'), 403);
        }
    }

    private function transition(CompletedWork $work, User $actor, string $action, ?string $comment): CompletedWork
    {
        if (($comment !== null && mb_strlen($comment) > 2000)
            || (in_array($action, ['reject', 'request_changes'], true) && ($comment === null || trim($comment) === ''))) {
            throw new BusinessLogicException(trans_message('completed_work.workflow_comment_required'), 422);
        }

        return DB::transaction(function () use ($work, $actor, $action, $comment): CompletedWork {
            $lockedWork = CompletedWork::query()->with(['project', 'journalEntry'])->whereKey($work->id)->lockForUpdate()->first();
            if (! $lockedWork) {
                throw new BusinessLogicException(trans_message('completed_work.not_found'), 404);
            }
            $this->assertActorScope($lockedWork, $actor);
            if ($lockedWork->work_origin_type === CompletedWork::ORIGIN_JOURNAL || $lockedWork->journal_entry_id !== null) {
                throw new BusinessLogicException(trans_message('completed_work.correction_required'), 422);
            }
            $context = $this->projectContextService->getContext($lockedWork->project, $actor->currentOrganization);
            if ($action !== 'submit' && ! $this->canConfirm($context->role)) {
                throw new BusinessLogicException(trans_message('completed_work.forbidden'), 403);
            }
            $toStatus = match ($action) {
                'confirm', 'approve' => CompletedWork::STATUS_CONFIRMED,
                'reject' => CompletedWork::STATUS_REJECTED,
                default => CompletedWork::STATUS_IN_REVIEW,
            };
            $workflow = $this->workflowData($lockedWork);
            $last = $workflow['status_history'][array_key_last($workflow['status_history'])] ?? null;
            if ($lockedWork->status === $toStatus && ($toStatus === CompletedWork::STATUS_CONFIRMED
                || ($last && $last['action'] === $action && (int) $last['user_id'] === (int) $actor->id && $last['comment'] === $comment))) {
                return $lockedWork->fresh(['materials.measurementUnit', 'project', 'journalEntry']);
            }
            $fromStatuses = match ($action) {
                'submit' => ['draft', 'pending', 'rejected'],
                'request_changes' => ['draft', 'pending'],
                default => ['draft', 'pending', 'in_review'],
            };
            if (! in_array($lockedWork->status, $fromStatuses, true)) {
                throw new BusinessLogicException(trans_message('completed_work.workflow_invalid_status'), 422);
            }
            app(CompletedWorkMutationGuard::class)->assertMutable($lockedWork);
            if ($action !== 'reject') {
                $this->assertOriginReadyForConfirmation($lockedWork);
                $this->factReadiness->assertReady($lockedWork);
            }
            $previousStatus = $lockedWork->status;
            $entry = ['id' => (string) Str::uuid(), 'action' => $action, 'from_status' => $previousStatus,
                'to_status' => $toStatus, 'comment' => $comment, 'user_id' => (int) $actor->id, 'created_at' => now()->toIso8601String()];
            $workflow['status_history'][] = $entry;
            if ($comment !== null && trim($comment) !== '') {
                $workflow['comments'][] = [...$entry, 'id' => (string) Str::uuid(), 'action' => 'comment'];
            }
            $additionalInfo = is_array($lockedWork->additional_info) ? $lockedWork->additional_info : [];
            $additionalInfo['mobile_workflow'] = $workflow;
            $lockedWork->forceFill(['status' => $toStatus, 'additional_info' => $additionalInfo])->save();
            if ($toStatus === CompletedWork::STATUS_CONFIRMED) {
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

            } else {
                $this->logging->audit('completed_work.'.$action, ['work_id' => $lockedWork->id, 'organization_id' => $lockedWork->organization_id,
                    'project_id' => $lockedWork->project_id, 'performed_by' => $actor->id, 'previous_status' => $previousStatus]);
            }

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
