<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ProjectOrganizationRole;
use App\Exceptions\BusinessLogicException;
use App\Http\Requests\Api\V1\Customer\Project\StoreProjectParticipantInvitationRequest;
use App\Http\Requests\Api\V1\Customer\Project\StoreProjectRequest;
use App\Http\Responses\CustomerResponse;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Services\Project\ProjectContextService;
use App\Services\Project\ProjectParticipantInvitationService;
use App\Services\Project\ProjectService;
use App\Services\Customer\CustomerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class ProjectController extends CustomerController
{
    public function __construct(
        private readonly CustomerPortalService $customerPortalService,
        private readonly ProjectService $projectService,
        private readonly ProjectContextService $projectContextService,
        private readonly ProjectParticipantInvitationService $projectParticipantInvitationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            return CustomerResponse::success(
                $this->customerPortalService->getProjects($organizationId, $user),
                trans_message('customer.projects_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.projects.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.projects_load_error'), 500);
        }
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->hasPermission($request, 'customer.projects.manage', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $project = $this->projectService->createProject($request->toDto(), $request);

            return CustomerResponse::success(
                $this->customerPortalService->getProject($organizationId, $project, $user),
                trans_message('customer.project_created'),
                201
            );
        } catch (BusinessLogicException $exception) {
            return CustomerResponse::error($exception->getMessage(), $exception->getCode() ?: 400);
        } catch (Throwable $exception) {
            Log::error('customer.project.store.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_create_error'), 500);
        }
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getProject($organizationId, $project, $user),
                trans_message('customer.project_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_load_error'), 500);
        }
    }

    public function documents(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getDocuments($organizationId, $project, $user),
                trans_message('customer.documents_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.documents.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.documents_load_error'), 500);
        }
    }

    public function approvals(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getApprovals($organizationId, $project, $user),
                trans_message('customer.approvals_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.approvals.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.approvals_load_error'), 500);
        }
    }

    public function conversations(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getConversations($organizationId, $project, $user),
                trans_message('customer.conversations_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.conversations.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.conversations_load_error'), 500);
        }
    }

    public function workspace(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getProjectWorkspace($organizationId, $project, $user),
                trans_message('customer.project_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.workspace.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_load_error'), 500);
        }
    }

    public function timeline(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getProjectTimeline($organizationId, $project, $user),
                trans_message('customer.project_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.timeline.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_load_error'), 500);
        }
    }

    public function risks(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getProjectRisks($organizationId, $project, $user),
                trans_message('customer.project_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.risks.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_load_error'), 500);
        }
    }

    public function participants(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                [
                    'participants' => $this->mapParticipants($project),
                    'invitations' => $this->projectParticipantInvitationService
                        ->list($project)
                        ->map(fn (ProjectParticipantInvitation $invitation): array => $this->mapInvitation($invitation))
                        ->values()
                        ->all(),
                    'can_manage' => $this->canManageProjectParticipants($request, $project, $organizationId),
                    'allowed_roles' => $this->allowedInvitationRoles(),
                ],
                trans_message('customer.project_participants_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.participants.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_participants_load_error'), 500);
        }
    }

    public function invitations(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            return CustomerResponse::success(
                $this->customerPortalService->getProjectInvitations($organizationId, $user),
                trans_message('customer.project_invitations_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.invitations.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_invitations_load_error'), 500);
        }
    }

    public function inviteParticipant(
        StoreProjectParticipantInvitationRequest $request,
        Project $project
    ): JsonResponse {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->canManageProjectParticipants($request, $project, $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $invitation = $this->projectParticipantInvitationService->create(
                $project,
                $organizationId,
                $request->user(),
                $request->validated()
            );

            return CustomerResponse::success(
                [
                    'invitation' => $this->mapInvitation($invitation),
                ],
                trans_message('customer.project_invitation_created'),
                201
            );
        } catch (BusinessLogicException $exception) {
            return CustomerResponse::error($exception->getMessage(), $exception->getCode() ?: 400);
        } catch (Throwable $exception) {
            Log::error('customer.project.participants.invite.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'payload' => $request->except(['message']),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_invitation_create_error'), 500);
        }
    }

    public function cancelInvitation(
        Request $request,
        Project $project,
        ProjectParticipantInvitation $invitation
    ): JsonResponse {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->canManageProjectParticipants($request, $project, $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $invitation = $this->projectParticipantInvitationService->cancel($project, $invitation, $request->user());

            return CustomerResponse::success(
                [
                    'invitation' => $this->mapInvitation($invitation),
                ],
                trans_message('customer.project_invitation_cancelled')
            );
        } catch (BusinessLogicException $exception) {
            return CustomerResponse::error($exception->getMessage(), $exception->getCode() ?: 400);
        } catch (Throwable $exception) {
            Log::error('customer.project.participants.cancel.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'invitation_id' => $invitation->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_invitation_update_error'), 500);
        }
    }

    public function resendInvitation(
        Request $request,
        Project $project,
        ProjectParticipantInvitation $invitation
    ): JsonResponse {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->canManageProjectParticipants($request, $project, $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $invitation = $this->projectParticipantInvitationService->resend($project, $invitation, $request->user());

            return CustomerResponse::success(
                [
                    'invitation' => $this->mapInvitation($invitation),
                ],
                trans_message('customer.project_invitation_resent')
            );
        } catch (BusinessLogicException $exception) {
            return CustomerResponse::error($exception->getMessage(), $exception->getCode() ?: 400);
        } catch (Throwable $exception) {
            Log::error('customer.project.participants.resend.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'invitation_id' => $invitation->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_invitation_update_error'), 500);
        }
    }

    private function canManageProjectParticipants(Request $request, Project $project, int $organizationId): bool
    {
        if ((int) $project->organization_id !== $organizationId) {
            return false;
        }

        return $this->hasPermission($request, 'customer.projects.participants.manage', $organizationId);
    }

    private function mapParticipants(Project $project): array
    {
        return collect($this->projectContextService->getAllProjectParticipants($project))
            ->map(function (array $participant): array {
                /** @var \App\Models\Organization $organization */
                $organization = $participant['organization'];
                /** @var ProjectOrganizationRole $role */
                $role = $participant['role'];

                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'email' => $organization->email,
                    'phone' => $organization->phone,
                    'inn' => $organization->tax_number,
                    'role' => $role->value,
                    'role_label' => $role->label(),
                    'is_owner' => (bool) ($participant['is_owner'] ?? false),
                    'is_active' => (bool) ($participant['is_active'] ?? false),
                    'accepted_at' => $participant['accepted_at']?->toIso8601String(),
                    'invited_at' => $participant['invited_at']?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    private function mapInvitation(ProjectParticipantInvitation $invitation): array
    {
        $status = $invitation->isCancelled()
            ? ProjectParticipantInvitation::STATUS_CANCELLED
            : ($invitation->isExpired() ? ProjectParticipantInvitation::STATUS_EXPIRED : $invitation->status);

        $role = $invitation->roleEnum();

        return [
            'id' => $invitation->id,
            'status' => $status,
            'status_reason' => $invitation->status_reason,
            'role' => $invitation->role,
            'role_label' => $role->label(),
            'organization_name' => $invitation->organization_name ?? $invitation->invitedOrganization?->name,
            'email' => $invitation->email,
            'inn' => $invitation->inn,
            'contact_name' => $invitation->contact_name,
            'phone' => $invitation->phone,
            'message' => $invitation->message,
            'expires_at' => optional($invitation->expires_at)?->toIso8601String(),
            'accepted_at' => optional($invitation->accepted_at)?->toIso8601String(),
            'cancelled_at' => optional($invitation->cancelled_at)?->toIso8601String(),
            'resent_at' => optional($invitation->resent_at)?->toIso8601String(),
            'invited_organization' => $invitation->invitedOrganization ? [
                'id' => $invitation->invitedOrganization->id,
                'name' => $invitation->invitedOrganization->name,
            ] : null,
        ];
    }

    private function allowedInvitationRoles(): array
    {
        return [
            [
                'value' => ProjectOrganizationRole::GENERAL_CONTRACTOR->value,
                'label' => ProjectOrganizationRole::GENERAL_CONTRACTOR->label(),
            ],
            [
                'value' => ProjectOrganizationRole::CONTRACTOR->value,
                'label' => ProjectOrganizationRole::CONTRACTOR->label(),
            ],
        ];
    }
}
