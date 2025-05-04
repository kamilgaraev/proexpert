<?php

namespace App\Services\Material;

use App\Repositories\Interfaces\MaterialRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class MaterialService
{
    protected MaterialRepositoryInterface $materialRepository;
    protected ?MeasurementUnitRepositoryInterface $measurementUnitRepository; // Может быть null

    public function __construct(
        MaterialRepositoryInterface $materialRepository,
        ?MeasurementUnitRepositoryInterface $measurementUnitRepository = null // Делаем опциональным
    ) {
        $this->materialRepository = $materialRepository;
        $this->measurementUnitRepository = $measurementUnitRepository;
    }

    /**
     * Helper для получения ID организации из запроса.
     */
    protected function getCurrentOrgId(Request $request): int
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user(); 
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
        }
        if (!$organizationId) {
            Log::error('Failed to determine organization context in MaterialService', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        return (int)$organizationId;
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

    public function createMaterial(array $data, Request $request): \App\Models\Material
    {
        $organizationId = $this->getCurrentOrgId($request);
        $data['organization_id'] = $organizationId;

        // Проверяем measurement_unit_id, если репозиторий доступен
        if (isset($data['measurement_unit_id']) && $this->measurementUnitRepository) {
            if (!$this->measurementUnitRepository->find($data['measurement_unit_id'])) {
                throw new BusinessLogicException('Указанная единица измерения не найдена', 400);
            }
        }
        // TODO: Возможно, добавить проверку доступности ед.изм для организации

        return $this->materialRepository->create($data);
    }

    public function findMaterialById(int $id, Request $request): ?\App\Models\Material
    {
        $organizationId = $this->getCurrentOrgId($request);
        $material = $this->materialRepository->find($id);
        if (!$material || $material->organization_id !== $organizationId) {
            return null;
        }
        return $material;
    }

    public function updateMaterial(int $id, array $data, Request $request): bool
    {
        // Проверка принадлежности к организации через findMaterialById
        $material = $this->findMaterialById($id, $request);
        if (!$material) {
             throw new BusinessLogicException('Материал не найден или не принадлежит вашей организации.', 404);
        }
        
        // Проверяем measurement_unit_id, если он передан и репозиторий доступен
        if (isset($data['measurement_unit_id']) && $this->measurementUnitRepository) {
            if (!$this->measurementUnitRepository->find($data['measurement_unit_id'])) {
                throw new BusinessLogicException('Указанная единица измерения не найдена', 400);
            }
        }

        // Убедимся, что organization_id не меняется
        unset($data['organization_id']);
        
        return $this->materialRepository->update($id, $data);
    }

    public function deleteMaterial(int $id, Request $request): bool
    {
        // Проверка принадлежности к организации через findMaterialById
         $material = $this->findMaterialById($id, $request);
         if (!$material) {
             throw new BusinessLogicException('Материал не найден или не принадлежит вашей организации.', 404);
         }
        // TODO: Проверка, используется ли материал где-либо
        return $this->materialRepository->delete($id);
    }

    /**
     * Получить пагинированный список материалов для текущей организации.
     */
    public function getMaterialsPaginated(Request $request, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $filters = [
            'name' => $request->query('name'),
            'category' => $request->query('category'),
            'is_active' => $request->query('is_active'), // Принимаем 'true', 'false', '1', '0' или null
        ];
        if (isset($filters['is_active'])) {
            $filters['is_active'] = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
             unset($filters['is_active']); 
        }
        $filters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');

        $sortBy = $request->query('sort_by', 'name');
        $sortDirection = $request->query('sort_direction', 'asc');

        // TODO: Валидация sortBy и sortDirection
        $allowedSortBy = ['name', 'category', 'created_at', 'updated_at'];
        if (!in_array(strtolower($sortBy), $allowedSortBy)) {
            $sortBy = 'name';
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'asc';
        }

        return $this->materialRepository->getMaterialsForOrganizationPaginated(
            $organizationId,
            $perPage,
            $filters,
            $sortBy,
            $sortDirection
        );
    }
} 