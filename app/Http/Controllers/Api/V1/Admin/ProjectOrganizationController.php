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
use App\Models\Project;
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
     * GET /api/v1/admin/projects/{project}/organizations
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            if (!$currentOrg) {
                return response()->json([
                    'success' => false,
                    'message' => 'Organization not found',
                ], 404);
            }

            // Проверяем доступ к проекту
            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied',
                ], 403);
            }

            $projectContext = $this->projectContextService->getContext($project, $currentOrg);
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
                'project_id' => $project->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
     * POST /api/v1/admin/projects/{project}/organizations
     */
    public function store(Request $request, Project $project): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            if (!$currentOrg) {
                return response()->json(['success' => false, 'message' => 'Organization not found'], 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $projectContext = $this->projectContextService->getContext($project, $currentOrg);
            
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
            
            $organizationId = $request->input('organization_id');
            $role = ProjectOrganizationRole::from($request->input('role'));
            
            $this->projectService->addOrganizationToProject(
                $project->id,
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
                'project_id' => $project->id ?? null,
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
     * GET /api/v1/admin/projects/{project}/organizations/{organization}
     */
    public function show(Request $request, Project $project, Organization $organization): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            if (!$currentOrg) {
                return response()->json(['success' => false, 'message' => 'Organization not found'], 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }
            
            $role = $this->projectContextService->getOrganizationRole($project, $organization);
            
            if (!$role) {
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не является участником проекта',
                ], 404);
            }
            
            $profile = $this->organizationProfileService->getProfile($organization);
            $pivot = $project->getOrganizationPivot($organization->id);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'organization' => [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'inn' => $organization->inn,
                        'address' => $organization->address,
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
                'project_id' => $project->id ?? null,
                'organization_id' => $organization->id ?? null,
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
     * PATCH /api/v1/admin/projects/{project}/organizations/{organization}/role
     */
    public function updateRole(Request $request, Project $project, Organization $organization): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            if (!$currentOrg) {
                return response()->json(['success' => false, 'message' => 'Organization not found'], 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $projectContext = $this->projectContextService->getContext($project, $currentOrg);
            
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
            
            $role = ProjectOrganizationRole::from($request->input('role'));
            
            $this->projectService->updateOrganizationRole(
                $project->id,
                $organization->id,
                $role,
                $request
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Роль участника успешно обновлена',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], (int)$e->getCode() ?: 500);
        } catch (\Exception $e) {
            Log::error('Failed to update participant role', [
                'project_id' => $project->id ?? null,
                'organization_id' => $organization->id ?? null,
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
     * DELETE /api/v1/admin/projects/{project}/organizations/{organization}
     */
    public function destroy(Request $request, Project $project, Organization $organization): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            if (!$currentOrg) {
                return response()->json(['success' => false, 'message' => 'Organization not found'], 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $projectContext = $this->projectContextService->getContext($project, $currentOrg);
            
            if (!$projectContext->roleConfig->canInviteParticipants) {
                return response()->json([
                    'success' => false,
                    'message' => 'У вас нет прав для удаления участников из проекта',
                ], 403);
            }
            
            $this->projectService->removeOrganizationFromProject(
                $project->id,
                $organization->id,
                $request
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Участник успешно удален из проекта',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], (int)$e->getCode() ?: 500);
        } catch (\Exception $e) {
            Log::error('Failed to remove participant from project', [
                'project_id' => $project->id ?? null,
                'organization_id' => $organization->id ?? null,
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
     * POST /api/v1/admin/projects/{project}/organizations/{organization}/activate
     */
    public function activate(Request $request, Project $project, Organization $organization): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            if (!$currentOrg) {
                return response()->json(['success' => false, 'message' => 'Organization not found'], 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $projectContext = $this->projectContextService->getContext($project, $currentOrg);
            
            if (!$projectContext->roleConfig->canInviteParticipants) {
                return response()->json([
                    'success' => false,
                    'message' => 'У вас нет прав для управления статусом участников',
                ], 403);
            }
            
            $project->organizations()->updateExistingPivot($organization->id, [
                'is_active' => true,
                'updated_at' => now(),
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Участник активирован',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], (int)$e->getCode() ?: 500);
        } catch (\Exception $e) {
            Log::error('Failed to activate participant', [
                'project_id' => $project->id ?? null,
                'organization_id' => $organization->id ?? null,
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
     * POST /api/v1/admin/projects/{project}/organizations/{organization}/deactivate
     */
    public function deactivate(Request $request, Project $project, Organization $organization): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            if (!$currentOrg) {
                return response()->json(['success' => false, 'message' => 'Organization not found'], 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $projectContext = $this->projectContextService->getContext($project, $currentOrg);
            
            if (!$projectContext->roleConfig->canInviteParticipants) {
                return response()->json([
                    'success' => false,
                    'message' => 'У вас нет прав для управления статусом участников',
                ], 403);
            }
            
            $project->organizations()->updateExistingPivot($organization->id, [
                'is_active' => false,
                'updated_at' => now(),
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Участник деактивирован',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], (int)$e->getCode() ?: 500);
        } catch (\Exception $e) {
            Log::error('Failed to deactivate participant', [
                'project_id' => $project->id ?? null,
                'organization_id' => $organization->id ?? null,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate participant',
            ], 500);
        }
    }

    /**
     * Получить список доступных организаций для добавления в проект
     * 
     * GET /api/v1/admin/projects/{project}/available-organizations
     */
    public function available(Request $request, Project $project): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $currentOrg = Organization::find($user->current_organization_id);
            
            if (!$currentOrg) {
                return response()->json([
                    'success' => false,
                    'message' => 'Organization not found',
                ], 404);
            }

            // Проверяем доступ к проекту
            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied',
                ], 403);
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

            return response()->json([
                'success' => true,
                'data' => $availableOrgs,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get available organizations', [
                'project_id' => $project->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error',
            ], 500);
        }
    }
}
