<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\SpecificationRepositoryInterface;
use App\DTOs\SpecificationDTO;
use App\Models\Contract;
use App\Models\Specification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SpecificationService
{
    public function __construct(
        protected SpecificationRepositoryInterface $repository
    ) {}

    public function create(SpecificationDTO $dto): Specification
    {
        return $this->repository->create($dto->toArray());
    }

    public function createForOrganization(
        SpecificationDTO $dto,
        int $contractId,
        int $organizationId,
    ): ?Specification {
        return DB::transaction(function () use ($dto, $contractId, $organizationId): ?Specification {
            $contract = Contract::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->find($contractId);
            if ($contract === null) {
                return null;
            }

            $specification = $this->repository->create($dto->toArray());
            $contract->specifications()->updateExistingPivot(
                $contract->specifications()->pluck('specifications.id')->all(),
                ['is_active' => false],
            );
            $contract->specifications()->attach($specification->id, [
                'attached_at' => now(),
                'is_active' => true,
            ]);

            return $this->repository->findForOrganization($specification->id, $organizationId);
        });
    }

    public function update(int $id, SpecificationDTO $dto): bool
    {
        return $this->repository->update($id, $dto->toArray());
    }

    public function updateForOrganization(int $id, int $organizationId, array $data): ?Specification
    {
        return $this->repository->updateForOrganization($id, $organizationId, $data);
    }

    public function delete(int $id): bool
    {
        return $this->repository->delete($id);
    }

    public function deleteForOrganization(int $id, int $organizationId): bool
    {
        return $this->repository->deleteForOrganization($id, $organizationId);
    }

    public function getById(int $id): ?Specification
    {
        return $this->repository->find($id);
    }

    public function getByIdForOrganization(int $id, int $organizationId): ?Specification
    {
        return $this->repository->findForOrganization($id, $organizationId);
    }

    public function paginate(int $perPage = 15)
    {
        return $this->repository->paginate($perPage);
    }

    public function paginateForOrganization(int $organizationId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginateForOrganization($organizationId, $perPage);
    }

    public function paginateByProject(int $projectId, int $perPage = 15)
    {
        return $this->repository->paginateByProject($projectId, $perPage);
    }

    public function paginateByProjectForOrganization(
        int $projectId,
        int $organizationId,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $this->repository->paginateByProjectForOrganization($projectId, $organizationId, $perPage);
    }
}
