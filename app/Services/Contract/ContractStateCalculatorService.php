<?php

namespace App\Services\Contract;

use App\Models\Contract;
use App\Models\ContractCurrentState;
use App\Models\ContractStateEvent;
use App\Repositories\Interfaces\ContractStateEventRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ContractStateCalculatorService
{
    protected ContractStateEventRepositoryInterface $eventRepository;

    public function __construct(
        ContractStateEventRepositoryInterface $eventRepository
    ) {
        $this->eventRepository = $eventRepository;
    }

    /**
     * Пересчитать и обновить материализованное представление для договора
     */
    public function recalculateContractState(Contract $contract): ContractCurrentState
    {
        $activeEvents = $this->eventRepository->findActiveEvents($contract->id);
        
        $totalAmount = $activeEvents->sum('amount_delta');
        
        // Находим активную спецификацию
        $activeSpecificationId = null;
        $lastAmendedEvent = $activeEvents
            ->where('event_type', \App\Enums\Contract\ContractStateEventTypeEnum::AMENDED)
            ->last();
        
        if ($lastAmendedEvent && $lastAmendedEvent->specification_id) {
            $activeSpecificationId = $lastAmendedEvent->specification_id;
        } else {
            $createdEvent = $activeEvents->where('event_type', \App\Enums\Contract\ContractStateEventTypeEnum::CREATED)->first();
            if ($createdEvent && $createdEvent->specification_id) {
                $activeSpecificationId = $createdEvent->specification_id;
            }
        }

        // Массив ID активных событий
        $activeEventIds = $activeEvents->pluck('id')->toArray();

        // Обновляем или создаем материализованное представление
        $currentState = ContractCurrentState::updateOrCreate(
            ['contract_id' => $contract->id],
            [
                'active_specification_id' => $activeSpecificationId,
                'current_total_amount' => $totalAmount,
                'active_events' => $activeEventIds,
                'calculated_at' => now(),
            ]
        );

        // Очистить кеш для этого договора
        Cache::forget("contract_current_state:{$contract->id}");

        return $currentState;
    }

    /**
     * Получить текущее состояние из материализованного представления (с проверкой актуальности)
     */
    public function getCurrentState(Contract $contract, bool $forceRecalculate = false): ?ContractCurrentState
    {
        if ($forceRecalculate) {
            return $this->recalculateContractState($contract);
        }

        $cacheKey = "contract_current_state:{$contract->id}";
        
        return Cache::remember($cacheKey, 300, function () use ($contract) {
            $currentState = ContractCurrentState::find($contract->id);

            // Если состояния нет или оно устарело - пересчитываем
            if (!$currentState || $currentState->isStale(10)) {
                return $this->recalculateContractState($contract);
            }

            return $currentState;
        });
    }

    /**
     * Получить состояние договора на определенную дату (из событий, не из материализованного представления)
     */
    public function getStateAtDate(Contract $contract, Carbon $date): array
    {
        $activeEvents = $this->eventRepository->findActiveEventsAsOfDate(
            $contract->id,
            $date
        );

        $totalAmount = $activeEvents->sum('amount_delta');
        $activeSpecificationId = null;
        
        $lastAmendedEvent = $activeEvents
            ->where('event_type', \App\Enums\Contract\ContractStateEventTypeEnum::AMENDED)
            ->last();
        
        if ($lastAmendedEvent && $lastAmendedEvent->specification_id) {
            $activeSpecificationId = $lastAmendedEvent->specification_id;
        } else {
            $createdEvent = $activeEvents->where('event_type', \App\Enums\Contract\ContractStateEventTypeEnum::CREATED)->first();
            if ($createdEvent && $createdEvent->specification_id) {
                $activeSpecificationId = $createdEvent->specification_id;
            }
        }

        return [
            'contract_id' => $contract->id,
            'total_amount' => $totalAmount,
            'active_specification_id' => $activeSpecificationId,
            'active_event_ids' => $activeEvents->pluck('id')->toArray(),
            'as_of_date' => $date,
        ];
    }

    /**
     * Массовый пересчет состояний для всех договоров с событиями
     */
    public function recalculateAllContracts(): int
    {
        $contracts = Contract::whereHas('stateEvents')
            ->get();

        $count = 0;
        foreach ($contracts as $contract) {
            try {
                $this->recalculateContractState($contract);
                $count++;
            } catch (\Exception $e) {
                \Log::error("Failed to recalculate state for contract {$contract->id}: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Массовый пересчет состояний для конкретного договора
     */
    public function recalculateContract(int $contractId): bool
    {
        $contract = Contract::find($contractId);
        
        if (!$contract) {
            return false;
        }

        try {
            $this->recalculateContractState($contract);
            return true;
        } catch (\Exception $e) {
            \Log::error("Failed to recalculate state for contract {$contractId}: " . $e->getMessage());
            return false;
        }
    }
}

