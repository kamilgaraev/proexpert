<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Project\ProjectService;
use App\Services\Project\ProjectContextService;
use App\Services\Organization\OrganizationProfileService;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Models\Organization;
use App\Enums\ProjectOrganizationRole;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ProjectOrganizationController extends Controller
{
    protected ProjectService $projectService;
    protected ProjectContextService $projectContextService;
    protected OrganizationProfileService $organizationProfileService;

    public function __construct(
        ProjectService $projectService,
        ProjectContextService $projectContextService,
        OrganizationProfileService $organizationProfileService
    ) {
        $this->projectService = $projectService;
        $this->projectContextService = $projectContextService;
        $this->organizationProfileService = $organizationProfileService;
    }

    /**
     * Получить список участников проекта
     * 
     * GET /api/v1/admin/projects/{project}/participants
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $project = ProjectContextMiddleware::getProject($request);
            $projectContext = ProjectContextMiddleware::getProjectContext($request);
            
            $participants = $this->projectContextService->getAllProjectParticipants($project);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'participants' => array_map(function ($participant) {
                        return [
                            'id' => $participant['organization']->id,
                            'name' => $participant['organization']->name,
                            'inn' => $participant['organization']->inn,
                            'role' => [
                                'value' => $participant['role']->value,
                                'label' => $participant['role']->label(),
                            ],
                            'is_active' => $participant['is_active'],
                            'is_owner' => $participant['is_owner'],
                            'added_at' => $participant['added_at'] ?? null,
                            'invited_at' => $participant['invited_at'] ?? null,
                            'accepted_at' => $participant['accepted_at'] ?? null,
                        ];
                    }, $participants),
                    'can_manage' => $projectContext->roleConfig->canInviteParticipants,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get project participants', [
                'project_id' => $request->route('project'),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve participants',
            ], 500);
        }
    }

    /**
     * Добавить участника в проект
     * 
     * POST /api/v1/admin/projects/{project}/participants
     */
    public function store(Request $request): JsonResponse
    {
        $projectContext = ProjectContextMiddleware::getProjectContext($request);
        
        if (!$projectContext->roleConfig->canInviteParticipants) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет прав для приглашения участников в проект',
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'organization_id' => 'required|integer|exists:organizations,id',
            'role' => 'required|string|in:' . implode(',', array_map(fn($r) => $r->value, ProjectOrganizationRole::cases())),
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $organizationId = $request->input('organization_id');
            $role = ProjectOrganizationRole::from($request->input('role'));
            
            $this->projectService->addOrganizationToProject(
                (int) $request->route('project'),
                $organizationId,
                $role,
                $request
            );

            return response()->json([
                'success' => true,
                'message' => 'Участник успешно добавлен в проект',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to add participant to project', [
                'project_id' => $request->route('project'),
                'organization_id' => $request->input('organization_id'),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Получить информацию об участнике
     * 
     * GET /api/v1/admin/projects/{project}/participants/{organization}
     */
    public function show(Request $request, int $project, int $organization): JsonResponse
    {
        try {
            $projectModel = ProjectContextMiddleware::getProject($request);
            $org = Organization::findOrFail($organization);
            
            $role = $this->projectContextService->getOrganizationRole($projectModel, $org);
            
            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не является участником проекта',
                ], 404);
            }
            
            $profile = $this->organizationProfileService->getProfile($org);
            $pivot = $projectModel->getOrganizationPivot($organization);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'organization' => [
                        'id' => $org->id,
                        'name' => $org->name,
                        'inn' => $org->inn,
                        'address' => $org->address,
                    ],
                    'role' => [
                        'value' => $role->value,
                        'label' => $role->label(),
                    ],
                    'profile' => $profile->toArray(),
                    'pivot' => $pivot ? [
                        'is_active' => $pivot->is_active,
                        'added_by_user_id' => $pivot->added_by_user_id,
                        'invited_at' => $pivot->invited_at,
                        'accepted_at' => $pivot->accepted_at,
                        'metadata' => $pivot->metadata,
                    ] : null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get participant details', [
                'project_id' => $project,
                'organization_id' => $organization,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve participant details',
            ], 500);
        }
    }

    /**
     * Обновить роль участника
     * 
     * PATCH /api/v1/admin/projects/{project}/participants/{organization}/role
     */
    public function updateRole(Request $request, int $project, int $organization): JsonResponse
    {
        $projectContext = ProjectContextMiddleware::getProjectContext($request);
        
        if (!$projectContext->roleConfig->canInviteParticipants) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет прав для изменения ролей участников',
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'role' => 'required|string|in:' . implode(',', array_map(fn($r) => $r->value, ProjectOrganizationRole::cases())),
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $role = ProjectOrganizationRole::from($request->input('role'));
            
            $this->projectService->updateOrganizationRole(
                $project,
                $organization,
                $role,
                $request
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Роль участника успешно обновлена',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update participant role', [
                'project_id' => $project,
                'organization_id' => $organization,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Удалить участника из проекта
     * 
     * DELETE /api/v1/admin/projects/{project}/participants/{organization}
     */
    public function destroy(Request $request, int $project, int $organization): JsonResponse
    {
        $projectContext = ProjectContextMiddleware::getProjectContext($request);
        
        if (!$projectContext->roleConfig->canInviteParticipants) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет прав для удаления участников из проекта',
            ], 403);
        }
        
        try {
            $this->projectService->removeOrganizationFromProject(
                $project,
                $organization,
                $request
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Участник успешно удален из проекта',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove participant from project', [
                'project_id' => $project,
                'organization_id' => $organization,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Активировать участника
     * 
     * POST /api/v1/admin/projects/{project}/participants/{organization}/activate
     */
    public function activate(Request $request, int $project, int $organization): JsonResponse
    {
        $projectContext = ProjectContextMiddleware::getProjectContext($request);
        
        if (!$projectContext->roleConfig->canInviteParticipants) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет прав для управления статусом участников',
            ], 403);
        }
        
        try {
            $projectModel = ProjectContextMiddleware::getProject($request);
            $projectModel->organizations()->updateExistingPivot($organization, [
                'is_active' => true,
                'updated_at' => now(),
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Участник активирован',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to activate participant', [
                'project_id' => $project,
                'organization_id' => $organization,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate participant',
            ], 500);
        }
    }

    /**
     * Деактивировать участника
     * 
     * POST /api/v1/admin/projects/{project}/participants/{organization}/deactivate
     */
    public function deactivate(Request $request, int $project, int $organization): JsonResponse
    {
        $projectContext = ProjectContextMiddleware::getProjectContext($request);
        
        if (!$projectContext->roleConfig->canInviteParticipants) {
            return response()->json([
                'success' => false,
                'message' => 'У вас нет прав для управления статусом участников',
            ], 403);
        }
        
        try {
            $projectModel = ProjectContextMiddleware::getProject($request);
            $projectModel->organizations()->updateExistingPivot($organization, [
                'is_active' => false,
                'updated_at' => now(),
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Участник деактивирован',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to deactivate participant', [
                'project_id' => $project,
                'organization_id' => $organization,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate participant',
            ], 500);
        }
    }
}
