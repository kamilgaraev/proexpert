<?php

namespace App\Observers;

use App\Models\Contract;
use App\Services\Contract\ContractStateEventService;
use Illuminate\Support\Facades\Log;

/**
 * Observer для автоматической синхронизации контракта с Event Sourcing
 */
class ContractObserver
{
    /**
     * Вызывается после получения модели из БД (при каждом чтении)
     */
    public function retrieved(Contract $contract): void
    {
        // Синхронизация для контрактов с Event Sourcing
        if ($contract->usesEventSourcing()) {
            // Проверяем и синхронизируем total_amount с событиями
            $this->syncTotalAmountFromEvents($contract);
        }

        // Автоматический пересчет для контрактов с нефиксированной суммой
        // (только если разница значительная, чтобы не замедлять работу)
        if (!$contract->is_fixed_amount) {
            $this->syncNonFixedContractTotal($contract);
        }
    }

    /**
     * Синхронизировать total_amount с суммой из событий
     */
    private function syncTotalAmountFromEvents(Contract $contract): void
    {
        try {
            $stateService = app(ContractStateEventService::class);
            $currentState = $stateService->getCurrentState($contract);
            $calculatedAmount = (float) $currentState['total_amount'];
            $dbAmount = (float) ($contract->total_amount ?? 0);

            // Если расхождение больше 1 копейки - автоматически исправляем
            if (abs($calculatedAmount - $dbAmount) > 0.01) {
                Log::info('Contract total_amount auto-sync', [
                    'contract_id' => $contract->id,
                    'old_amount' => $dbAmount,
                    'new_amount' => $calculatedAmount,
                    'difference' => $calculatedAmount - $dbAmount,
                ]);

                // Обновляем БД без вызова событий (чтобы избежать рекурсии)
                $contract->timestamps = false;
                $contract->updateQuietly(['total_amount' => $calculatedAmount]);
                $contract->timestamps = true;
                
                // Обновляем значение в текущем экземпляре
                $contract->total_amount = $calculatedAmount;
            }
        } catch (\Exception $e) {
            // Не критично - логируем и продолжаем
            Log::warning('Failed to sync contract total_amount from events', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Синхронизировать total_amount для контрактов с нефиксированной суммой
     * на основе актов и дополнительных соглашений
     */
    private function syncNonFixedContractTotal(Contract $contract): void
    {
        try {
            // Проверяем только если есть акты или ДС, чтобы не делать лишних запросов
            $hasActs = $contract->relationLoaded('performanceActs') 
                ? $contract->performanceActs->where('is_approved', true)->count() > 0
                : $contract->performanceActs()->where('is_approved', true)->exists();
            
            $hasAgreements = $contract->relationLoaded('agreements')
                ? $contract->agreements->count() > 0
                : $contract->agreements()->exists();

            // Если нет актов и ДС, и total_amount = 0, то все правильно
            if (!$hasActs && !$hasAgreements && ($contract->total_amount ?? 0) == 0) {
                return;
            }

            // Пересчитываем сумму
            $oldTotalAmount = $contract->total_amount ?? 0;
            $newTotalAmount = $contract->recalculateTotalAmountForNonFixed();

            if ($newTotalAmount !== null) {
                $difference = abs((float) $oldTotalAmount - $newTotalAmount);

                // Обновляем только если разница значительная (больше 1 копейки)
                if ($difference > 0.01) {
                    Log::info('Contract non-fixed total_amount auto-sync', [
                        'contract_id' => $contract->id,
                        'old_amount' => $oldTotalAmount,
                        'new_amount' => $newTotalAmount,
                        'difference' => $newTotalAmount - $oldTotalAmount,
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Не критично - логируем и продолжаем
            Log::warning('Failed to sync non-fixed contract total_amount', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

