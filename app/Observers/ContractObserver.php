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
        // Синхронизация только для контрактов с Event Sourcing
        if (!$contract->usesEventSourcing()) {
            return;
        }

        // Проверяем и синхронизируем total_amount с событиями
        $this->syncTotalAmountFromEvents($contract);
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
}

