<?php

namespace App\Services\WorkType;

use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class WorkTypeService
{
    protected WorkTypeRepositoryInterface $workTypeRepository;

    public function __construct(WorkTypeRepositoryInterface $workTypeRepository)
    {
        $this->workTypeRepository = $workTypeRepository;
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
            Log::error('Failed to determine organization context in WorkTypeService', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        return (int)$organizationId;
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

    /**
     * Получить пагинированный список видов работ.
     */
    public function getWorkTypesPaginated(Request $request, int $perPage = 15): LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $filters = [
            'name' => $request->query('name'),
            'category' => $request->query('category'),
            'is_active' => $request->query('is_active'),
        ];
        if (isset($filters['is_active'])) {
            $filters['is_active'] = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
             unset($filters['is_active']); 
        }
        $filters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');

        $sortBy = $request->query('sort_by', 'name');
        $sortDirection = $request->query('sort_direction', 'asc');

        $allowedSortBy = ['name', 'category', 'created_at', 'updated_at'];
        if (!in_array(strtolower($sortBy), $allowedSortBy)) {
            $sortBy = 'name';
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'asc';
        }

        return $this->workTypeRepository->getWorkTypesForOrganizationPaginated(
            $organizationId,
            $perPage,
            $filters,
            $sortBy,
            $sortDirection
        );
    }

    public function createWorkType(array $data, Request $request)
    {
        $organizationId = $this->getCurrentOrgId($request);
        $data['organization_id'] = $organizationId;
        return $this->workTypeRepository->create($data);
    }

    public function findWorkTypeById(int $id, Request $request): ?\App\Models\WorkType
    {
        $organizationId = $this->getCurrentOrgId($request);
        $workType = $this->workTypeRepository->find($id);
        if (!$workType || $workType->organization_id !== $organizationId) {
            return null;
        }
        return $workType;
    }

    public function updateWorkType(int $id, array $data, Request $request): bool
    {
        $workType = $this->findWorkTypeById($id, $request);
        if (!$workType) {
            throw new BusinessLogicException('Вид работы не найден или не принадлежит вашей организации.', 404);
        }
         unset($data['organization_id']);
        return $this->workTypeRepository->update($id, $data);
    }

    public function deleteWorkType(int $id, Request $request): bool
    {
        $workType = $this->findWorkTypeById($id, $request);
        if (!$workType) {
            throw new BusinessLogicException('Вид работы не найден или не принадлежит вашей организации.', 404);
        }
        // TODO: Проверка использования
        return $this->workTypeRepository->delete($id);
    }
} 