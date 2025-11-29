<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\ContractStateEventRepositoryInterface;
use App\Models\Contract;
use App\Models\ContractStateEvent;
use App\Models\SupplementaryAgreement;
use App\Models\ContractPayment;
use App\Enums\Contract\ContractStateEventTypeEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Exception;

class ContractStateEventService
{
    protected ContractStateEventRepositoryInterface $eventRepository;

    public function __construct(
        ContractStateEventRepositoryInterface $eventRepository
    ) {
        $this->eventRepository = $eventRepository;
    }

    /**
     * Создать событие для нового договора
     */
    public function createContractCreatedEvent(Contract $contract, ?int $specificationId = null): ContractStateEvent
    {
        return DB::transaction(function () use ($contract, $specificationId) {
            $data = [
                'contract_id' => $contract->id,
                'event_type' => ContractStateEventTypeEnum::CREATED,
                'triggered_by_type' => Contract::class,
                'triggered_by_id' => $contract->id,
                'specification_id' => $specificationId,
                'amount_delta' => $contract->total_amount ?? 0,
                'effective_from' => $contract->date ?? now(),
                'metadata' => [
                    'total_amount' => $contract->total_amount,
                    'contract_number' => $contract->number,
                    'contractor_id' => $contract->contractor_id,
                ],
                'created_by_user_id' => Auth::id(),
            ];

            return $this->eventRepository->createEvent($data);
        });
    }

    /**
     * Создать событие изменения (amended) - новая спецификация
     */
    public function createAmendedEvent(
        Contract $contract,
        ?int $specificationId,
        float $amountDelta,
        $triggeredBy = null,
        ?Carbon $effectiveFrom = null,
        array $metadata = []
    ): ContractStateEvent {
        return DB::transaction(function () use ($contract, $specificationId, $amountDelta, $triggeredBy, $effectiveFrom, $metadata) {
            $triggeredByType = Contract::class;
            $triggeredById = $contract->id;

            if ($triggeredBy instanceof SupplementaryAgreement) {
                $triggeredByType = SupplementaryAgreement::class;
                $triggeredById = $triggeredBy->id;
            } elseif ($triggeredBy instanceof \App\Models\ContractPerformanceAct) {
                $triggeredByType = \App\Models\ContractPerformanceAct::class;
                $triggeredById = $triggeredBy->id;
            }

            $data = [
                'contract_id' => $contract->id,
                'event_type' => ContractStateEventTypeEnum::AMENDED,
                'triggered_by_type' => $triggeredByType,
                'triggered_by_id' => $triggeredById,
                'specification_id' => $specificationId,
                'amount_delta' => $amountDelta,
                'effective_from' => $effectiveFrom ?? now(),
                'metadata' => array_merge([
                    'specification_id' => $specificationId,
                    'amount_delta' => $amountDelta,
                ], $metadata),
                'created_by_user_id' => Auth::id(),
            ];

            return $this->eventRepository->createEvent($data);
        });
    }

    /**
     * Создать событие аннулирования (superseded)
     */
    public function createSupersededEvent(
        Contract $contract,
        ContractStateEvent $supersededEvent,
        $triggeredBy = null,
        array $metadata = []
    ): ContractStateEvent {
        return DB::transaction(function () use ($contract, $supersededEvent, $triggeredBy, $metadata) {
            if (!$supersededEvent->isActive()) {
                throw new Exception('Событие уже аннулировано');
            }

            $triggeredByType = Contract::class;
            $triggeredById = $contract->id;

            if ($triggeredBy instanceof SupplementaryAgreement) {
                $triggeredByType = SupplementaryAgreement::class;
                $triggeredById = $triggeredBy->id;
            }

            $data = [
                'contract_id' => $contract->id,
                'event_type' => ContractStateEventTypeEnum::SUPERSEDED,
                'triggered_by_type' => $triggeredByType,
                'triggered_by_id' => $triggeredById,
                'specification_id' => null,
                'amount_delta' => -$supersededEvent->amount_delta, // Отрицательная сумма для компенсации
                'effective_from' => now(),
                'supersedes_event_id' => $supersededEvent->id,
                'metadata' => array_merge([
                    'superseded_event_id' => $supersededEvent->id,
                    'reason' => 'Аннулировано',
                    'previous_specification_id' => $supersededEvent->specification_id,
                ], $metadata),
                'created_by_user_id' => Auth::id(),
            ];

            return $this->eventRepository->createEvent($data);
        });
    }

    /**
     * Создать доп.соглашение с аннулированием предыдущего
     */
    public function createAmendmentWithSupersede(
        Contract $contract,
        SupplementaryAgreement $agreement,
        ?ContractStateEvent $previousActiveEvent = null,
        ?int $newSpecificationId = null,
        ?float $newAmount = null
    ): array {
        return DB::transaction(function () use ($contract, $agreement, $previousActiveEvent, $newSpecificationId, $newAmount) {
            $events = [];

            // Если указано предыдущее активное событие - аннулируем его
            if ($previousActiveEvent) {
                $supersededEvent = $this->createSupersededEvent(
                    $contract,
                    $previousActiveEvent,
                    $agreement,
                    ['reason' => 'Аннулировано доп. соглашением ' . $agreement->number]
                );
                $events[] = $supersededEvent;
            } else {
                // Ищем последнее активное событие автоматически
                $activeEvents = $this->eventRepository->findActiveEvents($contract->id);
                if ($activeEvents->isNotEmpty()) {
                    $latestActiveEvent = $activeEvents->last();
                    $supersededEvent = $this->createSupersededEvent(
                        $contract,
                        $latestActiveEvent,
                        $agreement,
                        ['reason' => 'Аннулировано доп. соглашением ' . $agreement->number]
                    );
                    $events[] = $supersededEvent;
                }
            }

            // Создаем новое событие amended с новой спецификацией
            if ($newSpecificationId || $newAmount) {
                $amountDelta = $newAmount ?? ($agreement->change_amount ?? 0);
                
                $amendedEvent = $this->createAmendedEvent(
                    $contract,
                    $newSpecificationId ?? null,
                    $amountDelta,
                    $agreement,
                    $agreement->agreement_date ?? now(),
                    [
                        'agreement_id' => $agreement->id,
                        'agreement_number' => $agreement->number,
                    ]
                );
                $events[] = $amendedEvent;
            }

            return $events;
        });
    }

    /**
     * Аннулировать выбранные ДС без изменения суммы контракта
     */
    public function supersedeAgreementsWithoutAmountChange(
        Contract $contract,
        SupplementaryAgreement $agreement,
        array $supersedeAgreementIds
    ): array {
        return DB::transaction(function () use ($contract, $agreement, $supersedeAgreementIds) {
            $events = [];

            // Находим все активные события контракта
            $activeEvents = $this->eventRepository->findActiveEvents($contract->id);
            
            // Фильтруем только события, влияющие на сумму контракта
            $amountAffectingEvents = $activeEvents->filter(function ($event) {
                return !in_array($event->event_type, [
                    ContractStateEventTypeEnum::PAYMENT_CREATED
                ]);
            });

            // Находим события, связанные с выбранными ДС для аннулирования
            $eventsToSupersede = $amountAffectingEvents->filter(function ($event) use ($supersedeAgreementIds) {
                return $event->triggered_by_type === SupplementaryAgreement::class
                    && in_array($event->triggered_by_id, $supersedeAgreementIds)
                    && $event->isActive();
            });

            if ($eventsToSupersede->isEmpty()) {
                throw new Exception('Не найдено активных событий для указанных дополнительных соглашений');
            }

            // Вычисляем сумму из событий, которые будут аннулированы
            $supersededAmount = $eventsToSupersede->sum('amount_delta');
            
            // Чтобы сохранить текущую сумму после аннулирования,
            // нужно создать компенсирующее событие с противоположной суммой
            // Но проще - после аннулирования события сумма автоматически пересчитается
            // и станет меньше на supersededAmount
            // Поэтому создаем событие AMENDED с дельтой = +supersededAmount для компенсации
            // Это сохранит текущую сумму контракта

            // Аннулируем выбранные события
            foreach ($eventsToSupersede as $eventToSupersede) {
                $supersededEvent = $this->createSupersededEvent(
                    $contract,
                    $eventToSupersede,
                    $agreement,
                    [
                        'reason' => 'Аннулировано доп. соглашением ' . $agreement->number . ' без изменения суммы',
                        'superseded_agreement_id' => $eventToSupersede->triggered_by_id,
                    ]
                );
                $events[] = $supersededEvent;
            }

            // Создаем компенсирующее событие AMENDED, чтобы сумма осталась прежней
            // Дельта = сумма аннулированных событий (компенсация)
            $compensatingEvent = $this->createAmendedEvent(
                $contract,
                null, // без спецификации, так как сумма не меняется
                $supersededAmount, // компенсация аннулированной суммы
                $agreement,
                $agreement->agreement_date ?? now(),
                [
                    'agreement_id' => $agreement->id,
                    'agreement_number' => $agreement->number,
                    'reason' => 'Компенсация аннулированных ДС без изменения суммы контракта',
                    'superseded_agreement_ids' => $supersedeAgreementIds,
                    'superseded_events_count' => $eventsToSupersede->count(),
                    'is_compensating' => true,
                ]
            );
            $events[] = $compensatingEvent;

            return $events;
        });
    }

    /**
     * Создать событие для дополнительного соглашения
     */
    public function createSupplementaryAgreementEvent(
        Contract $contract,
        SupplementaryAgreement $agreement
    ): ContractStateEvent {
        return DB::transaction(function () use ($contract, $agreement) {
            $data = [
                'contract_id' => $contract->id,
                'event_type' => ContractStateEventTypeEnum::SUPPLEMENTARY_AGREEMENT_CREATED,
                'triggered_by_type' => SupplementaryAgreement::class,
                'triggered_by_id' => $agreement->id,
                'specification_id' => null,
                'amount_delta' => $agreement->change_amount ?? 0,
                'effective_from' => $agreement->agreement_date ?? now(),
                'metadata' => [
                    'agreement_id' => $agreement->id,
                    'agreement_number' => $agreement->number,
                    'change_amount' => $agreement->change_amount,
                    'subject_changes' => $agreement->subject_changes ?? [],
                ],
                'created_by_user_id' => Auth::id(),
            ];

            return $this->eventRepository->createEvent($data);
        });
    }

    /**
     * Создать событие для платежа
     */
    public function createPaymentEvent(
        Contract $contract,
        $payment
    ): ContractStateEvent {
        return DB::transaction(function () use ($contract, $payment) {
            $paymentType = get_class($payment);
            
            $data = [
                'contract_id' => $contract->id,
                'event_type' => ContractStateEventTypeEnum::PAYMENT_CREATED,
                'triggered_by_type' => $paymentType,
                'triggered_by_id' => $payment->id,
                'specification_id' => null,
                'amount_delta' => $payment->amount ?? 0,
                'effective_from' => $payment->payment_date ?? now(),
                'metadata' => [
                    'payment_id' => $payment->id,
                    'payment_type' => $payment->payment_type ?? null,
                    'amount' => $payment->amount,
                    'description' => $payment->description ?? null,
                ],
                'created_by_user_id' => Auth::id(),
            ];

            return $this->eventRepository->createEvent($data);
        });
    }

    /**
     * Получить текущее состояние договора
     */
    public function getCurrentState(Contract $contract): array
    {
        $activeEvents = $this->eventRepository->findActiveEvents($contract->id, ['specification', 'createdBy']);

        // Рассчитываем сумму из событий, влияющих на сумму контракта
        // PAYMENT_CREATED не влияет на total_amount контракта (это платежи, не изменения суммы договора)
        $calculatedAmount = $activeEvents
            ->filter(function ($event) {
                return !in_array($event->event_type, [
                    ContractStateEventTypeEnum::PAYMENT_CREATED
                ]);
            })
            ->sum('amount_delta');
        
        // ИСПОЛЬЗУЕМ РАССЧИТАННОЕ ЗНАЧЕНИЕ ИЗ СОБЫТИЙ КАК ИСТОЧНИК ИСТИНЫ
        // Event Sourcing: состояние восстанавливается из событий
        $totalAmount = (float) $calculatedAmount;
        
        // Проверяем согласованность с БД и логируем расхождения для аудита
        $dbTotalAmount = (float) ($contract->total_amount ?? 0);
        if (abs($calculatedAmount - $dbTotalAmount) > 0.01) {
            \Illuminate\Support\Facades\Log::warning('Contract state amount mismatch detected', [
                'contract_id' => $contract->id,
                'db_total_amount' => $dbTotalAmount,
                'calculated_from_events' => $calculatedAmount,
                'difference' => $calculatedAmount - $dbTotalAmount,
                'active_events_count' => $activeEvents->count(),
            ]);
        }
        
        $activeSpecification = null;
        
        // Последняя спецификация из активных событий
        $lastAmendedEvent = $activeEvents
            ->where('event_type', ContractStateEventTypeEnum::AMENDED)
            ->last();
        
        if ($lastAmendedEvent && $lastAmendedEvent->specification_id) {
            $activeSpecification = $lastAmendedEvent->specification;
        } elseif ($activeEvents->isNotEmpty()) {
            $createdEvent = $activeEvents->where('event_type', ContractStateEventTypeEnum::CREATED)->first();
            if ($createdEvent && $createdEvent->specification_id) {
                $activeSpecification = $createdEvent->specification;
            }
        }

        return [
            'contract_id' => $contract->id,
            'total_amount' => $totalAmount,
            'active_specification' => $activeSpecification,
            'active_events' => $activeEvents,
            'as_of_date' => now(),
        ];
    }

    /**
     * Получить состояние договора на определенную дату
     */
    public function getStateAtDate(Contract $contract, Carbon $date): array
    {
        $activeEvents = $this->eventRepository->findActiveEventsAsOfDate(
            $contract->id,
            $date,
            ['specification', 'createdBy']
        );

        // Рассчитываем сумму только из событий, влияющих на сумму контракта
        // PAYMENT_CREATED не влияет на total_amount контракта
        $totalAmount = $activeEvents
            ->filter(function ($event) {
                return !in_array($event->event_type, [
                    ContractStateEventTypeEnum::PAYMENT_CREATED
                ]);
            })
            ->sum('amount_delta');
        $activeSpecification = null;
        
        $lastAmendedEvent = $activeEvents
            ->where('event_type', ContractStateEventTypeEnum::AMENDED)
            ->last();
        
        if ($lastAmendedEvent && $lastAmendedEvent->specification_id) {
            $activeSpecification = $lastAmendedEvent->specification;
        }

        return [
            'contract_id' => $contract->id,
            'total_amount' => $totalAmount,
            'active_specification' => $activeSpecification,
            'active_events' => $activeEvents,
            'as_of_date' => $date,
        ];
    }

    /**
     * Получить timeline событий для договора
     */
    public function getTimeline(Contract $contract, ?Carbon $asOfDate = null)
    {
        return $this->eventRepository->getTimeline($contract->id, $asOfDate);
    }
}

