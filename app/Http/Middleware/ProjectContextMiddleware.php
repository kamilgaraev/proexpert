<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Project\ValueObjects\ProjectContext;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Project\ProjectContextService;
use App\Services\Project\UserProjectAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class ProjectContextMiddleware
{
    protected ProjectContextService $projectContextService;
    protected UserProjectAccessService $userProjectAccessService;

    public function __construct(
        ProjectContextService $projectContextService,
        UserProjectAccessService $userProjectAccessService
    ) {
        $this->projectContextService = $projectContextService;
        $this->userProjectAccessService = $userProjectAccessService;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $projectParam = $request->route('project');

        if (!$projectParam) {
            return AdminResponse::error(trans_message('project.not_found'), Response::HTTP_BAD_REQUEST);
        }

        $user = $request->user();

        if (!$user) {
            return AdminResponse::error(trans_message('project.unauthorized'), Response::HTTP_UNAUTHORIZED);
        }

        $organization = $user->currentOrganization;

        if (!$organization) {
            return AdminResponse::error(trans_message('project.organization_not_found'), Response::HTTP_FORBIDDEN);
        }

        if ($projectParam instanceof Project) {
            $project = $projectParam;
        } else {
            $project = Project::find($projectParam);
        }

        if (!$project) {
            return AdminResponse::error(trans_message('project.not_found'), Response::HTTP_NOT_FOUND);
        }

        if (!$this->projectContextService->canOrganizationAccessProject($project, $organization)) {
            Log::warning('Unauthorized project access attempt', [
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('project.access_denied'), Response::HTTP_FORBIDDEN);
        }

        if (!$this->userProjectAccessService->canAccessProject($user, $project, $organization->id)) {
            Log::warning('Unauthorized user project scope attempt', [
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ]);

            return AdminResponse::error(trans_message('project.access_denied'), Response::HTTP_FORBIDDEN);
        }

        try {
            $projectContext = $this->projectContextService->getContext($project, $organization);
        } catch (\Exception $e) {
            Log::error('Failed to build project context', [
                'project_id' => $project->id,
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('project.context_not_available'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $request->attributes->set('project_context', $projectContext);
        $request->attributes->set('project', $project);
        $request->attributes->set('current_organization', $organization);

        return $next($request);
    }

    public static function getProjectContext(Request $request): ?ProjectContext
    {
        return $request->attributes->get('project_context');
    }

    public static function getProject(Request $request): ?Project
    {
        return $request->attributes->get('project');
    }

    public static function getOrganization(Request $request): ?Organization
    {
        return $request->attributes->get('current_organization');
    }
}
