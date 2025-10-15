<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\SupplementaryAgreementRepositoryInterface;
use App\DTOs\SupplementaryAgreementDTO;
use App\Models\SupplementaryAgreement;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;

class SupplementaryAgreementService
{
    public function __construct(
        protected SupplementaryAgreementRepositoryInterface $repository,
        protected LoggingService $logging
    ) {}

    public function create(SupplementaryAgreementDTO $dto): SupplementaryAgreement
    {
        return $this->repository->create($dto->toArray());
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

            // 1. Применяем изменение суммы контракта (если указано)
            if ($agreement->change_amount !== null && $agreement->change_amount != 0) {
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
} 