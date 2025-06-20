<?php

namespace App\Services\CompletedWork;

use App\Models\CompletedWork;
use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\DTOs\CompletedWork\CompletedWorkMaterialDTO;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Repositories\Interfaces\CompletedWorkRepositoryInterface;
use App\Exceptions\BusinessLogicException;
use App\Exceptions\ContractException;
use App\Models\Contract;
use App\Enums\Contract\ContractStatusEnum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompletedWorkService
{
    protected CompletedWorkRepositoryInterface $completedWorkRepository;

    public function __construct(CompletedWorkRepositoryInterface $completedWorkRepository)
    {
        $this->completedWorkRepository = $completedWorkRepository;
    }

    public function getAll(array $filters = [], int $perPage = 15, string $sortBy = 'completion_date', string $sortDirection = 'desc', array $relations = []): LengthAwarePaginator
    {
        // Добавляем сортировку по умолчанию для выполненных работ
        return $this->completedWorkRepository->getAllPaginated($filters, $perPage, $sortBy, $sortDirection, $relations);
    }

    public function getById(int $id, int $organizationId): CompletedWork
    {
        $completedWork = $this->completedWorkRepository->findById($id, $organizationId);
        if (!$completedWork) {
            throw new BusinessLogicException('Запись о выполненной работе не найдена.', 404);
        }
        return $completedWork;
    }

    public function create(CompletedWorkDTO $dto): CompletedWork
    {
        return DB::transaction(function () use ($dto) {
            // Валидация контракта перед созданием работы
            if ($dto->contract_id) {
                $this->validateContract($dto->contract_id, $dto->total_amount);
            }

            $data = $dto->toArray();
            unset($data['materials']);
            
            $createdModel = $this->completedWorkRepository->create($data);

            if (!$createdModel) {
                throw new BusinessLogicException('Не удалось создать запись о выполненной работе.', 500);
            }

            if ($dto->materials) {
                $this->syncMaterials($createdModel, $dto->materials);
            }

            // Обновление статуса контракта после создания работы
            if ($dto->contract_id && $dto->status === 'confirmed') {
                $this->updateContractStatus($dto->contract_id);
            }

            return $createdModel->fresh(['materials.measurementUnit']);
        });
    }

    public function update(int $id, CompletedWorkDTO $dto): CompletedWork
    {
        return DB::transaction(function () use ($id, $dto) {
            $existingWork = $this->getById($id, $dto->organization_id);

            // Валидация контракта при изменении суммы или статуса
            if ($dto->contract_id && ($dto->total_amount !== $existingWork->total_amount || $dto->status !== $existingWork->status)) {
                // Расчет разницы в сумме
                $amountDifference = ($dto->total_amount ?? 0) - ($existingWork->total_amount ?? 0);
                
                if ($amountDifference > 0) {
                    $this->validateContract($dto->contract_id, $amountDifference);
                }
            }

            $data = $dto->toArray();
            unset($data['materials']);

            $success = $this->completedWorkRepository->update($id, $data);
            if (!$success) {
                throw new BusinessLogicException('Не удалось обновить запись о выполненной работе.', 500);
            }

            $updatedWork = $existingWork->refresh();

            if ($dto->materials !== null) {
                $this->syncMaterials($updatedWork, $dto->materials);
            }

            // Обновление статуса контракта после изменения работы
            if ($dto->contract_id && $dto->status === 'confirmed') {
                $this->updateContractStatus($dto->contract_id);
            }

            return $updatedWork->fresh(['materials.measurementUnit']);
        });
    }

    public function delete(int $id, int $organizationId): bool
    {
        $this->getById($id, $organizationId);
        
        $success = $this->completedWorkRepository->delete($id);
        if (!$success) {
            throw new BusinessLogicException('Не удалось удалить запись о выполненной работе.', 500);
        }
        return true;
    }

    protected function syncMaterials(CompletedWork $completedWork, array $materials): void
    {
        $syncData = [];
        
        foreach ($materials as $materialData) {
            if ($materialData instanceof CompletedWorkMaterialDTO) {
                $syncData[$materialData->material_id] = $materialData->toArray();
            } elseif (is_array($materialData)) {
                $syncData[$materialData['material_id']] = $materialData;
            }
        }

        $completedWork->materials()->sync($syncData);
    }

    public function syncCompletedWorkMaterials(int $completedWorkId, array $materials, int $organizationId): CompletedWork
    {
        $completedWork = $this->getById($completedWorkId, $organizationId);
        
        $this->syncMaterials($completedWork, $materials);
        
        return $completedWork->fresh(['materials.measurementUnit']);
    }

    public function getWorkTypeMaterialDefaults(int $workTypeId, int $organizationId): Collection
    {
        return DB::table('work_type_materials as wtm')
            ->join('materials as m', 'wtm.material_id', '=', 'm.id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('wtm.work_type_id', $workTypeId)
            ->where('wtm.organization_id', $organizationId)
            ->whereNull('wtm.deleted_at')
            ->whereNull('m.deleted_at')
            ->select([
                'm.id as material_id',
                'm.name as material_name',
                'm.default_price',
                'wtm.default_quantity as quantity',
                'wtm.notes',
                'mu.short_name as measurement_unit'
            ])
            ->get();
    }

    /**
     * Валидация контракта перед добавлением работы
     */
    protected function validateContract(int $contractId, ?float $workAmount): void
    {
        $contract = Contract::find($contractId);
        
        if (!$contract) {
            throw new BusinessLogicException('Контракт не найден.', 404);
        }

        // Проверка статуса контракта
        if ($contract->status === ContractStatusEnum::COMPLETED) {
            throw ContractException::contractCompleted();
        }

        if ($contract->status === ContractStatusEnum::TERMINATED) {
            throw ContractException::contractTerminated();
        }

        // Проверка лимита суммы
        if ($workAmount && !$contract->canAddWork($workAmount)) {
            throw ContractException::amountExceedsLimit(
                $contract->completed_works_amount,
                $contract->total_amount,
                $workAmount
            );
        }
    }

    /**
     * Обновление статуса контракта после выполнения работ
     */
    protected function updateContractStatus(int $contractId): void
    {
        $contract = Contract::find($contractId);
        
        if (!$contract) {
            return;
        }

        // Автоматическое обновление статуса
        $statusChanged = $contract->updateStatusBasedOnCompletion();

        // Проверка приближения к лимиту (для уведомлений)
        if ($contract->isNearingLimit()) {
            // Здесь можно добавить логику отправки уведомлений
            // Например, dispatch event или отправить в очередь
            $this->notifyContractNearingLimit($contract);
        }
    }

    /**
     * Уведомление о приближении контракта к лимиту
     */
    protected function notifyContractNearingLimit(Contract $contract): void
    {
        // Логирование
        Log::warning("Контракт #{$contract->number} приближается к лимиту: {$contract->completion_percentage}%", [
            'contract_id' => $contract->id,
            'organization_id' => $contract->organization_id,
            'completed_amount' => $contract->completed_works_amount,
            'total_amount' => $contract->total_amount,
            'completion_percentage' => $contract->completion_percentage
        ]);

        // Отправляем real-time уведомление
        event(new \App\Events\ContractLimitWarning($contract));

        // Здесь можно добавить отправку email, push-уведомлений и т.д.
    }
} 