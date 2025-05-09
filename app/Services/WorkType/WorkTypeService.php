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
        $user = $request->user(); 
        $organizationId = $request->attributes->get('current_organization_id');
        Log::debug('[WorkTypeService@getCurrentOrgId] Attempting to get org ID from request attributes.', ['attr_org_id' => $organizationId]);
        
        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
            Log::debug('[WorkTypeService@getCurrentOrgId] Org ID from user object.', ['user_org_id' => $organizationId]);
        }
        
        if (!$organizationId) {
            Log::error('[WorkTypeService@getCurrentOrgId] Failed to determine organization context.', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        Log::info('[WorkTypeService@getCurrentOrgId] Successfully determined org ID.', ['organization_id' => (int)$organizationId]);
        return (int)$organizationId;
    }

    public function getAllWorkTypesForCurrentOrg()
    {
        $organizationId = Auth::user()->current_organization_id;
        return $this->workTypeRepository->findBy('organization_id', $organizationId);
    }

    public function getActiveWorkTypesForCurrentOrg()
    {
        $organizationId = Auth::user()->current_organization_id;
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
        Log::info('[WorkTypeService@getWorkTypesPaginated] Service method CALLED.', [
            'query_params' => $request->query(),
            'perPage' => $perPage
        ]);

        try {
            $organizationId = $this->getCurrentOrgId($request);
            Log::info('[WorkTypeService@getWorkTypesPaginated] Org ID determined in main method.', ['organization_id' => $organizationId]);
            
            $filters = [
                'name' => $request->query('name'),
                'category' => $request->query('category'),
                'is_active' => $request->query('is_active'),
            ];
            Log::debug('[WorkTypeService@getWorkTypesPaginated] Raw filters.', $filters);

            if (array_key_exists('is_active', $filters) && !is_null($filters['is_active'])) {
                $filters['is_active'] = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            } else {
                 unset($filters['is_active']); 
            }
            $processedFilters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');
            Log::debug('[WorkTypeService@getWorkTypesPaginated] Processed filters.', $processedFilters);

            $sortBy = $request->query('sort_by', 'name');
            $sortDirection = $request->query('sort_direction', 'asc');
            Log::debug('[WorkTypeService@getWorkTypesPaginated] Sort params.', ['sortBy' => $sortBy, 'sortDirection' => $sortDirection]);

            $allowedSortBy = ['name', 'category', 'created_at', 'updated_at'];
            if (!in_array(strtolower($sortBy), $allowedSortBy)) {
                Log::warning('[WorkTypeService@getWorkTypesPaginated] Invalid sort_by, defaulting to name.', ['requested_sort_by' => $sortBy]);
                $sortBy = 'name';
            }
            if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
                $sortDirection = 'asc';
            }
            
            Log::info('[WorkTypeService@getWorkTypesPaginated] PRE-CALL to repository.', [
                'organizationId' => $organizationId,
                'perPage' => $perPage,
                'filters' => $processedFilters,
                'sortBy' => $sortBy,
                'sortDirection' => $sortDirection
            ]);

            $result = $this->workTypeRepository->getWorkTypesForOrganizationPaginated(
                $organizationId,
                $perPage,
                $processedFilters,
                $sortBy,
                $sortDirection
            );
            Log::info('[WorkTypeService@getWorkTypesPaginated] POST-CALL to repository. Result type: ' . gettype($result));
            
            if ($result instanceof LengthAwarePaginator) {
                 Log::info('[WorkTypeService@getWorkTypesPaginated] Repository returned LengthAwarePaginator.', ['total' => $result->total(), 'count' => $result->count()]);
            } else {
                 Log::warning('[WorkTypeService@getWorkTypesPaginated] Repository DID NOT return LengthAwarePaginator!');
            }
            return $result;

        } catch (BusinessLogicException $e) { // Для BusinessLogicException логируем и перебрасываем
            Log::error('[WorkTypeService@getWorkTypesPaginated] BusinessLogicException caught.', ['error' => $e->getMessage(), 'code' => $e->getCode()]);
            throw $e;
        } catch (\Throwable $e) { // Для всех остальных исключений — полное логгирование
            Log::critical('[WorkTypeService@getWorkTypesPaginated] CRITICAL ERROR caught in service method.', [
                'error_message' => $e->getMessage(), 
                'error_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            // Бросаем общее исключение, чтобы Laravel вернул 500, но детали уже залогированы
            throw new BusinessLogicException('Внутренняя ошибка сервера при получении видов работ.', 500, $e);
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