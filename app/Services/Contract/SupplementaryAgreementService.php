<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\DTOs\SupplementaryAgreementDTO;
use App\Enums\Contract\ContractStateEventTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\ContractStateEvent;
use App\Models\SupplementaryAgreement;
use App\Repositories\Interfaces\SupplementaryAgreementRepositoryInterface;
use App\Services\Logging\LoggingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function trans_message;

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
        return DB::transaction(function () use ($id, $dto): bool {
            $agreement = SupplementaryAgreement::query()->whereKey($id)->lockForUpdate()->first();
            if (! $agreement instanceof SupplementaryAgreement) {
                return false;
            }

            $this->assertMutable($agreement);

            return $this->updateMutable($id, $dto, $agreement);
        });
    }

    private function updateMutable(
        int $id,
        SupplementaryAgreementDTO $dto,
        ?SupplementaryAgreement $agreement = null
    ): bool {
        $agreement ??= $this->getById($id);
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
        return DB::transaction(function () use ($id): bool {
            $agreement = SupplementaryAgreement::query()->whereKey($id)->lockForUpdate()->first();
            if (! $agreement instanceof SupplementaryAgreement) {
                return false;
            }

            $this->assertMutable($agreement);

            return $this->deleteMutable($id, $agreement);
        });
    }

    private function deleteMutable(int $id, ?SupplementaryAgreement $agreement = null): bool
    {
        $agreement ??= $this->getById($id);
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

    private function assertMutable(SupplementaryAgreement $agreement): void
    {
        if ($agreement->financial_applied_at !== null || $agreement->applied_at !== null) {
            throw new DomainException(trans_message('agreements.applied_is_immutable'));
        }
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

            $contractStatus = $contract->status instanceof ContractStatusEnum
                ? $contract->status->value
                : (string) $contract->status;
            if ($lockedAgreement->financial_applied_at === null
                && in_array($contractStatus, ['completed', 'terminated', 'archived'], true)) {
                throw new DomainException(trans_message('agreements.contract_terminal_change_forbidden'));
            }

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

            $changeAmount = BigDecimal::of((string) ($lockedAgreement->change_amount ?? 0))
                ->toScale(2, RoundingMode::HalfUp);
            $oldTotalAmount = BigDecimal::of((string) ($contract->total_amount ?? 0))
                ->toScale(2, RoundingMode::HalfUp);
            $newTotalAmount = $financialAlreadyApplied
                ? $oldTotalAmount
                : $oldTotalAmount->plus($changeAmount)->toScale(2, RoundingMode::HalfUp);

            if (! $financialAlreadyApplied && $newTotalAmount->isNegative()) {
                throw new DomainException(trans_message('agreements.contract_total_negative'));
            }

            if (! $financialAlreadyApplied && $changeAmount->isNegative()) {
                $commitmentFloor = $this->contractCommitmentFloor($contract);
                if ($newTotalAmount->isLessThan($commitmentFloor)) {
                    throw new DomainException(trans_message('agreements.contract_total_below_commitments'));
                }
            }

            if (! $financialAlreadyApplied && ! $changeAmount->isZero()) {
                if (! $contract->usesEventSourcing()) {
                    $this->getStateEventService()->createContractCreatedEvent($contract, null, $actorId);
                }

                $contract->total_amount = (string) $newTotalAmount;
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

            if (! $financialAlreadyApplied && ! $changeAmount->isZero() && ! empty($lockedAgreement->supersede_agreement_ids)) {
                $activeSpecification = $contract->specifications()->wherePivot('is_active', true)->first();
                $this->getStateEventService()->createAmendedEvent(
                    $contract,
                    $activeSpecification?->id,
                    (float) (string) $changeAmount,
                    $lockedAgreement,
                    $lockedAgreement->agreement_date ?? now(),
                    [
                        'agreement_number' => $lockedAgreement->number,
                        'superseded_agreement_ids' => $lockedAgreement->supersede_agreement_ids,
                    ],
                    $actorId
                );
            } elseif (! $financialAlreadyApplied && ! $changeAmount->isZero()) {
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
                $this->applyAdvanceChanges(
                    $contract,
                    $lockedAgreement,
                    $lockedAgreement->advance_changes,
                    $actorId
                );
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

            DB::afterCommit(function () use (
                $lockedAgreement,
                $contract,
                $actorId,
                $newTotalAmount,
                $oldTotalAmount
            ): void {
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
                    'total_amount_delta' => (string) $newTotalAmount->minus($oldTotalAmount),
                ]);
            });

            return $contract->refresh();
        });
    }

    private function contractCommitmentFloor(Contract $contract): BigDecimal
    {
        if (! Schema::hasTable('contract_performance_acts')
            || ! Schema::hasTable('payment_documents')
            || ! Schema::hasTable('payment_transactions')) {
            return BigDecimal::zero()->toScale(2);
        }

        $actIds = ContractPerformanceAct::query()
            ->where('contract_id', $contract->id)
            ->whereIn('status', [
                ContractPerformanceAct::STATUS_APPROVED,
                ContractPerformanceAct::STATUS_SIGNED,
            ])
            ->pluck('id');
        $acted = BigDecimal::of((string) ContractPerformanceAct::query()
            ->whereIn('id', $actIds)
            ->sum('amount'))
            ->toScale(2, RoundingMode::HalfUp);

        $documents = PaymentDocument::query()
            ->where('organization_id', $contract->organization_id)
            ->where('status', '<>', PaymentDocumentStatus::CANCELLED->value)
            ->where(function ($query) use ($contract, $actIds): void {
                $query->where(function ($contractQuery) use ($contract): void {
                    $contractQuery
                        ->where('invoiceable_type', Contract::class)
                        ->where('invoiceable_id', $contract->id);
                })->orWhere(function ($sourceQuery) use ($contract): void {
                    $sourceQuery
                        ->where('source_type', Contract::class)
                        ->where('source_id', $contract->id);
                })->when($actIds->isNotEmpty(), function ($actQuery) use ($actIds): void {
                    $actQuery->orWhere(function ($morphQuery) use ($actIds): void {
                        $morphQuery
                            ->where('invoiceable_type', ContractPerformanceAct::class)
                            ->whereIn('invoiceable_id', $actIds);
                    });
                });
            });
        $documentIds = (clone $documents)->pluck('id');
        $invoiced = BigDecimal::of((string) (clone $documents)->sum('amount'))
            ->toScale(2, RoundingMode::HalfUp);
        $netPaid = BigDecimal::of((string) PaymentTransaction::query()
            ->whereIn('payment_document_id', $documentIds)
            ->where('status', PaymentTransactionStatus::COMPLETED->value)
            ->sum('amount'))
            ->toScale(2, RoundingMode::HalfUp);

        return array_reduce(
            [$acted, $invoiced, $netPaid],
            static fn (BigDecimal $max, BigDecimal $amount): BigDecimal => $amount->isGreaterThan($max) ? $amount : $max,
            BigDecimal::zero()->toScale(2)
        );
    }

    public function applyChangesToContract(int $agreementId): bool
    {
        $agreement = $this->getById($agreementId);

        if (! $agreement instanceof SupplementaryAgreement) {
            throw new DomainException(trans_message('agreements.not_found'));
        }

        $this->applyOnce($agreement, (int) (Auth::id() ?? 0));

        return true;
    }

    private function applySubcontractChanges(Contract $contract, array $changes): void
    {
        if (isset($changes['amount'])) {
            // Валидация: сумма субподряда не может быть отрицательной
            if ($changes['amount'] < 0) {
                throw new DomainException(trans_message('agreements.subcontract_amount_negative'));
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
                throw new DomainException(trans_message('agreements.gp_coefficient_positive'));
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

    private function applyAdvanceChanges(
        Contract $contract,
        SupplementaryAgreement $agreement,
        array $changes,
        int $actorId
    ): void {
        foreach ($changes as $change) {
            if (! isset($change['payment_id']) || ! isset($change['new_amount'])) {
                continue;
            }

            $adjustedAmount = BigDecimal::of((string) $change['new_amount'])
                ->toScale(2, RoundingMode::HalfUp);
            if ($adjustedAmount->isNegative()) {
                throw new DomainException(trans_message('agreements.advance_amount_negative'));
            }

            $payment = PaymentDocument::query()
                ->whereKey($change['payment_id'])
                ->where('organization_id', $contract->organization_id)
                ->where('invoiceable_type', Contract::class)
                ->where('invoiceable_id', $contract->id)
                ->where(function ($query): void {
                    $query->where('invoice_type', 'advance')
                        ->orWhere('metadata->contract_payment_type', 'advance');
                })
                ->lockForUpdate()
                ->first();

            if (! $payment instanceof PaymentDocument) {
                throw new DomainException(trans_message('agreements.advance_payment_not_found'));
            }

            $previousAdjustment = DB::table('supplementary_agreement_advance_adjustments')
                ->where('contract_id', $contract->id)
                ->where('payment_document_id', $payment->id)
                ->latest('id')
                ->first();
            $previousAmount = BigDecimal::of((string) ($previousAdjustment->adjusted_amount ?? $payment->amount))
                ->toScale(2, RoundingMode::HalfUp);
            $delta = $adjustedAmount->minus($previousAmount)->toScale(2, RoundingMode::HalfUp);

            DB::table('supplementary_agreement_advance_adjustments')->insert([
                'organization_id' => $contract->organization_id,
                'contract_id' => $contract->id,
                'supplementary_agreement_id' => $agreement->id,
                'payment_document_id' => $payment->id,
                'previous_amount' => (string) $previousAmount,
                'adjusted_amount' => (string) $adjustedAmount,
                'amount_delta' => (string) $delta,
                'created_by_user_id' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $plannedAdvance = BigDecimal::of((string) ($contract->planned_advance_amount ?? 0))
                ->plus($delta)
                ->toScale(2, RoundingMode::HalfUp);
            if ($plannedAdvance->isNegative()) {
                throw new DomainException(trans_message('agreements.advance_total_negative'));
            }
            Contract::query()
                ->whereKey($contract->id)
                ->where('organization_id', $contract->organization_id)
                ->update(['planned_advance_amount' => (string) $plannedAdvance]);
            $contract->setAttribute('planned_advance_amount', (string) $plannedAdvance);
            $contract->syncOriginalAttribute('planned_advance_amount');

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
