<?php

namespace App\Services\CompletedWork;

use App\Models\CompletedWork;
use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\DTOs\CompletedWork\CompletedWorkMaterialDTO;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Repositories\Interfaces\CompletedWorkRepositoryInterface;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
            $data = $dto->toArray();
            unset($data['materials']);
            
            $createdModel = $this->completedWorkRepository->create($data);

            if (!$createdModel) {
                throw new BusinessLogicException('Не удалось создать запись о выполненной работе.', 500);
            }

            if ($dto->materials) {
                $this->syncMaterials($createdModel, $dto->materials);
            }

            return $createdModel->fresh(['materials.measurementUnit']);
        });
    }

    public function update(int $id, CompletedWorkDTO $dto): CompletedWork
    {
        return DB::transaction(function () use ($id, $dto) {
            $existingWork = $this->getById($id, $dto->organization_id);

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
} 