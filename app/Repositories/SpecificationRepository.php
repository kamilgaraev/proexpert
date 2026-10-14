<?php

namespace App\Repositories;

use App\Models\Specification;
use App\Repositories\Interfaces\SpecificationRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SpecificationRepository extends BaseRepository implements SpecificationRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(Specification::class);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Specification::orderBy('spec_date', 'desc')->paginate($perPage);
    }

    public function paginateForOrganization(int $organizationId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->forOrganization($organizationId)
            ->orderBy('spec_date', 'desc')
            ->paginate($perPage);
    }

    public function findForOrganization(int $id, int $organizationId): ?Specification
    {
        return $this->forOrganization($organizationId)->find($id);
    }

    public function updateForOrganization(int $id, int $organizationId, array $data): ?Specification
    {
        $specification = $this->findForOrganization($id, $organizationId);
        if ($specification === null) {
            return null;
        }

        $specification->update($data);

        return $this->findForOrganization($id, $organizationId);
    }

    public function deleteForOrganization(int $id, int $organizationId): bool
    {
        return $this->findForOrganization($id, $organizationId)?->delete() ?? false;
    }

    public function paginateByProject(int $projectId, int $perPage = 15): LengthAwarePaginator
    {
        return Specification::query()
            ->whereHas('contracts', function ($query) use ($projectId): void {
                $query->where('contracts.project_id', $projectId)
                    ->orWhereHas('projects', fn ($projectQuery) => $projectQuery->where('projects.id', $projectId));
            })
            ->orderBy('spec_date', 'desc')
            ->paginate($perPage);
    }

    public function paginateByProjectForOrganization(
        int $projectId,
        int $organizationId,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return $this->forOrganization($organizationId)
            ->whereHas('contracts', function ($query) use ($projectId, $organizationId): void {
                $query->where('contracts.organization_id', $organizationId)
                    ->where(function ($contractQuery) use ($projectId): void {
                        $contractQuery->where('contracts.project_id', $projectId)
                            ->orWhereHas(
                                'projects',
                                fn ($projectQuery) => $projectQuery->where('projects.id', $projectId),
                            );
                    });
            })
            ->orderBy('spec_date', 'desc')
            ->paginate($perPage);
    }

    public function create(array $data): Specification
    {
        /** @var Specification $model */
        $model = parent::create($data);
        return $model;
    }

    public function createOwned(array $data, int $organizationId): Specification
    {
        $specification = new Specification;
        $specification->fill($data)->forceFill(['organization_id' => $organizationId])->save();

        return $specification;
    }

    public function find(int $id, array $columns = ['*'], array $relations = [], array $appends = []): ?Specification
    {
        /** @var Specification|null $model */
        $model = Specification::with($relations)->find($id, $columns);
        if ($model && $appends) $model->append($appends);
        return $model;
    }

    private function forOrganization(int $organizationId): Builder
    {
        return Specification::query()
            ->where(function ($query) use ($organizationId): void {
                $query->where('specifications.organization_id', $organizationId)
                    ->orWhere(function ($legacy) use ($organizationId): void {
                        $legacy->whereNull('specifications.organization_id')->whereHas(
                            'contracts',
                            fn ($contracts) => $contracts->where('contracts.organization_id', $organizationId),
                        );
                    });
            })
            ->whereDoesntHave(
                'contracts',
                fn ($query) => $query->withTrashed()->where('contracts.organization_id', '!=', $organizationId),
            )
            ->with([
                'contracts' => fn ($query) => $query
                    ->where('contracts.organization_id', $organizationId)
                    ->select(['contracts.id', 'contracts.number', 'contracts.organization_id']),
            ]);
    }
}
