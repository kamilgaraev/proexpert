<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Project\ProjectService;
use App\Services\Project\ProjectContextService;
use App\Services\Organization\OrganizationProfileService;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use App\Models\Project;
use App\Enums\ProjectOrganizationRole;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Response;

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
     * Helper to get Project model from request (route params or attributes)
     */
    private function getProjectModel(Request $request): Project
    {
        // 1. Try from attributes (set by middleware)
        $project = $request->attributes->get('project');
        if ($project instanceof Project) {
            return $project;
        }

        // 2. Try from route 'project' param
        $routeProject = $request->route('project');
        if ($routeProject instanceof Project) {
            return $routeProject;
        }

        // 3. Try to find ID in 'project' or 'id' params
        $projectId = null;
        if (is_numeric($routeProject)) {
            $projectId = (int) $routeProject;
        } elseif ($request->route('id')) {
            $projectId = (int) $request->route('id');
        }

        if ($projectId) {
            return Project::findOrFail($projectId);
        }

        throw new \RuntimeException('Project ID not found in request');
    }

    /**
     * Получить проект и проверить доступ к нему
     */
    private function getProjectWithAccess(Request $request): array
    {
        $project = $this->getProjectModel($request);
        $user = $request->user();
        
        if (!$user) {
            throw new \RuntimeException('Unauthorized', 401);
        }

        $currentOrg = Organization::find($user->current_organization_id);
        if (!$currentOrg) {
            throw new \RuntimeException('Organization not found', 404);
        }

        if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
            throw new \RuntimeException('Access denied', 403);
        }

        $projectContext = $this->projectContextService->getContext($project, $currentOrg);

        return [$project, $currentOrg, $projectContext];
    }

    /**
     * Получить список участников проекта
     * 
     * GET /api/v1/admin/projects/{project}/organizations
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $project = $this->getProjectModel($request);
            
            $user = $request->user();
            if (!$user) {
                return AdminResponse::error(trans_message('project.unauthorized'), 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            if (!$currentOrg) {
                return AdminResponse::error(trans_message('project.organization_not_found'), 404);
            }

            // Проверяем доступ к проекту
            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return AdminResponse::error(trans_message('project.access_denied'), 403);
            }

            $projectContext = $this->projectContextService->getContext($project, $currentOrg);
            $participants = $this->projectContextService->getAllProjectParticipants($project);
            
            return AdminResponse::success([
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
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get project participants', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return AdminResponse::error(trans_message('project.participants_error'), 500);
        }
    }

    /**
     * Добавить участника в проект
     * 
     * POST /api/v1/admin/projects/{project}/organizations
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $project = $this->getProjectModel($request);
            $projectId = $project->id;
            
            $user = $request->user();
            if (!$user) {
                return AdminResponse::error(trans_message('project.unauthorized'), 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            if (!$currentOrg) {
                return AdminResponse::error(trans_message('project.organization_not_found'), 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return AdminResponse::error(trans_message('project.access_denied'), 403);
            }

            $projectContext = $this->projectContextService->getContext($project, $currentOrg);
            
            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_invite_permission'), 403);
            }
            
            $validator = Validator::make($request->all(), [
                'organization_id' => 'required|integer|exists:organizations,id',
                'role' => 'required|string|in:' . implode(',', array_map(fn($r) => $r->value, ProjectOrganizationRole::cases())),
            ]);
            
            if ($validator->fails()) {
                return AdminResponse::error(trans_message('project.validation_failed'), 422, $validator->errors());
            }
            
            $organizationId = $request->input('organization_id');
            $role = ProjectOrganizationRole::from($request->input('role'));
            
            $this->projectService->addOrganizationToProject(
                $projectId,
                $organizationId,
                $role,
                $request
            );

            return AdminResponse::success(null, trans_message('project.participant_added'));
        } catch (\Exception $e) {
            Log::error('Failed to add participant to project', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $request->input('organization_id'),
                'error' => $e->getMessage(),
            ]);
            
            return AdminResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Получить информацию об участнике
     * 
     * GET /api/v1/admin/projects/{id}/organizations/{organization}
     */
    public function show(Request $request, int $organization): JsonResponse
    {
        try {
            $projectModel = $this->getProjectModel($request);
            $id = $projectModel->id;
            $user = $request->user();
            
            if (!$user) {
                return AdminResponse::error(trans_message('project.unauthorized'), 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            if (!$currentOrg) {
                return AdminResponse::error(trans_message('project.organization_not_found'), 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($projectModel, $currentOrg)) {
                return AdminResponse::error(trans_message('project.access_denied'), 403);
            }

            $org = Organization::findOrFail($organization);
            
            $role = $this->projectContextService->getOrganizationRole($projectModel, $org);
            
            if (!$role) {
                return AdminResponse::error(trans_message('project.participant_not_found'), 404);
            }
            
            $profile = $this->organizationProfileService->getProfile($org);
            $pivot = $projectModel->getOrganizationPivot($organization);
            
            return AdminResponse::success([
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
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get participant details', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $organization,
                'error' => $e->getMessage(),
            ]);
            
            return AdminResponse::error(trans_message('project.participant_details_error'), 500);
        }
    }

    /**
     * Обновить роль участника
     * 
     * PATCH /api/v1/admin/projects/{id}/organizations/{organization}/role
     */
    public function updateRole(Request $request, int $organization): JsonResponse
    {
        try {
            [$project, $currentOrg, $projectContext] = $this->getProjectWithAccess($request);
            $id = $project->id;
            
            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_role_change_permission'), 403);
            }
            
            $validator = Validator::make($request->all(), [
                'role' => 'required|string|in:' . implode(',', array_map(fn($r) => $r->value, ProjectOrganizationRole::cases())),
            ]);
            
            if ($validator->fails()) {
                return AdminResponse::error(trans_message('project.validation_failed'), 422, $validator->errors());
            }
            
            $role = ProjectOrganizationRole::from($request->input('role'));
            
            $this->projectService->updateOrganizationRole(
                $id,
                $organization,
                $role,
                $request
            );
            
            return AdminResponse::success(null, trans_message('project.participant_role_updated'));
        } catch (\RuntimeException $e) {
            return AdminResponse::error($e->getMessage(), (int)$e->getCode() ?: 500);
        } catch (\Exception $e) {
            Log::error('Failed to update participant role', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $organization,
                'error' => $e->getMessage(),
            ]);
            
            return AdminResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Удалить участника из проекта
     * 
     * DELETE /api/v1/admin/projects/{id}/organizations/{organization}
     */
    public function destroy(Request $request, int $organization): JsonResponse
    {
        try {
            [$project, $currentOrg, $projectContext] = $this->getProjectWithAccess($request);
            $id = $project->id;
            
            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_remove_permission'), 403);
            }
            
            $this->projectService->removeOrganizationFromProject(
                $id,
                $organization,
                $request
            );
            
            return AdminResponse::success(null, trans_message('project.participant_removed'));
        } catch (\RuntimeException $e) {
            return AdminResponse::error($e->getMessage(), (int)$e->getCode() ?: 500);
        } catch (\Exception $e) {
            Log::error('Failed to remove participant from project', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $organization,
                'error' => $e->getMessage(),
            ]);
            
            return AdminResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Активировать участника
     * 
     * POST /api/v1/admin/projects/{id}/organizations/{organization}/activate
     */
    public function activate(Request $request, int $organization): JsonResponse
    {
        try {
            [$projectModel, $currentOrg, $projectContext] = $this->getProjectWithAccess($request);
            $id = $projectModel->id;
            
            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_status_change_permission'), 403);
            }
            
            $projectModel->organizations()->updateExistingPivot($organization, [
                'is_active' => true,
                'updated_at' => now(),
            ]);
            
            return AdminResponse::success(null, trans_message('project.participant_activated'));
        } catch (\RuntimeException $e) {
            return AdminResponse::error($e->getMessage(), (int)$e->getCode() ?: 500);
        } catch (\Exception $e) {
            Log::error('Failed to activate participant', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $organization,
                'error' => $e->getMessage(),
            ]);
            
            return AdminResponse::error(trans_message('project.participant_activate_error'), 500);
        }
    }

    /**
     * Деактивировать участника
     * 
     * POST /api/v1/admin/projects/{id}/organizations/{organization}/deactivate
     */
    public function deactivate(Request $request, int $organization): JsonResponse
    {
        try {
            [$projectModel, $currentOrg, $projectContext] = $this->getProjectWithAccess($request);
            $id = $projectModel->id;
            
            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_status_change_permission'), 403);
            }
            
            $projectModel->organizations()->updateExistingPivot($organization, [
                'is_active' => false,
                'updated_at' => now(),
            ]);
            
            return AdminResponse::success(null, trans_message('project.participant_deactivated'));
        } catch (\RuntimeException $e) {
            return AdminResponse::error($e->getMessage(), (int)$e->getCode() ?: 500);
        } catch (\Exception $e) {
            Log::error('Failed to deactivate participant', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $organization,
                'error' => $e->getMessage(),
            ]);
            
            return AdminResponse::error(trans_message('project.participant_deactivate_error'), 500);
        }
    }

    /**
     * Получить список доступных организаций для добавления в проект
     * 
     * GET /api/v1/admin/projects/{id}/available-organizations
     */
    public function available(Request $request): JsonResponse
    {
        try {
            $project = $this->getProjectModel($request);
            $id = $project->id;
            $user = $request->user();
            
            if (!$user) {
                return AdminResponse::error(trans_message('project.unauthorized'), 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            
            if (!$currentOrg) {
                return AdminResponse::error(trans_message('project.organization_not_found'), 404);
            }

            // Проверяем доступ к проекту
            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return AdminResponse::error(trans_message('project.access_denied'), 403);
            }

            // Получаем ID уже добавленных организаций
            $existingOrgIds = $project->organizations()
                ->pluck('organizations.id')
                ->merge([$project->organization_id]) // Добавляем owner организацию
                ->unique()
                ->toArray();

            // Получаем доступные организации (связанные с текущей организацией)
            $availableOrgs = Organization::query()
                ->where('is_active', true)
                ->whereNotIn('id', $existingOrgIds)
                ->get()
                ->map(function ($org) {
                    return [
                        'id' => $org->id,
                        'name' => $org->name,
                        'inn' => $org->inn,
                        'address' => $org->address,
                        'phone' => $org->phone,
                    ];
                });

            return AdminResponse::success($availableOrgs);
        } catch (\Exception $e) {
            Log::error('Failed to get available organizations', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return AdminResponse::error(trans_message('project.available_organizations_error'), 500);
        }
    }
}
