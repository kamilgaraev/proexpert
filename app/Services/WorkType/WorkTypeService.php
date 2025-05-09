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
        Log::info('[WorkTypeService@getWorkTypesPaginated] Called', [
            'request_query' => $request->query(),
            'perPage' => $perPage
        ]);

        try {
            $organizationId = $this->getCurrentOrgId($request);
            Log::info('[WorkTypeService@getWorkTypesPaginated] Organization ID determined', ['organization_id' => $organizationId]);
        } catch (\Throwable $e) {
            Log::error('[WorkTypeService@getWorkTypesPaginated] Error in getCurrentOrgId', ['error' => $e->getMessage()]);
            throw $e; // Перебрасываем исключение, чтобы увидеть его в основных логах
        }
        
        $filters = [
            'name' => $request->query('name'),
            'category' => $request->query('category'),
            'is_active' => $request->query('is_active'),
        ];
        
        Log::debug('[WorkTypeService@getWorkTypesPaginated] Raw filters from request', $filters);

        if (isset($filters['is_active'])) {
            $filters['is_active'] = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
             unset($filters['is_active']); 
        }
        $processedFilters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');
        
        Log::debug('[WorkTypeService@getWorkTypesPaginated] Processed filters', $processedFilters);

        $sortBy = $request->query('sort_by', 'name');
        $sortDirection = $request->query('sort_direction', 'asc');

        $allowedSortBy = ['name', 'category', 'created_at', 'updated_at'];
        if (!in_array(strtolower($sortBy), $allowedSortBy)) {
            Log::warning('[WorkTypeService@getWorkTypesPaginated] Invalid sort_by provided, defaulting to name.', ['requested_sort_by' => $sortBy]);
            $sortBy = 'name';
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'asc';
        }
        
        Log::info('[WorkTypeService@getWorkTypesPaginated] Calling repository with params', [
            'organizationId' => $organizationId,
            'perPage' => $perPage,
            'filters' => $processedFilters,
            'sortBy' => $sortBy,
            'sortDirection' => $sortDirection
        ]);

        try {
            $result = $this->workTypeRepository->getWorkTypesForOrganizationPaginated(
                $organizationId,
                $perPage,
                $processedFilters, // Используем обработанные фильтры
                $sortBy,
                $sortDirection
            );
            Log::info('[WorkTypeService@getWorkTypesPaginated] Repository call successful');
            return $result;
        } catch (\Throwable $e) {
            Log::error('[WorkTypeService@getWorkTypesPaginated] Error calling workTypeRepository', [
                'error' => $e->getMessage(), 
                'trace' => $e->getTraceAsString() // Добавляем полный трейс
            ]);
            throw $e; // Важно перебросить исключение, чтобы оно попало в стандартный обработчик Laravel
        }
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