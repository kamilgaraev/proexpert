<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\Domain\Project\ValueObjects\ProjectContext;
use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\EstimateItem;
use App\Models\Material;
use App\Models\Project;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Models\WorkType;
use App\Services\Project\UserProjectAccessService;

use function trans_message;

final class CompletedWorkScopeResolver
{
    public function __construct(
        private readonly UserProjectAccessService $projectAccess,
    ) {}

    public function assertCreate(
        CompletedWorkDTO $dto,
        ?User $actor,
        ?ProjectContext $context = null,
    ): void {
        $this->assertActor($actor);
        $project = $this->assertProject($dto->project_id, $dto->organization_id, $actor, $context);

        $this->assertReferences($dto, $project);
        $this->assertManage($context);
    }

    public function assertUpdate(
        CompletedWork $existingWork,
        CompletedWorkDTO $dto,
        ?User $actor,
        ?ProjectContext $context = null,
    ): void {
        $this->assertActor($actor);

        if ((int) $dto->organization_id !== (int) $existingWork->organization_id
            || (int) $dto->project_id !== (int) $existingWork->project_id) {
            throw $this->notFound();
        }

        $project = $this->assertProject($existingWork->project_id, $existingWork->organization_id, $actor, $context);
        $this->assertReferences($dto, $project);
        $this->assertManage($context);
    }

    public function assertDelete(
        CompletedWork $work,
        ?User $actor,
        ?ProjectContext $context = null,
    ): void {
        $this->assertActor($actor);
        $this->assertProject($work->project_id, $work->organization_id, $actor, $context);
        $this->assertManage($context);
    }

    /** @param list<CompletedWorkDTO> $dtos */
    public function assertBulk(
        array $dtos,
        ?User $actor,
        ?ProjectContext $context = null,
    ): void {
        foreach ($dtos as $dto) {
            $this->assertCreate($dto, $actor, $context);
        }
    }

    private function assertActor(?User $actor): void
    {
        if (! $actor) {
            throw new BusinessLogicException(trans_message('completed_work.forbidden'), 403);
        }
    }

    private function assertManage(?ProjectContext $context): void
    {
        if ($context && ! $context->roleConfig->canManageWorks) {
            throw new BusinessLogicException(trans_message('completed_work.forbidden'), 403);
        }
    }

    private function assertProject(int $projectId, int $organizationId, User $actor, ?ProjectContext $context): Project
    {
        $project = Project::query()->find($projectId);
        if (! $project || (int) $project->organization_id !== $organizationId) {
            throw $this->notFound();
        }

        if ($context && ((int) $context->projectId !== $projectId || (int) $context->organizationId !== (int) $actor->current_organization_id)) {
            throw $this->notFound();
        }

        $accessOrganizationId = $context?->organizationId ?? (int) $actor->current_organization_id;
        if (! $accessOrganizationId || ! $this->projectAccess->canAccessProject($actor, $project, $accessOrganizationId)) {
            throw $this->notFound();
        }

        return $project;
    }

    private function assertReferences(CompletedWorkDTO $dto, Project $project): void
    {
        $organizationId = (int) $project->organization_id;

        if ($dto->schedule_task_id !== null) {
            $task = ScheduleTask::query()->with('schedule')->find($dto->schedule_task_id);
            if (! $task || (int) $task->organization_id !== $organizationId
                || (int) $task->schedule?->project_id !== (int) $project->id) {
                throw $this->notFound();
            }
        }

        if ($dto->estimate_item_id !== null) {
            $item = EstimateItem::query()->with('estimate')->find($dto->estimate_item_id);
            if (! $item || (int) $item->estimate?->organization_id !== $organizationId
                || (int) $item->estimate?->project_id !== (int) $project->id) {
                throw $this->notFound();
            }
        }

        if ($dto->contract_id !== null) {
            $contract = Contract::query()->find($dto->contract_id);
            $projects = $contract?->getProjectIds() ?? [];
            if (! $contract || (int) $contract->organization_id !== $organizationId
                || ($projects !== [] && ! in_array((int) $project->id, array_map('intval', $projects), true))) {
                throw $this->notFound();
            }
        }

        $this->assertOrganizationReference($dto->contractor_id, Contractor::class, $organizationId);
        $this->assertOrganizationReference($dto->work_type_id, WorkType::class, $organizationId);

        if ($dto->user_id !== null && ! $this->activeOrganizationUserExists($dto->user_id, $organizationId)) {
            throw $this->notFound();
        }

        foreach ($dto->materials ?? [] as $material) {
            $materialId = is_object($material) ? $material->material_id : ($material['material_id'] ?? null);
            $this->assertOrganizationReference($materialId, Material::class, $organizationId);
        }
    }

    private function assertOrganizationReference(?int $id, string $model, int $organizationId): void
    {
        if ($id !== null && ! $model::query()->whereKey($id)->where('organization_id', $organizationId)->exists()) {
            throw $this->notFound();
        }
    }

    private function activeOrganizationUserExists(int $userId, int $organizationId): bool
    {
        return User::query()->whereKey($userId)->whereNull('deleted_at')->whereHas('organizations', function ($query) use ($organizationId): void {
            $query->whereKey($organizationId)->where('organization_user.is_active', true);
        })->exists();
    }

    private function notFound(): BusinessLogicException
    {
        return new BusinessLogicException(trans_message('completed_work.not_found'), 404);
    }
}
