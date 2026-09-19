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
            app(ContractBuilderMutationGuard::class)->assertLegacy($contract);

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
        return DB::transaction(function () use ($id, $dto): bool {
            $this->assertMutable($id);

            return $this->repository->update($id, $dto->toArray());
        });
    }

    public function applyRevisionPlan(Contract $contract, int $revisionId, string $date, array $rows): Specification
    {
        return DB::transaction(function () use ($contract, $revisionId, $date, $rows): Specification {
            $locked = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if (!DB::table('contract_builder_revisions as r')->join('contract_builder_instances as i', 'i.id', '=', 'r.instance_id')
                ->where('r.id', $revisionId)->where('i.contract_id', $locked->id)->exists()) {
                throw new \App\Exceptions\ContractBuilderException('contracts.activation_conflict', 409);
            }
            $existing = Specification::where('builder_revision_id', $revisionId)->first();
            if ($existing !== null) {
                if (!$locked->specifications()->whereKey($existing->id)->exists()) {
                    throw new \App\Exceptions\ContractBuilderException('contracts.activation_conflict', 409);
                }
                return $existing;
            }
            $total = \Brick\Math\BigDecimal::of('0');
            foreach ($rows as $row) {
                $total = $total->plus($row['amount']);
            }
            $specification = new Specification;
            $specification->forceFill([
                'builder_revision_id' => $revisionId, 'number' => 'REV-'.$contract->id.'-'.$revisionId,
                'spec_date' => $date, 'total_amount' => (string) $total, 'scope_items' => $rows, 'status' => 'approved',
            ])->save();
            $locked->specifications()->updateExistingPivot($locked->specifications()->pluck('specifications.id')->all(), ['is_active' => false]);
            $locked->specifications()->attach($specification->id, ['attached_at' => now(), 'is_active' => true]);

            return $specification;
        });
    }

    public function updateForOrganization(int $id, int $organizationId, array $data): ?Specification
    {
        return DB::transaction(function () use ($id, $organizationId, $data): ?Specification {
            if ($this->repository->findForOrganization($id, $organizationId) === null) {
                return null;
            }
            $this->assertMutable($id);

            return $this->repository->updateForOrganization($id, $organizationId, $data);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $this->assertMutable($id);

            return $this->repository->delete($id);
        });
    }

    public function deleteForOrganization(int $id, int $organizationId): bool
    {
        return DB::transaction(function () use ($id, $organizationId): bool {
            if ($this->repository->findForOrganization($id, $organizationId) === null) {
                return false;
            }
            $this->assertMutable($id);

            return $this->repository->deleteForOrganization($id, $organizationId);
        });
    }

    private function assertMutable(int $specificationId): void
    {
        $contracts = Contract::whereIn('id', DB::table('contract_specification')->where('specification_id', $specificationId)->select('contract_id'))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($contracts as $contract) {
            app(ContractBuilderMutationGuard::class)->assertLegacy($contract);
        }
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
