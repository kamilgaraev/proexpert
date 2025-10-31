<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\SupplementaryAgreementRepositoryInterface;
use App\Repositories\Interfaces\ContractStateEventRepositoryInterface;
use App\DTOs\SupplementaryAgreementDTO;
use App\Models\SupplementaryAgreement;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Models\ContractStateEvent;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Enums\Contract\SupplementaryAgreementStatusEnum;
use App\Services\Logging\LoggingService;
use App\Services\Contract\ContractStateEventService;
use App\Services\Contract\ContractStateCalculatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;

class SupplementaryAgreementService
{
    protected ?ContractStateEventService $stateEventService = null;
    protected ?ContractStateCalculatorService $stateCalculatorService = null;

    public function __construct(
        protected SupplementaryAgreementRepositoryInterface $repository,
        protected LoggingService $logging
    ) {}

    public function create(SupplementaryAgreementDTO $dto): SupplementaryAgreement
    {
        $agreement = $this->repository->create($dto->toArray());
        
        // Загружаем контракт для создания события
        $contract = $agreement->fresh('contract')->contract;
        
        // Создаем событие истории, если контракт использует Event Sourcing
        if ($contract && $contract->usesEventSourcing()) {
            try {
                $this->getStateEventService()->createSupplementaryAgreementEvent($contract, $agreement);
            } catch (Exception $e) {
                // Не критично, если событие не создалось - логируем и продолжаем
                \Illuminate\Support\Facades\Log::warning('Failed to create supplementary agreement event', [
                    'agreement_id' => $agreement->id,
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $agreement;
    }

    public function update(int $id, SupplementaryAgreementDTO $dto): bool
    {
        return $this->repository->update($id, $dto->toArray());
    }

    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }

    public function getById(int $id): ?SupplementaryAgreement
    {
        return $this->repository->find($id);
    }

    public function paginateByContract(int $contractId, int $perPage = 15)
    {
        return $this->repository->paginateByContract($contractId, $perPage);
    }

    public function paginate(int $perPage = 15)
    {
        return $this->repository->paginate($perPage);
    }

    public function applyChangesToContract(int $agreementId): bool
    {
        try {
            $agreement = $this->getById($agreementId);
            if (!$agreement) {
                throw new Exception('Дополнительное соглашение не найдено');
            }

            $contract = $agreement->contract;
            
            // BUSINESS: Начало применения изменений допсоглашения
            $this->logging->business('agreement.apply_changes.started', [
                'agreement_id' => $agreementId,
                'agreement_number' => $agreement->number,
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'organization_id' => $contract->organization_id,
                'user_id' => Auth::id(),
                'changes' => [
                    'change_amount' => $agreement->change_amount,
                    'has_subcontract_changes' => !empty($agreement->subcontract_changes),
                    'has_gp_changes' => !empty($agreement->gp_changes),
                    'has_advance_changes' => !empty($agreement->advance_changes),
                ]
            ]);

            // Сохраняем старые значения для логирования
            $oldValues = [
                'total_amount' => $contract->total_amount,
                'subcontract_amount' => $contract->subcontract_amount,
                'gp_percentage' => $contract->gp_percentage,
                'gp_coefficient' => $contract->gp_coefficient,
                'gp_calculation_type' => $contract->gp_calculation_type?->value,
            ];

            DB::beginTransaction();

            // 1. Применяем изменение суммы контракта
            // Приоритет у new_amount (если указан) - это абсолютная сумма с аннулированием всех предыдущих
            if ($agreement->new_amount !== null) {
                // Установка новой абсолютной суммы с аннулированием всех предыдущих событий
                $newTotalAmount = $agreement->new_amount;
                
                // Валидация: сумма контракта не может быть отрицательной
                if ($newTotalAmount < 0) {
                    throw new Exception(
                        "Невозможно применить изменения: новая сумма контракта не может быть отрицательной " .
                        "({$newTotalAmount})"
                    );
                }
                
                $contract->total_amount = $newTotalAmount;
                
                // BUSINESS: Установка новой абсолютной суммы
                $this->logging->business('agreement.contract_amount_set_absolute', [
                    'agreement_id' => $agreementId,
                    'contract_id' => $contract->id,
                    'old_amount' => $oldValues['total_amount'],
                    'new_amount' => $newTotalAmount,
                    'user_id' => Auth::id(),
                ]);
            } elseif ($agreement->change_amount !== null && $agreement->change_amount != 0) {
                // Старая логика: изменение суммы через дельту
                $newTotalAmount = $contract->total_amount + $agreement->change_amount;
                
                // Валидация: сумма контракта не может быть отрицательной
                if ($newTotalAmount < 0) {
                    throw new Exception(
                        "Невозможно применить изменения: новая сумма контракта будет отрицательной " .
                        "({$newTotalAmount}). Текущая сумма: {$contract->total_amount}, " .
                        "изменение: {$agreement->change_amount}"
                    );
                }
                
                $contract->total_amount = $newTotalAmount;
                
                // BUSINESS: Изменение суммы контракта
                $this->logging->business('agreement.contract_amount_changed', [
                    'agreement_id' => $agreementId,
                    'contract_id' => $contract->id,
                    'old_amount' => $oldValues['total_amount'],
                    'change_amount' => $agreement->change_amount,
                    'new_amount' => $newTotalAmount,
                    'user_id' => Auth::id(),
                ]);
            }

            // 2. Применяем изменения субподряда
            if ($agreement->subcontract_changes) {
                $this->applySubcontractChanges($contract, $agreement->subcontract_changes);
            }

            // 3. Применяем изменения ГП
            if ($agreement->gp_changes) {
                $this->applyGpChanges($contract, $agreement->gp_changes);
            }

            // 4. Применяем изменения авансов
            if ($agreement->advance_changes) {
                $this->applyAdvanceChanges($contract, $agreement->advance_changes);
            }

            // Сохраняем контракт со всеми изменениями
            $contract->save();

            // Если контракт не использует Event Sourcing, активируем его
            if (!$contract->usesEventSourcing()) {
                try {
                    // Создаем начальное событие CREATED для активации Event Sourcing
                    $this->getStateEventService()->createContractCreatedEvent($contract);
                    \Illuminate\Support\Facades\Log::info('Event Sourcing activated for contract via agreement', [
                        'contract_id' => $contract->id,
                        'agreement_id' => $agreementId
                    ]);
                } catch (Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to activate Event Sourcing for contract', [
                        'contract_id' => $contract->id,
                        'agreement_id' => $agreementId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Создаем событие для примененного доп.соглашения, если Event Sourcing активен
            $contract->refresh(); // Обновляем модель чтобы получить актуальный статус usesEventSourcing
            if ($contract->usesEventSourcing()) {
                try {
                    // Находим активную спецификацию для события (если есть)
                    $activeSpecification = $contract->specifications()->wherePivot('is_active', true)->first();
                    
                    if ($agreement->new_amount !== null) {
                        // Если указана новая абсолютная сумма - аннулируем все предыдущие события
                        $events = $this->getStateEventService()->createAmendmentWithAllSupersede(
                            $contract,
                            $agreement,
                            $agreement->new_amount,
                            $activeSpecification?->id
                        );
                        
                        // BUSINESS: Логирование массового аннулирования
                        $this->logging->business('agreement.all_events_superseded', [
                            'agreement_id' => $agreementId,
                            'contract_id' => $contract->id,
                            'new_amount' => $agreement->new_amount,
                            'events_created' => count($events),
                            'user_id' => Auth::id(),
                        ]);
                    } else {
                        // Старая логика: простое изменение суммы через дельту
                        $this->getStateEventService()->createAmendedEvent(
                            $contract,
                            $activeSpecification?->id,
                            $agreement->change_amount ?? 0,
                            $agreement,
                            $agreement->agreement_date ?? now(),
                            [
                                'agreement_number' => $agreement->number,
                                'reason' => 'Применено дополнительное соглашение'
                            ]
                        );
                    }

                    // Обновляем материализованное представление
                    $this->getStateCalculatorService()->recalculateContractState($contract);
                } catch (Exception $e) {
                    \Illuminate\Support\Facades\Log::warning('Failed to create state event for agreement', [
                        'contract_id' => $contract->id,
                        'agreement_id' => $agreementId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            DB::commit();

            // BUSINESS: Изменения успешно применены
            $this->logging->business('agreement.apply_changes.success', [
                'agreement_id' => $agreementId,
                'contract_id' => $contract->id,
                'organization_id' => $contract->organization_id,
                'user_id' => Auth::id(),
                'old_values' => $oldValues,
                'new_values' => [
                    'total_amount' => $contract->total_amount,
                    'subcontract_amount' => $contract->subcontract_amount,
                    'gp_percentage' => $contract->gp_percentage,
                    'gp_coefficient' => $contract->gp_coefficient,
                    'gp_calculation_type' => $contract->gp_calculation_type?->value,
                ]
            ]);

            // AUDIT: Применение допсоглашения для compliance
            $this->logging->audit('agreement.applied_to_contract', [
                'agreement_id' => $agreementId,
                'agreement_number' => $agreement->number,
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'organization_id' => $contract->organization_id,
                'user_id' => Auth::id(),
                'total_amount_delta' => $contract->total_amount - $oldValues['total_amount'],
            ]);

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            
            // BUSINESS: Ошибка применения изменений
            $this->logging->business('agreement.apply_changes.failed', [
                'agreement_id' => $agreementId,
                'contract_id' => $agreement?->contract_id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            
            throw $e;
        }
    }

    private function applySubcontractChanges(Contract $contract, array $changes): void
    {
        if (isset($changes['amount'])) {
            // Валидация: сумма субподряда не может быть отрицательной
            if ($changes['amount'] < 0) {
                throw new Exception(
                    "Невозможно применить изменения: сумма субподряда не может быть отрицательной ({$changes['amount']})"
                );
            }
            
            $oldAmount = $contract->subcontract_amount;
            $contract->subcontract_amount = $changes['amount'];
            
            // TECHNICAL: Изменение суммы субподряда
            $this->logging->technical('agreement.subcontract_amount_changed', [
                'contract_id' => $contract->id,
                'old_amount' => $oldAmount,
                'new_amount' => $changes['amount'],
                'user_id' => Auth::id(),
            ]);
        }
    }

    private function applyGpChanges(Contract $contract, array $changes): void
    {
        $oldValues = [
            'gp_percentage' => $contract->gp_percentage,
            'gp_coefficient' => $contract->gp_coefficient,
            'gp_calculation_type' => $contract->gp_calculation_type?->value,
        ];

        if (isset($changes['percentage'])) {
            // Примечание: процент ГП может быть отрицательным (по требованиям)
            $contract->gp_percentage = $changes['percentage'];
            $contract->gp_calculation_type = GpCalculationTypeEnum::PERCENTAGE;
        }

        if (isset($changes['coefficient'])) {
            // Валидация: коэффициент должен быть положительным
            if ($changes['coefficient'] <= 0) {
                throw new Exception(
                    "Невозможно применить изменения: коэффициент ГП должен быть положительным ({$changes['coefficient']})"
                );
            }
            $contract->gp_coefficient = $changes['coefficient'];
            $contract->gp_calculation_type = GpCalculationTypeEnum::COEFFICIENT;
        }

        if (isset($changes['calculation_type'])) {
            $contract->gp_calculation_type = GpCalculationTypeEnum::from($changes['calculation_type']);
        }

        // TECHNICAL: Изменение параметров ГП
        $this->logging->technical('agreement.gp_changed', [
            'contract_id' => $contract->id,
            'old_values' => $oldValues,
            'new_values' => [
                'gp_percentage' => $contract->gp_percentage,
                'gp_coefficient' => $contract->gp_coefficient,
                'gp_calculation_type' => $contract->gp_calculation_type?->value,
            ],
            'user_id' => Auth::id(),
        ]);
    }

    private function applyAdvanceChanges(Contract $contract, array $changes): void
    {
        foreach ($changes as $change) {
            if (!isset($change['payment_id']) || !isset($change['new_amount'])) {
                continue;
            }

            // Валидация: сумма платежа не может быть отрицательной
            if ($change['new_amount'] < 0) {
                throw new Exception(
                    "Невозможно применить изменения: сумма авансового платежа не может быть отрицательной " .
                    "(платеж ID: {$change['payment_id']}, сумма: {$change['new_amount']})"
                );
            }

            $payment = ContractPayment::where('id', $change['payment_id'])
                ->where('contract_id', $contract->id)
                ->where('payment_type', 'advance')
                ->first();

            if ($payment) {
                $oldAmount = $payment->amount;
                $payment->amount = $change['new_amount'];
                $payment->save();

                // TECHNICAL: Изменение суммы авансового платежа
                $this->logging->technical('agreement.advance_payment_changed', [
                    'contract_id' => $contract->id,
                    'payment_id' => $payment->id,
                    'old_amount' => $oldAmount,
                    'new_amount' => $change['new_amount'],
                    'delta' => $change['new_amount'] - $oldAmount,
                    'user_id' => Auth::id(),
                ]);
            } else {
                // WARNING: Попытка изменить несуществующий платеж
                $this->logging->technical('agreement.advance_payment_not_found', [
                    'contract_id' => $contract->id,
                    'payment_id' => $change['payment_id'],
                    'user_id' => Auth::id(),
                ]);
            }
        }
    }

    /**
     * Создать доп.соглашение с аннулированием предыдущего через Event Sourcing
     */
    public function createWithSupersede(
        Contract $contract,
        SupplementaryAgreementDTO $dto,
        ?ContractStateEvent $previousActiveEvent = null,
        ?int $newSpecificationId = null
    ): SupplementaryAgreement {
        return DB::transaction(function () use ($contract, $dto, $previousActiveEvent, $newSpecificationId) {
            // Создаем доп.соглашение
            $agreement = $this->repository->create(array_merge($dto->toArray(), [
                'contract_id' => $contract->id,
                'status' => SupplementaryAgreementStatusEnum::ACTIVE->value,
            ]));

            // Если договор использует Event Sourcing, создаем события
            if ($contract->usesEventSourcing()) {
                try {
                    $events = $this->getStateEventService()->createAmendmentWithSupersede(
                        $contract,
                        $agreement,
                        $previousActiveEvent,
                        $newSpecificationId,
                        $agreement->change_amount ?? null
                    );

                    // Обновляем материализованное представление
                    $this->getStateCalculatorService()->recalculateContractState($contract);

                    $this->logging->business('agreement.created_with_supersede', [
                        'agreement_id' => $agreement->id,
                        'contract_id' => $contract->id,
                        'events_created' => count($events),
                        'user_id' => Auth::id(),
                    ]);
                } catch (Exception $e) {
                    $this->logging->technical('agreement.event_creation.failed', [
                        'agreement_id' => $agreement->id,
                        'contract_id' => $contract->id,
                        'error' => $e->getMessage(),
                        'user_id' => Auth::id(),
                    ], 'error');
                    // Не прерываем транзакцию - доп.соглашение уже создано
                }
            }

            return $agreement;
        });
    }

    /**
     * Аннулировать доп.соглашение
     */
    public function supersedeAgreement(
        SupplementaryAgreement $agreement,
        SupplementaryAgreement $newAgreement
    ): void {
        DB::transaction(function () use ($agreement, $newAgreement) {
            // Обновляем статус аннулированного доп.соглашения
            $agreement->status = SupplementaryAgreementStatusEnum::SUPERSEDED;
            $agreement->save();

            $contract = $agreement->contract;

            // Если договор использует Event Sourcing, создаем событие аннулирования
            if ($contract->usesEventSourcing()) {
                // Находим активное событие, связанное с этим доп.соглашением
                $activeEvent = ContractStateEvent::where('contract_id', $contract->id)
                    ->where('triggered_by_type', SupplementaryAgreement::class)
                    ->where('triggered_by_id', $agreement->id)
                    ->whereDoesntHave('supersededByEvents')
                    ->first();

                if ($activeEvent) {
                    try {
                        $this->getStateEventService()->createSupersededEvent(
                            $contract,
                            $activeEvent,
                            $newAgreement,
                            ['reason' => 'Аннулировано доп. соглашением ' . $newAgreement->number]
                        );

                        // Обновляем материализованное представление
                        $this->getStateCalculatorService()->recalculateContractState($contract);
                    } catch (Exception $e) {
                        $this->logging->technical('agreement.supersede_event.failed', [
                            'agreement_id' => $agreement->id,
                            'contract_id' => $contract->id,
                            'error' => $e->getMessage(),
                            'user_id' => Auth::id(),
                        ], 'error');
                    }
                }
            }

            $this->logging->business('agreement.superseded', [
                'superseded_agreement_id' => $agreement->id,
                'new_agreement_id' => $newAgreement->id,
                'contract_id' => $contract->id,
                'user_id' => Auth::id(),
            ]);
        });
    }

    /**
     * Получить сервис для работы с событиями состояния договора (lazy loading)
     */
    protected function getStateEventService(): ContractStateEventService
    {
        if ($this->stateEventService === null) {
            $this->stateEventService = app(ContractStateEventService::class);
        }
        return $this->stateEventService;
    }

    /**
     * Получить сервис для расчета состояний договора (lazy loading)
     */
    protected function getStateCalculatorService(): ContractStateCalculatorService
    {
        if ($this->stateCalculatorService === null) {
            $this->stateCalculatorService = app(ContractStateCalculatorService::class);
        }
        return $this->stateCalculatorService;
    }
} 