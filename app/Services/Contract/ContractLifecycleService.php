<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Enums\Contract\ContractStatusEnum;
use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
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
        private readonly LegalDocumentAudit $audit,
    ) {}

    public function transition(Contract $contract, string $action, User $actor, ?string $reason): Contract
    {
        $applyTransition = function (Contract $contract) use ($actor, $action, $reason): Contract {
            $currentStatus = $contract->status instanceof ContractStatusEnum
                ? $contract->status->value
                : (string) $contract->status;
            $targetStatus = self::TRANSITIONS[$currentStatus][$action] ?? null;

            if ($targetStatus === null) {
                throw new BusinessLogicException(trans_message('contracts.invalid_transition'), 409);
            }

            $contract->status = ContractStatusEnum::from($targetStatus);
            $contract->save();

            if ($contract->exists) {
                $stateEvent = $this->stateEventService->createStatusTransitionEvent(
                    $contract,
                    $action,
                    $currentStatus,
                    $targetStatus,
                    $reason,
                    (int) $actor->id
                );
                $this->audit->recordContractForActorId($action, $contract, (int) $actor->id, [
                    'before' => ['status' => $currentStatus],
                    'after' => ['status' => $targetStatus],
                    'reason' => $reason,
                    'source_event_id' => 'contract_state_event:'.(string) $stateEvent->id,
                ]);
            }

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
