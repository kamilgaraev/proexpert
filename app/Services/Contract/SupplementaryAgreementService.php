<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\SupplementaryAgreementRepositoryInterface;
use App\DTOs\SupplementaryAgreementDTO;
use App\Models\SupplementaryAgreement;
use App\Models\Contract;
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

            // Применяем изменения авансов
            if ($agreement->advance_changes) {
                $this->applyAdvanceChanges($contract, $agreement->advance_changes);
            }

            // Применяем изменения субподряда
            if ($agreement->subcontract_changes) {
                $this->applySubcontractChanges($contract, $agreement->subcontract_changes);
            }

            DB::commit();

            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function applyAdvanceChanges(Contract $contract, array $changes): void
    {
        // Логика применения изменений авансов к контракту
        // Это может включать обновление planned_advance_amount или создание/изменение авансовых платежей
        // Реализация зависит от бизнес-логики
    }

    private function applySubcontractChanges(Contract $contract, array $changes): void
    {
        // Логика применения изменений субподряда к контракту
        // Это может включать обновление subcontract_amount
        // Реализация зависит от бизнес-логики
    }
} 