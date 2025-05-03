<?php

namespace App\Services\WorkType;

use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;

class WorkTypeService
{
    protected WorkTypeRepositoryInterface $workTypeRepository;

    public function __construct(WorkTypeRepositoryInterface $workTypeRepository)
    {
        $this->workTypeRepository = $workTypeRepository;
    }

    public function getAllWorkTypesForCurrentOrg()
    {
        $organizationId = Auth::user()->getCurrentOrganizationId();
        // В базовом репозитории нет метода all для конкретной организации, 
        // но можно использовать findBy
        return $this->workTypeRepository->findBy('organization_id', $organizationId);
    }

    public function getActiveWorkTypesForCurrentOrg()
    {
        $organizationId = Auth::user()->getCurrentOrganizationId();
        return $this->workTypeRepository->getActiveWorkTypes($organizationId);
    }

    public function getAllActive(): Collection
    {
        $user = Auth::user();
        if (!$user || !$user->current_organization_id) {
            throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $organizationId = $user->current_organization_id;
        return $this->workTypeRepository->getActiveWorkTypes($organizationId);
    }

    public function create(array $data): WorkType
    {
        $user = Auth::user();
        if (!$user || !$user->current_organization_id) {
            throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $data['organization_id'] = $user->current_organization_id;
        return $this->workTypeRepository->create($data);
    }

    public function findWorkTypeById(int $id)
    {
        // TODO: Проверка принадлежности организации
        return $this->workTypeRepository->find($id);
    }

    public function update(int $id, array $data): bool
    {
        $user = Auth::user();
        if (!$user || !$user->current_organization_id) {
            throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $organizationId = $user->current_organization_id;
        $workType = $this->workTypeRepository->find($id);
        if (!$workType || $workType->organization_id !== $organizationId) {
            throw new BusinessLogicException('Вид работы не найден или не принадлежит вашей организации.', 404);
        }
        return $this->workTypeRepository->update($id, $data);
    }

    public function deleteWorkType(int $id)
    {
        // TODO: Проверка принадлежности организации
        // TODO: Проверка использования
        return $this->workTypeRepository->delete($id);
    }
} 