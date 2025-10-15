<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\SupplementaryAgreementRepositoryInterface;
use App\DTOs\SupplementaryAgreementDTO;
use App\Models\SupplementaryAgreement;
use App\Models\Contract;
use App\Models\ContractPayment;
use App\Enums\Contract\GpCalculationTypeEnum;
use Illuminate\Support\Facades\DB;
use Exception;

class SupplementaryAgreementService
{
    public function __construct(
        protected SupplementaryAgreementRepositoryInterface $repository
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

            DB::beginTransaction();

            $contract = $agreement->contract;

            if ($agreement->subcontract_changes) {
                $this->applySubcontractChanges($contract, $agreement->subcontract_changes);
            }

            if ($agreement->gp_changes) {
                $this->applyGpChanges($contract, $agreement->gp_changes);
            }

            if ($agreement->advance_changes) {
                $this->applyAdvanceChanges($contract, $agreement->advance_changes);
            }

            DB::commit();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function applySubcontractChanges(Contract $contract, array $changes): void
    {
        if (isset($changes['amount'])) {
            $contract->subcontract_amount = $changes['amount'];
            $contract->save();
        }
    }

    private function applyGpChanges(Contract $contract, array $changes): void
    {
        if (isset($changes['percentage'])) {
            $contract->gp_percentage = $changes['percentage'];
            $contract->gp_calculation_type = GpCalculationTypeEnum::PERCENTAGE;
        }

        if (isset($changes['coefficient'])) {
            $contract->gp_coefficient = $changes['coefficient'];
            $contract->gp_calculation_type = GpCalculationTypeEnum::COEFFICIENT;
        }

        if (isset($changes['calculation_type'])) {
            $contract->gp_calculation_type = GpCalculationTypeEnum::from($changes['calculation_type']);
        }

        $contract->save();
    }

    private function applyAdvanceChanges(Contract $contract, array $changes): void
    {
        foreach ($changes as $change) {
            if (!isset($change['payment_id']) || !isset($change['new_amount'])) {
                continue;
            }

            $payment = ContractPayment::where('id', $change['payment_id'])
                ->where('contract_id', $contract->id)
                ->where('payment_type', 'advance')
                ->first();

            if ($payment) {
                $payment->amount = $change['new_amount'];
                $payment->save();
            }
        }
    }
} 