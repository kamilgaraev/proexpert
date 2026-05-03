<?php

declare(strict_types=1);

namespace App\Services\WorkType;

use App\Exceptions\BusinessLogicException;
use App\Models\WorkType;
use App\Repositories\Interfaces\WorkTypeRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use function trans_message;

class WorkTypeService
{
    protected WorkTypeRepositoryInterface $workTypeRepository;

    public function __construct(WorkTypeRepositoryInterface $workTypeRepository)
    {
        $this->workTypeRepository = $workTypeRepository;
    }

    protected function getCurrentOrgId(Request $request): int
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
        }

        if (!$organizationId) {
            Log::error('[WorkTypeService@getCurrentOrgId] Failed to determine organization context.', [
                'user_id' => $user?->id,
                'request_attributes' => $request->attributes->all(),
            ]);

            throw new BusinessLogicException(trans_message('catalog.errors.organization_context_missing'), 500);
        }

        return (int) $organizationId;
    }

    public function getAllWorkTypesForCurrentOrg(Request $request)
    {
        $organizationId = $this->getCurrentOrgId($request);

        return $this->workTypeRepository->findBy('organization_id', $organizationId);
    }

    public function getActiveWorkTypesForCurrentOrg(Request $request): Collection
    {
        $organizationId = $this->getCurrentOrgId($request);

        return $this->workTypeRepository->getActiveWorkTypes($organizationId);
    }

    public function getAllActive(Request $request): Collection
    {
        $organizationId = $this->getCurrentOrgId($request);

        return $this->workTypeRepository->getActiveWorkTypes($organizationId);
    }

    public function getWorkTypesPaginated(Request $request, int $perPage = 15): LengthAwarePaginator
    {
        try {
            $organizationId = $this->getCurrentOrgId($request);

            $filters = [
                'name' => $request->query('name'),
                'category' => $request->query('category'),
                'is_active' => $request->query('is_active'),
            ];

            if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
                $filters['is_active'] = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            } else {
                unset($filters['is_active']);
            }

            $processedFilters = array_filter($filters, static fn ($value) => $value !== null && $value !== '');

            $sortBy = $request->query('sort_by', 'name');
            $sortDirection = $request->query('sort_direction', 'asc');

            $allowedSortBy = ['name', 'category', 'created_at', 'updated_at'];
            if (!in_array(strtolower((string) $sortBy), $allowedSortBy, true)) {
                Log::warning('[WorkTypeService@getWorkTypesPaginated] Invalid sort_by, defaulting to name.', [
                    'requested_sort_by' => $sortBy,
                ]);
                $sortBy = 'name';
            }

            if (!in_array(strtolower((string) $sortDirection), ['asc', 'desc'], true)) {
                $sortDirection = 'asc';
            }

            return $this->workTypeRepository->getWorkTypesForOrganizationPaginated(
                $organizationId,
                $perPage,
                $processedFilters,
                (string) $sortBy,
                (string) $sortDirection
            );
        } catch (BusinessLogicException $e) {
            Log::error('[WorkTypeService@getWorkTypesPaginated] BusinessLogicException caught.', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::critical('[WorkTypeService@getWorkTypesPaginated] Critical error.', [
                'error_message' => $e->getMessage(),
                'error_class' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw new BusinessLogicException(trans_message('catalog.errors.work_type_load_failed'), 500, $e);
        }
    }

    public function createWorkType(array $data, Request $request)
    {
        $organizationId = $this->getCurrentOrgId($request);
        $data['organization_id'] = $organizationId;

        return $this->workTypeRepository->create($data);
    }

    public function findWorkTypeById(int $id, Request $request): ?WorkType
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
            throw new BusinessLogicException(trans_message('catalog.errors.work_type_not_found'), 404);
        }

        unset($data['organization_id']);

        return $this->workTypeRepository->update($id, $data);
    }

    public function deleteWorkType(int $id, Request $request): bool
    {
        $workType = $this->findWorkTypeById($id, $request);

        if (!$workType) {
            throw new BusinessLogicException(trans_message('catalog.errors.work_type_not_found'), 404);
        }

        if ($this->hasWorkTypeUsage($workType)) {
            throw new BusinessLogicException(trans_message('catalog.errors.work_type_in_use'), 422);
        }

        return $this->workTypeRepository->delete($id);
    }

    protected function hasWorkTypeUsage(WorkType $workType): bool
    {
        return $workType->completedWorks()->exists()
            || $workType->materials()->exists();
    }
}
