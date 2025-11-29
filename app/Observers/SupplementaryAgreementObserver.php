<?php

namespace App\Observers;

use App\Models\SupplementaryAgreement;
use Illuminate\Support\Facades\Log;

/**
 * Observer для автоматического пересчета total_amount контракта
 * при изменении дополнительных соглашений (только для контрактов с нефиксированной суммой)
 */
class SupplementaryAgreementObserver
{
    /**
     * Вызывается после создания дополнительного соглашения
     */
    public function created(SupplementaryAgreement $agreement): void
    {
        $this->recalculateContractTotal($agreement);
    }

    /**
     * Вызывается после обновления дополнительного соглашения
     */
    public function updated(SupplementaryAgreement $agreement): void
    {
        // Пересчитываем только если изменилась сумма изменения
        if ($agreement->wasChanged('change_amount')) {
            $this->recalculateContractTotal($agreement);
        }
    }

    /**
     * Вызывается после удаления дополнительного соглашения
     */
    public function deleted(SupplementaryAgreement $agreement): void
    {
        $this->recalculateContractTotal($agreement);
    }

    /**
     * Пересчитать total_amount контракта
     */
    private function recalculateContractTotal(SupplementaryAgreement $agreement): void
    {
        try {
            $contract = $agreement->contract;
            
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
                
                Log::info('contract.total_amount.recalculated.from_agreement', [
                    'contract_id' => $contract->id,
                    'agreement_id' => $agreement->id,
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
                            $agreement, // triggeredBy - дополнительное соглашение
                            now(),
                            [
                                'reason' => 'Автоматический пересчет суммы контракта',
                                'triggered_by' => 'supplementary_agreement',
                                'agreement_id' => $agreement->id,
                                'agreement_number' => $agreement->number,
                                'change_amount' => $agreement->change_amount,
                                'old_total_amount' => $oldTotalAmount,
                                'new_total_amount' => $newTotalAmount,
                            ]
                        );
                    } catch (\Exception $e) {
                        // Не критично, если событие не создалось - логируем и продолжаем
                        Log::warning('Failed to create contract state event for agreement recalculation', [
                            'contract_id' => $contract->id,
                            'agreement_id' => $agreement->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Не критично - логируем и продолжаем
            Log::warning('Failed to recalculate contract total_amount from agreement', [
                'agreement_id' => $agreement->id,
                'contract_id' => $agreement->contract_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

