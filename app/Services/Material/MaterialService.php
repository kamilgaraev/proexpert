<?php

namespace App\Services\Material;

use App\Repositories\Interfaces\MaterialRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;

class MaterialService
{
    protected MaterialRepositoryInterface $materialRepository;

    public function __construct(MaterialRepositoryInterface $materialRepository)
    {
        $this->materialRepository = $materialRepository;
    }

    public function getAllMaterialsForCurrentOrg()
    {
        $organizationId = Auth::user()->getCurrentOrganizationId();
        return $this->materialRepository->getMaterialsForOrganization($organizationId);
    }

    public function getActiveMaterialsForCurrentOrg()
    {
        $organizationId = Auth::user()->getCurrentOrganizationId();
        return $this->materialRepository->getActiveMaterials($organizationId);
    }

    public function getAllActive(): Collection
    {
        $user = Auth::user();
        if (!$user || !$user->current_organization_id) {
            throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $organizationId = $user->current_organization_id;
        return $this->materialRepository->getActiveMaterials($organizationId);
    }

    public function createMaterial(array $data)
    {
        $user = Auth::user();
        if (!$user || !$user->current_organization_id) {
            throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $data['organization_id'] = $user->current_organization_id;
        // TODO: Добавить валидацию
        return $this->materialRepository->create($data);
    }

    public function findMaterialById(int $id)
    {
        // TODO: Проверка принадлежности организации
        return $this->materialRepository->find($id);
    }

    public function updateMaterial(int $id, array $data)
    {
        $user = Auth::user();
        if (!$user || !$user->current_organization_id) {
            throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $organizationId = $user->current_organization_id;
        // Доп. проверка, что запись принадлежит организации пользователя
        $material = $this->materialRepository->find($id);
        if (!$material || $material->organization_id !== $organizationId) {
            throw new BusinessLogicException('Материал не найден или не принадлежит вашей организации.', 404);
        }
        return $this->materialRepository->update($id, $data);
    }

    public function deleteMaterial(int $id)
    {
        // TODO: Проверка принадлежности организации
        // TODO: Проверка, используется ли материал где-либо
        return $this->materialRepository->delete($id);
    }
} 