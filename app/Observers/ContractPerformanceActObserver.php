<?php

namespace App\Observers;

use App\Models\ContractPerformanceAct;
use Illuminate\Support\Facades\Log;

/**
 * Observer для автоматического пересчета total_amount контракта
 * при изменении актов выполненных работ (только для контрактов с нефиксированной суммой)
 */
class ContractPerformanceActObserver
{
    /**
     * Вызывается после создания акта
     */
    public function created(ContractPerformanceAct $act): void
    {
        $this->recalculateContractTotal($act);
    }

    /**
     * Вызывается после обновления акта
     */
    public function updated(ContractPerformanceAct $act): void
    {
        // Пересчитываем только если изменилась сумма или статус одобрения
        if ($act->wasChanged(['amount', 'is_approved'])) {
            $this->recalculateContractTotal($act);
        }
    }

    /**
     * Вызывается после удаления акта
     */
    public function deleted(ContractPerformanceAct $act): void
    {
        $this->recalculateContractTotal($act);
    }

    /**
     * Пересчитать total_amount контракта
     */
    private function recalculateContractTotal(ContractPerformanceAct $act): void
    {
        try {
            $contract = $act->contract;
            
            if (!$contract) {
                return;
            }

            // Пересчет только для контрактов с нефиксированной суммой
            if ($contract->is_fixed_amount) {
                return;
            }

            $oldTotalAmount = $contract->total_amount ?? 0;
            $newTotalAmount = $contract->recalculateTotalAmountForNonFixed();

            if ($newTotalAmount !== null && abs((float) $oldTotalAmount - $newTotalAmount) > 0.01) {
                $amountDelta = $newTotalAmount - $oldTotalAmount;
                
                Log::info('contract.total_amount.recalculated.from_act', [
                    'contract_id' => $contract->id,
                    'act_id' => $act->id,
                    'old_total_amount' => $oldTotalAmount,
                    'new_total_amount' => $newTotalAmount,
                    'difference' => $amountDelta,
                ]);

                // Создаем событие в истории изменений, если контракт использует Event Sourcing
                if ($contract->usesEventSourcing()) {
                    try {
                        $stateEventService = app(\App\Services\Contract\ContractStateEventService::class);
                        
                        // Находим активную спецификацию для события (если есть)
                        $activeSpecification = $contract->specifications()->wherePivot('is_active', true)->first();
                        
                        $stateEventService->createAmendedEvent(
                            $contract,
                            $activeSpecification?->id ?? null,
                            $amountDelta,
                            $act, // triggeredBy - акт выполненных работ
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
                        // Не критично, если событие не создалось - логируем и продолжаем
                        Log::warning('Failed to create contract state event for act recalculation', [
                            'contract_id' => $contract->id,
                            'act_id' => $act->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Не критично - логируем и продолжаем
            Log::warning('Failed to recalculate contract total_amount from act', [
                'act_id' => $act->id,
                'contract_id' => $act->contract_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

