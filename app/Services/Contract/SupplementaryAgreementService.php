<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\DTOs\SupplementaryAgreementDTO;
use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Models\Contract;
use App\Models\ContractStateEvent;
use App\Models\SupplementaryAgreement;
use App\Repositories\Interfaces\SupplementaryAgreementRepositoryInterface;
use App\Services\Logging\LoggingService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupplementaryAgreementService
{
    protected ?ContractStateEventService $stateEventService = null;

    protected ?ContractStateCalculatorService $stateCalculatorService = null;

    public function __construct(
        protected SupplementaryAgreementRepositoryInterface $repository,
        protected LoggingService $logging,
        protected ContractAuditedMutationService $contractMutations,
    ) {}

    public function create(SupplementaryAgreementDTO $dto): SupplementaryAgreement
    {
        return $this->repository->create($dto->toArray());
    }

    public function update(int $id, SupplementaryAgreementDTO $dto): bool
    {
        $agreement = $this->getById($id);
        if (! $agreement) {
            return false;
        }

        $contract = $agreement->contract;
        $oldChangeAmount = $agreement->change_amount;

        // Обновляем ДС
        $updated = $this->repository->update($id, $dto->toArray());

        if (! $updated) {
            return false;
        }

        // Если контракт использует Event Sourcing и изменилась сумма - пересчитываем
        if ($contract && $contract->usesEventSourcing()) {
            $newChangeAmount = $dto->change_amount;

            // Проверяем, изменилась ли сумма
            if ($oldChangeAmount != $newChangeAmount) {
                try {
                    // Пересчитываем состояние контракта
                    $this->getStateCalculatorService()->recalculateContractState($contract);
                    $contract->refresh();
                    $currentState = $this->getStateEventService()->getCurrentState($contract);
                    $calculatedAmount = $currentState['total_amount'];
                    $contract->total_amount = $calculatedAmount;
                    $this->contractMutations->saveDirty($contract, 'agreement_amount_recalculated', Auth::id(), [
                        'agreement_id' => $id,
                    ]);

                    // BUSINESS: Логирование изменения ДС
                    $this->logging->business('agreement.updated', [
                        'agreement_id' => $id,
                        'agreement_number' => $agreement->number,
                        'contract_id' => $contract->id,
                        'old_change_amount' => $oldChangeAmount,
                        'new_change_amount' => $newChangeAmount,
                        'new_contract_amount' => $calculatedAmount,
                        'user_id' => Auth::id(),
                    ]);
                } catch (Exception $e) {
                    // КРИТИЧЕСКАЯ ОШИБКА
                    \Illuminate\Support\Facades\Log::error('Failed to update contract state after agreement update', [
                        'agreement_id' => $id,
                        'contract_id' => $contract->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    throw new \RuntimeException(
                        "Не удалось пересчитать состояние контракта после обновления ДС: {$e->getMessage()}"
                    );
                }
            }
        }

        return true;
    }

    public function delete(int $id): bool
    {
        $agreement = $this->getById($id);
        if (! $agreement) {
            return false;
        }

        // Загружаем контракт для создания события
        $contract = $agreement->contract;

        // Если контракт использует Event Sourcing, создаем события аннулирования
        if ($contract && $contract->usesEventSourcing()) {
            try {
                // Находим все активные события, связанные с этим ДС
                $activeEvents = $this->getStateEventService()->getTimeline($contract);
                $agreementEvents = $activeEvents
                    ->filter(function ($event) use ($id) {
                        return $event->isActive()
                            && $event->triggered_by_type === SupplementaryAgreement::class
                            && $event->triggered_by_id === $id;
                    });

                if ($agreementEvents->isNotEmpty()) {
                    // Аннулируем все события, связанные с этим ДС
                    foreach ($agreementEvents as $eventToSupersede) {
                        $this->getStateEventService()->createSupersededEvent(
                            $contract,
                            $eventToSupersede,
                            null, // Удаление ДС - не связано с новым ДС
                            [
                                'reason' => 'Дополнительное соглашение удалено',
                                'agreement_number' => $agreement->number,
                                'agreement_id' => $id,
                                'deleted_by' => Auth::id(),
                            ]
                        );
                    }

                    // Пересчитываем состояние и обновляем сумму контракта
                    $this->getStateCalculatorService()->recalculateContractState($contract);
                    $contract->refresh();
                    $currentState = $this->getStateEventService()->getCurrentState($contract);
                    $calculatedAmount = $currentState['total_amount'];
                    $contract->total_amount = $calculatedAmount;
                    $this->contractMutations->saveDirty($contract, 'agreement_deleted_recalculated', Auth::id(), [
                        'agreement_id' => $id,
                    ]);

                    // BUSINESS: Логирование удаления ДС
                    $this->logging->business('agreement.deleted', [
                        'agreement_id' => $id,
                        'agreement_number' => $agreement->number,
                        'contract_id' => $contract->id,
                        'contract_number' => $contract->number,
                        'events_superseded' => $agreementEvents->count(),
                        'new_contract_amount' => $calculatedAmount,
                        'user_id' => Auth::id(),
                    ]);
                } else {
                    // Нет активных событий - просто логируем
                    $this->logging->business('agreement.deleted_no_events', [
                        'agreement_id' => $id,
                        'agreement_number' => $agreement->number,
                        'contract_id' => $contract->id,
                        'user_id' => Auth::id(),
                    ]);
                }
            } catch (Exception $e) {
                // КРИТИЧЕСКАЯ ОШИБКА - не удаляем ДС если не удалось обновить события
                \Illuminate\Support\Facades\Log::error('Failed to create deletion events for agreement', [
                    'agreement_id' => $id,
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                throw new \RuntimeException(
                    "Не удалось удалить события дополнительного соглашения: {$e->getMessage()}"
                );
            }
        }

        // Удаляем ДС
        return $this->repository->delete($id);
    }

    public function getById(int $id): ?SupplementaryAgreement
    {
        return $this->repository->find($id);
    }

    public function paginateByContract(
        int $contractId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'agreement_date',
        string $sortDirection = 'desc'
    ) {
        return $this->repository->paginateByContract($contractId, $perPage, $filters, $sortBy, $sortDirection);
    }

    public function paginateByProject(
        int $projectId,
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'agreement_date',
        string $sortDirection = 'desc'
    ) {
        return $this->repository->paginateByProject($projectId, $organizationId, $perPage, $filters, $sortBy, $sortDirection);
    }

    public function paginate(
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'agreement_date',
        string $sortDirection = 'desc'
    ) {
        return $this->repository->paginate($perPage, $filters, $sortBy, $sortDirection);
    }

    public function applyOnce(SupplementaryAgreement $agreement, int $actorId): Contract
    {
        return DB::transaction(function () use ($agreement, $actorId): Contract {
            $lockedAgreement = SupplementaryAgreement::query()
                ->whereKey($agreement->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAgreement->applied_at !== null) {
                return $lockedAgreement->contract()->firstOrFail();
            }

            $contract = Contract::query()
                ->whereKey($lockedAgreement->contract_id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingFinancialEvent = ContractStateEvent::query()
                ->where('contract_id', $contract->id)
                ->where('triggered_by_type', SupplementaryAgreement::class)
                ->where('triggered_by_id', $lockedAgreement->id)
                ->whereIn('event_type', [
                    ContractStateEventTypeEnum::AMENDED->value,
                    ContractStateEventTypeEnum::SUPPLEMENTARY_AGREEMENT_CREATED->value,
                ])
                ->oldest('created_at')
                ->oldest('id')
                ->first();

            $financialAlreadyApplied = $lockedAgreement->financial_applied_at !== null
                || $existingFinancialEvent instanceof ContractStateEvent;

            if ($lockedAgreement->financial_applied_at === null && $existingFinancialEvent instanceof ContractStateEvent) {
                $lockedAgreement->forceFill([
                    'financial_applied_at' => $existingFinancialEvent->created_at ?? now(),
                    'application_key' => "supplementary-agreement:{$lockedAgreement->id}",
                ])->save();
            }

            $changeAmount = (float) ($lockedAgreement->change_amount ?? 0);
            $oldTotalAmount = (float) ($contract->total_amount ?? 0);
            $newTotalAmount = $financialAlreadyApplied
                ? $oldTotalAmount
                : round($oldTotalAmount + $changeAmount, 2);

            if (! $financialAlreadyApplied && $newTotalAmount < 0) {
                throw new Exception('Невозможно применить изменения: новая сумма договора будет отрицательной.');
            }

            if (! $financialAlreadyApplied && abs($changeAmount) > 0.001) {
                if (! $contract->usesEventSourcing()) {
                    $this->getStateEventService()->createContractCreatedEvent($contract, null, $actorId);
                }

                $contract->total_amount = $newTotalAmount;
            }

            if (! $financialAlreadyApplied) {
                $this->contractMutations->saveDirty($contract, 'agreement_financial_terms_applied', $actorId, [
                    'agreement_id' => (int) $lockedAgreement->id,
                    'source_event_id' => "supplementary_agreement:{$lockedAgreement->id}:financial",
                ]);
            }

            if (! $financialAlreadyApplied && ! empty($lockedAgreement->supersede_agreement_ids)) {
                $this->getStateEventService()->supersedeAgreementsWithoutAmountChange(
                    $contract,
                    $lockedAgreement,
                    $lockedAgreement->supersede_agreement_ids
                );
            }

            if (! $financialAlreadyApplied && abs($changeAmount) > 0.001 && ! empty($lockedAgreement->supersede_agreement_ids)) {
                $activeSpecification = $contract->specifications()->wherePivot('is_active', true)->first();
                $this->getStateEventService()->createAmendedEvent(
                    $contract,
                    $activeSpecification?->id,
                    $changeAmount,
                    $lockedAgreement,
                    $lockedAgreement->agreement_date ?? now(),
                    [
                        'agreement_number' => $lockedAgreement->number,
                        'superseded_agreement_ids' => $lockedAgreement->supersede_agreement_ids,
                    ],
                    $actorId
                );
            } elseif (! $financialAlreadyApplied && abs($changeAmount) > 0.001) {
                $this->getStateEventService()->createSupplementaryAgreementEvent(
                    $contract,
                    $lockedAgreement,
                    $actorId
                );
            }

            if ($lockedAgreement->financial_applied_at === null) {
                $lockedAgreement->forceFill([
                    'financial_applied_at' => now(),
                    'application_key' => "supplementary-agreement:{$lockedAgreement->id}",
                ])->save();
            }

            if (is_array($lockedAgreement->subcontract_changes)) {
                $this->applySubcontractChanges($contract, $lockedAgreement->subcontract_changes);
            }

            if (is_array($lockedAgreement->gp_changes)) {
                $this->applyGpChanges($contract, $lockedAgreement->gp_changes);
            }

            if (is_array($lockedAgreement->advance_changes)) {
                $this->applyAdvanceChanges($contract, $lockedAgreement->advance_changes);
            }

            if ($contract->isDirty()) {
                $this->contractMutations->saveDirty($contract, 'agreement_legal_terms_applied', $actorId, [
                    'agreement_id' => (int) $lockedAgreement->id,
                    'source_event_id' => "supplementary_agreement:{$lockedAgreement->id}:legal",
                ]);
            }

            $lockedAgreement->forceFill([
                'applied_at' => now(),
                'applied_by_user_id' => $actorId,
                'application_key' => "supplementary-agreement:{$lockedAgreement->id}",
            ])->save();

            $this->logging->business('agreement.apply_changes.success', [
                'agreement_id' => $lockedAgreement->id,
                'contract_id' => $contract->id,
                'organization_id' => $contract->organization_id,
                'user_id' => $actorId,
            ]);

            $this->logging->audit('agreement.applied_to_contract', [
                'agreement_id' => $lockedAgreement->id,
                'agreement_number' => $lockedAgreement->number,
                'contract_id' => $contract->id,
                'contract_number' => $contract->number,
                'organization_id' => $contract->organization_id,
                'user_id' => $actorId,
                'total_amount_delta' => $newTotalAmount - $oldTotalAmount,
            ]);

            return $contract->refresh();
        });
    }

    public function applyChangesToContract(int $agreementId): bool
    {
        $agreement = $this->getById($agreementId);

        if (! $agreement instanceof SupplementaryAgreement) {
            throw new Exception('Дополнительное соглашение не найдено');
        }

        $this->applyOnce($agreement, (int) (Auth::id() ?? 0));

        return true;
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
            if (! isset($change['payment_id']) || ! isset($change['new_amount'])) {
                continue;
            }

            // Валидация: сумма платежа не может быть отрицательной
            if ($change['new_amount'] < 0) {
                throw new Exception(
                    'Невозможно применить изменения: сумма авансового платежа не может быть отрицательной '.
                    "(платеж ID: {$change['payment_id']}, сумма: {$change['new_amount']})"
                );
            }

            $payment = PaymentDocument::query()
                ->whereKey($change['payment_id'])
                ->where('invoiceable_type', Contract::class)
                ->where('invoiceable_id', $contract->id)
                ->where(function ($query): void {
                    $query->where('invoice_type', 'advance')
                        ->orWhere('metadata->contract_payment_type', 'advance');
                })
                ->first();

            if ($payment) {
                $oldAmount = $payment->paid_amount;
                $payment->update([
                    'amount' => $change['new_amount'],
                    'paid_amount' => $change['new_amount'],
                    'remaining_amount' => 0,
                ]);

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
     * Аннулировать доп.соглашение
     */
    public function supersedeAgreement(
        SupplementaryAgreement $agreement,
        SupplementaryAgreement $newAgreement
    ): void {
        DB::transaction(function () use ($agreement, $newAgreement) {
            // Статусы убраны, аннулирование теперь отслеживается только через Event Sourcing

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
                            ['reason' => 'Аннулировано доп. соглашением '.$newAgreement->number]
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
