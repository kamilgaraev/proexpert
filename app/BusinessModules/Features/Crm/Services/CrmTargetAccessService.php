<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Crm\Services;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class CrmTargetAccessService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $projects,
    ) {}

    public function assertOperation(int $organizationId, User $actor, string $permission, ?int $projectId = null): void
    {
        if ($organizationId <= 0 || (int) $actor->current_organization_id !== $organizationId
            || ! DB::table('organization_user')->useWritePdo()
                ->join('organizations', 'organizations.id', '=', 'organization_user.organization_id')
                ->join('users', 'users.id', '=', 'organization_user.user_id')
                ->where('organization_user.organization_id', $organizationId)->where('organization_user.user_id', $actor->id)
                ->where('organization_user.is_active', true)->where('organizations.is_active', true)->where('users.is_active', true)->exists()) {
            $this->notFound();
        }
        if ($this->authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId])) {
            return;
        }
        if ($projectId !== null) {
            AuthorizationContext::getProjectContext($projectId, $organizationId);
            if ($this->authorization->canCurrent($actor, $permission, [
                'organization_id' => $organizationId, 'project_id' => $projectId, 'strict_project_scope' => true,
            ])) {
                return;
            }
        }
        $this->notFound();
    }

    public function project(int $organizationId, User $actor, mixed $id, string $permission, bool $ownerOnly = true): Project
    {
        $query = $this->projects->queryAccessibleProjects($actor, $organizationId, true)->useWritePdo()->whereKey($this->id($id));
        if ($ownerOnly) {
            $query->where('projects.organization_id', $organizationId);
        }
        $project = $query->first();
        if (! $project instanceof Project) {
            $this->notFound();
        }
        $this->assertOperation($organizationId, $actor, $permission, (int) $project->id);

        return $project;
    }

    public function contract(int $organizationId, User $actor, mixed $id, string $permission): Contract
    {
        $contract = Contract::query()->useWritePdo()->forOrganization($organizationId)->find($this->id($id));
        if (! $contract instanceof Contract) {
            $this->notFound();
        }
        $relatedIds = $contract->is_multi_project
            ? DB::table('contract_project')->useWritePdo()->where('contract_id', $contract->id)->pluck('project_id')->all()
            : [];
        if ($relatedIds === [] && $contract->project_id !== null) {
            $relatedIds = [(int) $contract->project_id];
        }
        if ($relatedIds === []) {
            $this->assertOperation($organizationId, $actor, $permission);

            return $contract;
        }
        $projectIds = $this->projects->queryAccessibleProjects($actor, $organizationId, true)->useWritePdo()
            ->whereIn('projects.id', $relatedIds)->pluck('projects.id');
        foreach ($projectIds as $projectId) {
            try {
                $this->assertOperation($organizationId, $actor, $permission, (int) $projectId);

                return $contract;
            } catch (ModelNotFoundException) {
            }
        }
        $this->notFound();
    }

    public function assertInput(int $organizationId, User $actor, string $permission, array $data): void
    {
        $this->assertOperation($organizationId, $actor, $permission);
        if (isset($data['project_id']) && $data['project_id'] !== '') {
            $this->project($organizationId, $actor, $data['project_id'], $permission, false);
        }
        if (isset($data['contract_id']) && $data['contract_id'] !== '') {
            $this->contract($organizationId, $actor, $data['contract_id'], $permission);
        }
    }

    private function id(mixed $id): int
    {
        $validated = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            $this->notFound();
        }

        return $validated;
    }

    private function notFound(): never
    {
        throw (new ModelNotFoundException)->setModel(Project::class);
    }
}
