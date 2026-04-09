<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ProjectOrganizationRole;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use App\Models\Project;
use App\Services\Organization\OrganizationProfileService;
use App\Services\Project\ProjectContextService;
use App\Services\Project\ProjectCustomerResolverService;
use App\Services\Project\ProjectParticipantService;
use App\Services\Project\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProjectOrganizationController extends Controller
{
    public function __construct(
        private readonly ProjectService $projectService,
        private readonly ProjectContextService $projectContextService,
        private readonly OrganizationProfileService $organizationProfileService,
        private readonly ProjectParticipantService $projectParticipantService,
        private readonly ProjectCustomerResolverService $projectCustomerResolverService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            [$project, $currentOrg, $projectContext] = $this->getProjectWithAccess($request);
            $participants = $this->projectContextService->getAllProjectParticipants($project);

            return AdminResponse::success([
                'participants' => array_map(
                    fn (array $participant): array => $this->mapParticipant($participant),
                    $participants
                ),
                'resolved_customer' => $this->projectCustomerResolverService->resolve($project),
                'can_manage' => $projectContext->roleConfig->canInviteParticipants,
            ]);
        } catch (\RuntimeException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 500);
        } catch (\Throwable $exception) {
            Log::error('project.participants.index.failed', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('project.participants_error'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            [$project, $currentOrg, $projectContext] = $this->getProjectWithAccess($request);

            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_invite_permission'), 403);
            }

            $validator = Validator::make($request->all(), [
                'organization_id' => 'required|integer|exists:organizations,id',
                'role' => 'required|string|in:' . implode(',', array_map(
                    static fn (ProjectOrganizationRole $role): string => $role->value,
                    ProjectOrganizationRole::cases()
                )),
            ]);

            if ($validator->fails()) {
                return AdminResponse::error(trans_message('project.validation_failed'), 422, $validator->errors());
            }

            $this->projectParticipantService->attach(
                $project,
                (int) $request->input('organization_id'),
                ProjectOrganizationRole::from((string) $request->input('role')),
                $request->user()
            );

            return AdminResponse::success(null, trans_message('project.participant_added'));
        } catch (\RuntimeException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 500);
        } catch (\Throwable $exception) {
            Log::error('project.participants.store.failed', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $request->input('organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 400);
        }
    }

    public function show(Request $request, int $organization): JsonResponse
    {
        try {
            [$project] = $this->getProjectWithAccess($request);
            $participant = Organization::findOrFail($organization);
            $role = $this->projectContextService->getOrganizationRole($project, $participant);

            if (!$role instanceof ProjectOrganizationRole) {
                return AdminResponse::error(trans_message('project.participant_not_found'), 404);
            }

            $profile = $this->organizationProfileService->getProfile($participant);
            $pivot = $project->getOrganizationPivot($organization);

            return AdminResponse::success([
                'organization' => [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'inn' => $participant->inn,
                    'address' => $participant->address,
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
        } catch (\RuntimeException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 500);
        } catch (\Throwable $exception) {
            Log::error('project.participants.show.failed', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $organization,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('project.participant_details_error'), 500);
        }
    }

    public function updateRole(Request $request, int $organization): JsonResponse
    {
        try {
            [$project, $currentOrg, $projectContext] = $this->getProjectWithAccess($request);

            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_role_change_permission'), 403);
            }

            $validator = Validator::make($request->all(), [
                'role' => 'required|string|in:' . implode(',', array_map(
                    static fn (ProjectOrganizationRole $role): string => $role->value,
                    ProjectOrganizationRole::cases()
                )),
            ]);

            if ($validator->fails()) {
                return AdminResponse::error(trans_message('project.validation_failed'), 422, $validator->errors());
            }

            $this->projectParticipantService->updateRole(
                $project,
                $organization,
                ProjectOrganizationRole::from((string) $request->input('role')),
                $request->user()
            );

            return AdminResponse::success(null, trans_message('project.participant_role_updated'));
        } catch (\RuntimeException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 500);
        } catch (\Throwable $exception) {
            Log::error('project.participants.role.failed', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $organization,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 400);
        }
    }

    public function destroy(Request $request, int $organization): JsonResponse
    {
        try {
            [$project, $currentOrg, $projectContext] = $this->getProjectWithAccess($request);

            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_remove_permission'), 403);
            }

            $this->projectService->removeOrganizationFromProject($project->id, $organization, $request);

            return AdminResponse::success(null, trans_message('project.participant_removed'));
        } catch (\RuntimeException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 500);
        } catch (\Throwable $exception) {
            Log::error('project.participants.destroy.failed', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $organization,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 400);
        }
    }

    public function activate(Request $request, int $organization): JsonResponse
    {
        try {
            [$project, $currentOrg, $projectContext] = $this->getProjectWithAccess($request);

            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_status_change_permission'), 403);
            }

            $this->projectParticipantService->setActiveState($project, $organization, true);

            return AdminResponse::success(null, trans_message('project.participant_activated'));
        } catch (\RuntimeException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 500);
        } catch (\Throwable $exception) {
            Log::error('project.participants.activate.failed', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $organization,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('project.participant_activate_error'), 500);
        }
    }

    public function deactivate(Request $request, int $organization): JsonResponse
    {
        try {
            [$project, $currentOrg, $projectContext] = $this->getProjectWithAccess($request);

            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_status_change_permission'), 403);
            }

            $this->projectParticipantService->setActiveState($project, $organization, false);

            return AdminResponse::success(null, trans_message('project.participant_deactivated'));
        } catch (\RuntimeException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 500);
        } catch (\Throwable $exception) {
            Log::error('project.participants.deactivate.failed', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'organization_id' => $organization,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('project.participant_deactivate_error'), 500);
        }
    }

    public function available(Request $request): JsonResponse
    {
        try {
            [$project] = $this->getProjectWithAccess($request);

            $existingOrgIds = $project->organizations()
                ->pluck('organizations.id')
                ->merge([$project->organization_id])
                ->unique()
                ->toArray();

            $availableOrganizations = Organization::query()
                ->where('is_active', true)
                ->whereNotIn('id', $existingOrgIds)
                ->get()
                ->map(fn (Organization $organization): array => $this->mapAvailableOrganization($organization))
                ->values()
                ->all();

            return AdminResponse::success($availableOrganizations);
        } catch (\RuntimeException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 500);
        } catch (\Throwable $exception) {
            Log::error('project.participants.available.failed', [
                'project_id' => $request->route('project') ?? $request->route('id'),
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('project.available_organizations_error'), 500);
        }
    }

    private function getProjectWithAccess(Request $request): array
    {
        $project = $this->getProjectModel($request);
        $user = $request->user();

        if (!$user) {
            throw new \RuntimeException(trans_message('project.unauthorized'), 401);
        }

        $currentOrg = Organization::find($user->current_organization_id);
        if (!$currentOrg instanceof Organization) {
            throw new \RuntimeException(trans_message('project.organization_not_found'), 404);
        }

        if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
            throw new \RuntimeException(trans_message('project.access_denied'), 403);
        }

        return [$project, $currentOrg, $this->projectContextService->getContext($project, $currentOrg)];
    }

    private function getProjectModel(Request $request): Project
    {
        $project = $request->attributes->get('project');
        if ($project instanceof Project) {
            return $project;
        }

        $routeProject = $request->route('project');
        if ($routeProject instanceof Project) {
            return $routeProject;
        }

        $projectId = null;

        if (is_numeric($routeProject)) {
            $projectId = (int) $routeProject;
        } elseif ($request->route('id')) {
            $projectId = (int) $request->route('id');
        }

        if ($projectId !== null) {
            return Project::findOrFail($projectId);
        }

        throw new \RuntimeException('Project ID not found in request');
    }

    private function mapParticipant(array $participant): array
    {
        $organization = $participant['organization'];
        $profile = $this->organizationProfileService->getProfile($organization);

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'inn' => $organization->inn,
            'capabilities' => $profile->getCapabilities(),
            'primary_business_type' => $profile->getPrimaryBusinessType()?->value,
            'interaction_modes' => $profile->getInteractionModes(),
            'allowed_project_roles' => $profile->getAllowedProjectRoles(),
            'role' => [
                'value' => $participant['role']->value,
                'label' => $participant['role']->label(),
            ],
            'is_active' => $participant['is_active'],
            'status' => $participant['is_active'] ? 'active' : 'inactive',
            'is_owner' => $participant['is_owner'],
            'added_at' => $participant['added_at'] ?? null,
            'invited_at' => $participant['invited_at'] ?? null,
            'accepted_at' => $participant['accepted_at'] ?? null,
        ];
    }

    private function mapAvailableOrganization(Organization $organization): array
    {
        $profile = $this->organizationProfileService->getProfile($organization);

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'inn' => $organization->inn,
            'address' => $organization->address,
            'phone' => $organization->phone,
            'capabilities' => $profile->getCapabilities(),
            'primary_business_type' => $profile->getPrimaryBusinessType()?->value,
            'interaction_modes' => $profile->getInteractionModes(),
            'allowed_project_roles' => $profile->getAllowedProjectRoles(),
            'availability_status' => 'available',
        ];
    }
}
