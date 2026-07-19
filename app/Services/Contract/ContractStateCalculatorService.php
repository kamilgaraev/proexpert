<?php

namespace App\Services\Contract;

use App\Models\Contract;
use App\Models\ContractCurrentState;
use App\Repositories\Interfaces\ContractStateEventRepositoryInterface;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;

class ContractStateCalculatorService
{
    private const PRICE_EVENT_TYPES = [
        'created',
        'amount_changed',
        'supplementary_agreement_applied',
    ];

    private const LEGACY_PRICE_EVENT_TYPES = [
        'amended' => 'amount_changed',
        'supplementary_agreement_created' => 'supplementary_agreement_applied',
    ];

    protected ContractStateEventRepositoryInterface $eventRepository;

    public function __construct(
        ContractStateEventRepositoryInterface $eventRepository
    ) {
        $this->eventRepository = $eventRepository;
    }

    public function calculate(iterable $events): \stdClass
    {
        $totalCents = 0;

        foreach ($events as $event) {
            $eventData = $event instanceof Arrayable ? $event->toArray() : (array) $event;
            $eventType = $eventData['event_type'] ?? null;
            $eventType = $eventType instanceof \BackedEnum ? $eventType->value : (string) $eventType;
            $canonicalType = self::LEGACY_PRICE_EVENT_TYPES[$eventType] ?? $eventType;

            if (!in_array($canonicalType, self::PRICE_EVENT_TYPES, true)) {
                continue;
            }

            $totalCents += $this->toCents($eventData['amount_delta'] ?? 0);
        }

        $sign = $totalCents < 0 ? '-' : '';
        $absoluteCents = abs($totalCents);

        return (object) [
            'totalAmount' => sprintf('%s%d.%02d', $sign, intdiv($absoluteCents, 100), $absoluteCents % 100),
        ];
    }

    private function toCents(mixed $amount): int
    {
        $normalizedAmount = str_replace(',', '.', trim((string) $amount));

        if (!preg_match('/^(?<sign>-?)(?<whole>\d+)(?:\.(?<fraction>\d+))?$/', $normalizedAmount, $matches)) {
            return (int) round((float) $amount * 100);
        }

        $fraction = str_pad(substr($matches['fraction'] ?? '', 0, 2), 2, '0');
        $cents = ((int) $matches['whole'] * 100) + (int) $fraction;

        return $matches['sign'] === '-' ? -$cents : $cents;
    }

    /**
     * Пересчитать и обновить материализованное представление для договора
     */
    public function recalculateContractState(Contract $contract): ContractCurrentState
    {
        $activeEvents = $this->eventRepository->findActiveEvents($contract->id);
        
        $totalAmount = $this->calculate($activeEvents)->totalAmount;
        
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

        $totalAmount = $this->calculate($activeEvents)->totalAmount;
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
