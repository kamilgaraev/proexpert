<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Enums\Contract\ContractStatusEnum;
use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ContractLifecycleService
{
    private const TRANSITIONS = [
        'draft' => ['activate' => 'active', 'archive' => 'archived'],
        'active' => ['suspend' => 'on_hold', 'complete' => 'completed', 'terminate' => 'terminated'],
        'on_hold' => ['resume' => 'active', 'terminate' => 'terminated'],
        'completed' => ['archive' => 'archived'],
        'terminated' => ['archive' => 'archived'],
    ];

    public function __construct(
        private readonly ContractStateEventService $stateEventService,
        private readonly ContractAuditedMutationService $contractMutations,
    ) {}

    public function transition(Contract $contract, string $action, User $actor, ?string $reason): Contract
    {
        $applyTransition = function (Contract $contract) use ($actor, $action, $reason): Contract {
            if ($contract->exists && $action === 'activate'
                && DB::table('contract_builder_instances')->where('contract_id', $contract->id)->exists()) {
                throw new BusinessLogicException(trans_message('contracts.builder_revision_required'), 409);
            }
            if ($contract->exists && $contract->organizationViews()->exists()) {
                $organizationId = (int) $actor->current_organization_id;
                if ($action === 'archive') {
                    $views = app(ContractOrganizationViewService::class);
                    $view = $views->find($actor, $organizationId, (int) $contract->id);
                    if ($view->visibility !== 'archived') {
                        $views->transition($actor, $organizationId, (int) $contract->id, 'archive', $view->version);
                    } else {
                        $views->transition($actor, $organizationId, (int) $contract->id, 'archive', $view->version - 1);
                    }

                    return $contract->refresh();
                }
                if ((int) $contract->organization_id !== $organizationId) {
                    throw new BusinessLogicException(trans_message('contracts.shared_lifecycle_owner_required'), 403);
                }
            }
            $currentStatus = $contract->status instanceof ContractStatusEnum
                ? $contract->status->value
                : (string) $contract->status;
            $targetStatus = self::TRANSITIONS[$currentStatus][$action] ?? null;

            if ($targetStatus === null) {
                throw new BusinessLogicException(trans_message('contracts.invalid_transition'), 409);
            }

            $contract->status = ContractStatusEnum::from($targetStatus);

            $this->contractMutations->saveDirty($contract, $action, (int) $actor->id, [
                'reason' => $reason,
            ], function (Contract $mutated) use ($action, $currentStatus, $targetStatus, $reason, $actor): array {
                $stateEvent = $this->stateEventService->createStatusTransitionEvent(
                    $mutated,
                    $action,
                    $currentStatus,
                    $targetStatus,
                    $reason,
                    (int) $actor->id,
                );

                return ['source_event_id' => 'contract_state_event:'.(string) $stateEvent->id];
            });

            return $contract->exists ? $contract->refresh() : $contract;
        };

        if (! $contract->exists) {
            return $applyTransition($contract);
        }

        return DB::transaction(function () use ($contract, $applyTransition): Contract {
            $lockedContract = Contract::query()
                ->whereKey($contract->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $applyTransition($lockedContract);
        });
    }
}
