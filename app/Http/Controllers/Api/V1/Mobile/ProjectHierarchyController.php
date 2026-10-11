<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\Project;
use App\Models\Organization;
use App\Models\User;
use App\Services\ConstructionJournal\ConstructionJournalAccessService;
use App\Services\Mobile\MobileProjectAccessResolver;
use App\Services\Project\ProjectContextService;
use App\Services\Project\ProjectParticipantService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProjectHierarchyController extends Controller
{
    public function __construct(
        private readonly MobileProjectAccessResolver $projects,
        private readonly ConstructionJournalAccessService $access,
        private readonly ProjectParticipantService $participants,
        private readonly ProjectContextService $context,
        private readonly AuthorizationService $authorization,
    ) {}

    public function show(Request $request, int $project): JsonResponse
    {
        try {
            $model = $this->resolve($request->user(), $project);
            return MobileResponse::success([...$this->participants->getHierarchy($model), 'can_manage' => $this->canManage($request->user(), $model)]);
        } catch (AuthorizationException|DomainException) {
            return MobileResponse::error(trans_message('project.access_denied'), 403);
        }
    }

    public function update(Request $request, int $project): JsonResponse
    {
        try {
            $model = $this->resolve($request->user(), $project);
            if (! $this->canManage($request->user(), $model)) {
                throw new AuthorizationException;
            }
            $data = $request->validate(['root_organization_id' => 'required|integer|min:1', 'parents' => 'present|array', 'parents.*.organization_id' => 'required|integer|min:1|distinct', 'parents.*.parent_organization_id' => 'required|integer|min:1'], ['root_organization_id.required' => 'Выберите корневую компанию — верхнего участника проекта.']);
            return MobileResponse::success([...$this->participants->saveHierarchy($model, (int) $data['root_organization_id'], $data['parents'], $request->user()), 'can_manage' => true], trans_message('project.hierarchy_saved'));
        } catch (AuthorizationException) {
            return MobileResponse::error(trans_message('project.no_role_change_permission'), 403);
        } catch (BusinessLogicException|DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        }
    }

    private function resolve(User $actor, int $projectId): Project
    {
        $project = $this->projects->resolve($actor, (int) $actor->current_organization_id, $projectId, trans_message('project.access_denied'));
        if (! $this->access->canAccessProject($actor, $project)
            || ! $this->authorization->can($actor, 'projects.view', ['organization_id' => (int) $actor->current_organization_id, 'project_id' => $projectId, 'strict_project_scope' => true])) {
            throw new AuthorizationException;
        }
        return $project;
    }

    private function canManage(User $actor, Project $project): bool
    {
        return $this->context->getContext($project, Organization::query()->findOrFail($actor->current_organization_id))->roleConfig->canInviteParticipants
            && $this->authorization->can($actor, 'projects.organizations.manage', ['organization_id' => (int) $actor->current_organization_id, 'project_id' => $project->id, 'strict_project_scope' => true]);
    }
}
