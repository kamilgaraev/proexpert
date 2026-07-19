<?php

namespace App\Observers;

use App\Models\ContractPerformanceAct;
use App\Services\Analytics\EVMService;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractAuditReconciliationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ContractPerformanceActObserver
{
    public function __construct(
        private readonly ContractAuditedMutationService $contractMutations,
        private readonly ContractAuditReconciliationService $reconciliation,
    ) {}

    public function created(ContractPerformanceAct $act): void
    {
        $this->recalculateContractTotal($act, 'created');
        $this->invalidateEVMCache($act);
    }

    public function updated(ContractPerformanceAct $act): void
    {
        if ($act->wasChanged(['amount', 'is_approved'])) {
            $this->recalculateContractTotal($act, 'updated');
        }

        if ($act->wasChanged(['amount', 'is_approved', 'project_id', 'contract_id', 'act_date', 'approval_date', 'status'])) {
            $this->invalidateEVMCache($act, true);
        }
    }

    public function deleted(ContractPerformanceAct $act): void
    {
        $this->recalculateContractTotal($act, 'deleted');
        $this->invalidateEVMCache($act);
    }

    private function recalculateContractTotal(ContractPerformanceAct $act, string $reason): void
    {
        try {
            $contract = $act->contract;

            if (! $contract || $contract->is_fixed_amount) {
                return;
            }

            $oldTotalAmount = $contract->total_amount ?? 0;
            $newTotalAmount = $contract->recalculateTotalAmountForNonFixed();

            if ($newTotalAmount === null || abs((float) $oldTotalAmount - $newTotalAmount) <= 0.01) {
                return;
            }

            $this->contractMutations->update(
                $contract,
                ['total_amount' => $newTotalAmount],
                'performance_act_total_recalculated',
                Auth::id(),
                [
                    'act_id' => (int) $act->id,
                    'reason' => $reason,
                    'source_event_id' => 'performance_act:'.(string) $act->id.':'.$reason.':'.$this->changeFingerprint($act, $reason),
                ],
            );

            $amountDelta = $newTotalAmount - $oldTotalAmount;

            Log::info('contract.total_amount.recalculated.from_act', [
                'contract_id' => $contract->id,
                'act_id' => $act->id,
                'old_total_amount' => $oldTotalAmount,
                'new_total_amount' => $newTotalAmount,
                'difference' => $amountDelta,
            ]);

            if (! $contract->usesEventSourcing()) {
                return;
            }

            try {
                $stateEventService = app(\App\Services\Contract\ContractStateEventService::class);
                $activeSpecification = $contract->specifications()->wherePivot('is_active', true)->first();

                $stateEventService->createAmendedEvent(
                    $contract,
                    $activeSpecification?->id ?? null,
                    $amountDelta,
                    $act,
                    now(),
                    [
                        'reason' => 'Автоматический пересчет суммы контракта',
                        'triggered_by' => 'performance_act',
                        'act_id' => $act->id,
                        'act_document_number' => $act->act_document_number,
                        'act_amount' => $act->amount,
                        'is_approved' => $act->is_approved,
                        'old_total_amount' => $oldTotalAmount,
                        'new_total_amount' => $newTotalAmount,
                    ]
                );
            } catch (\Exception $e) {
                Log::warning('Failed to create contract state event for act recalculation', [
                    'contract_id' => $contract->id,
                    'act_id' => $act->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            if (isset($newTotalAmount) && $contract instanceof \App\Models\Contract && is_numeric($newTotalAmount)) {
                $this->reconciliation->recordDebt($contract, 'performance_act', (string) $act->id, $this->changeFingerprint($act, $reason), (float) $newTotalAmount, $e);
            }
            Log::warning('Failed to recalculate contract total_amount from act', [
                'act_id' => $act->id,
                'contract_id' => $act->contract_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function changeFingerprint(ContractPerformanceAct $act, string $reason): string
    {
        return hash('sha256', json_encode([$reason, $act->getOriginal(), $act->getChanges()], JSON_THROW_ON_ERROR));
    }

    private function invalidateEVMCache(ContractPerformanceAct $act, bool $includeOriginal = false): void
    {
        try {
            app(EVMService::class)->invalidateCacheForPerformanceAct($act, $includeOriginal);

            Log::debug('EVM cache invalidated for project due to performance act change', [
                'contract_id' => $act->contract_id,
                'act_id' => $act->id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to invalidate EVM cache for performance act', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
