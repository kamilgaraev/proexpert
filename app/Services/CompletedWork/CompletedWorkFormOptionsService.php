<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Database\Eloquent\Builder;

final class CompletedWorkFormOptionsService
{
    public function __construct(private readonly CompletedWorkScopeResolver $scopeResolver) {}

    public function forProject(Project $project, User $actor, ProjectContext $context): array
    {
        $this->scopeResolver->assertFormOptions($project, $actor, $context);

        $ownerOrganizationId = (int) $project->organization_id;
        $participantOrganizationId = (int) $context->organizationId;
        $isOwner = $ownerOrganizationId === $participantOrganizationId;

        $workTypes = WorkType::query()
            ->where('organization_id', $ownerOrganizationId)
            ->where('is_active', true)
            ->with('measurementUnit:id,short_name')
            ->orderBy('name')
            ->get(['id', 'name', 'measurement_unit_id'])
            ->map(static fn (WorkType $workType): array => [
                'id' => $workType->id,
                'name' => $workType->name,
                'measurement_unit' => $workType->measurementUnit
                    ? ['code' => $workType->measurementUnit->short_name]
                    : null,
            ])
            ->all();

        $users = User::query()
            ->whereHas('organizations', static function (Builder $query) use ($ownerOrganizationId): void {
                $query->whereKey($ownerOrganizationId)->where('organization_user.is_active', true);
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();

        $contractors = Contractor::query()
            ->where('organization_id', $ownerOrganizationId)
            ->when(! $isOwner, static function (Builder $query) use ($participantOrganizationId): void {
                $query->where('source_organization_id', $participantOrganizationId);
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Contractor $contractor): array => ['id' => $contractor->id, 'name' => $contractor->name])
            ->all();

        $contracts = Contract::query()
            ->where('organization_id', $ownerOrganizationId)
            ->where(static function (Builder $query) use ($project): void {
                $query->where('project_id', $project->id)
                    ->orWhereHas('projects', static function (Builder $projects) use ($project): void {
                        $projects->whereKey($project->id);
                    });
            })
            ->when(! $isOwner, static function (Builder $query) use ($participantOrganizationId): void {
                $query->where(static function (Builder $related) use ($participantOrganizationId): void {
                    $related->whereHas('contractor', static function (Builder $contractors) use ($participantOrganizationId): void {
                        $contractors->where('source_organization_id', $participantOrganizationId);
                    })->orWhereHas('parties', static function (Builder $parties) use ($participantOrganizationId): void {
                        $parties->where('linked_organization_id', $participantOrganizationId);
                    });
                });
            })
            ->whereNotIn('status', ['completed', 'terminated'])
            ->orderBy('number')
            ->get(['id', 'number'])
            ->map(static fn (Contract $contract): array => ['id' => $contract->id, 'number' => $contract->number])
            ->all();

        return [
            'work_types' => $workTypes,
            'users' => $users,
            'contractors' => $contractors,
            'contracts' => $contracts,
        ];
    }
}
