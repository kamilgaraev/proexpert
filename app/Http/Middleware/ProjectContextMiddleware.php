<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Services\Project\ProjectContextService;
use App\Models\Project;
use App\Models\Organization;
use App\Domain\Project\ValueObjects\ProjectContext;
use Illuminate\Support\Facades\Log;

class ProjectContextMiddleware
{
    protected ProjectContextService $projectContextService;

    public function __construct(ProjectContextService $projectContextService)
    {
        $this->projectContextService = $projectContextService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $projectId = $request->route('project');
        
        if (!$projectId) {
            return response()->json([
                'message' => 'Project ID is required in URL',
                'error' => 'MISSING_PROJECT_CONTEXT',
            ], 400);
        }

        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'message' => 'Authentication required',
                'error' => 'UNAUTHENTICATED',
            ], 401);
        }

        $organization = $user->organization;
        
        if (!$organization) {
            return response()->json([
                'message' => 'User is not associated with any organization',
                'error' => 'NO_ORGANIZATION',
            ], 403);
        }

        $project = Project::find($projectId);
        
        if (!$project) {
            return response()->json([
                'message' => 'Project not found',
                'error' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        // Проверка доступа организации к проекту
        if (!$this->projectContextService->canOrganizationAccessProject($project, $organization)) {
            Log::warning('Unauthorized project access attempt', [
                'project_id' => $projectId,
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ]);
            
            return response()->json([
                'message' => 'Your organization does not have access to this project',
                'error' => 'PROJECT_ACCESS_DENIED',
            ], 403);
        }

        // Получаем ProjectContext
        try {
            $projectContext = $this->projectContextService->getContext($project, $organization);
        } catch (\Exception $e) {
            Log::error('Failed to build project context', [
                'project_id' => $projectId,
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Failed to establish project context',
                'error' => 'CONTEXT_ERROR',
            ], 500);
        }

        // Инъектируем context в request
        $request->attributes->set('project_context', $projectContext);
        $request->attributes->set('project', $project);
        $request->attributes->set('current_organization', $organization);

        // Добавляем в request для удобного доступа
        $request->merge([
            '_project_context' => $projectContext,
            '_project' => $project,
        ]);

        return $next($request);
    }

    /**
     * Helper для получения ProjectContext из request
     */
    public static function getProjectContext(Request $request): ?ProjectContext
    {
        return $request->attributes->get('project_context');
    }

    /**
     * Helper для получения Project из request
     */
    public static function getProject(Request $request): ?Project
    {
        return $request->attributes->get('project');
    }

    /**
     * Helper для получения Organization из request
     */
    public static function getOrganization(Request $request): ?Organization
    {
        return $request->attributes->get('current_organization');
    }
}
