<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ProjectParticipantInvitation\StoreProjectParticipantInvitationRequest;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Services\Project\ProjectContextService;
use App\Services\Project\ProjectParticipantInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProjectParticipantInvitationController extends Controller
{
    public function __construct(
        private readonly ProjectContextService $projectContextService,
        private readonly ProjectParticipantInvitationService $invitationService
    ) {
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        try {
            [$currentOrg, $projectContext] = $this->resolveAccessContext($request, $project);

            return AdminResponse::success([
                'items' => $this->invitationService->list($project)
                    ->map(fn (ProjectParticipantInvitation $invitation): array => $this->mapInvitation($invitation))
                    ->values()
                    ->all(),
            ]);
        } catch (Throwable $exception) {
            Log::error('project.participant_invitations.index.failed', [
                'project_id' => $project->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(
                $exception->getMessage() ?: trans_message('project.participants_error'),
                $exception->getCode() ?: 500
            );
        }
    }

    public function store(StoreProjectParticipantInvitationRequest $request, Project $project): JsonResponse
    {
        try {
            [$currentOrg, $projectContext] = $this->resolveAccessContext($request, $project);

            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_invite_permission'), 403);
            }

            $invitation = $this->invitationService->create(
                $project,
                $currentOrg->id,
                $request->user(),
                $request->validated()
            );

            return AdminResponse::success([
                'invitation' => $this->mapInvitation($invitation),
            ], trans_message('project.participant_added'), 201);
        } catch (Throwable $exception) {
            Log::error('project.participant_invitations.store.failed', [
                'project_id' => $project->id,
                'user_id' => $request->user()?->id,
                'payload' => $request->except(['message']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), $exception->getCode() ?: 400);
        }
    }

    public function cancel(Request $request, Project $project, ProjectParticipantInvitation $invitation): JsonResponse
    {
        try {
            [$currentOrg, $projectContext] = $this->resolveAccessContext($request, $project);

            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_invite_permission'), 403);
            }

            $updatedInvitation = $this->invitationService->cancel($project, $invitation, $request->user());

            return AdminResponse::success([
                'invitation' => $this->mapInvitation($updatedInvitation),
            ], trans_message('project.participant_removed'));
        } catch (Throwable $exception) {
            Log::error('project.participant_invitations.cancel.failed', [
                'project_id' => $project->id,
                'invitation_id' => $invitation->id ?? null,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), $exception->getCode() ?: 400);
        }
    }

    public function resend(Request $request, Project $project, ProjectParticipantInvitation $invitation): JsonResponse
    {
        try {
            [$currentOrg, $projectContext] = $this->resolveAccessContext($request, $project);

            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_invite_permission'), 403);
            }

            $updatedInvitation = $this->invitationService->resend($project, $invitation, $request->user());

            return AdminResponse::success([
                'invitation' => $this->mapInvitation($updatedInvitation),
            ], trans_message('project.participant_added'));
        } catch (Throwable $exception) {
            Log::error('project.participant_invitations.resend.failed', [
                'project_id' => $project->id,
                'invitation_id' => $invitation->id ?? null,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), $exception->getCode() ?: 400);
        }
    }

    private function resolveAccessContext(Request $request, Project $project): array
    {
        $user = $request->user();
        $currentOrg = Organization::find($user?->current_organization_id);

        if (!$currentOrg instanceof Organization) {
            throw new \RuntimeException(trans_message('project.organization_not_found'), 404);
        }

        if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
            throw new \RuntimeException(trans_message('project.access_denied'), 403);
        }

        return [$currentOrg, $this->projectContextService->getContext($project, $currentOrg)];
    }

    private function mapInvitation(ProjectParticipantInvitation $invitation): array
    {
        $status = $invitation->isCancelled()
            ? ProjectParticipantInvitation::STATUS_CANCELLED
            : ($invitation->isExpired() ? ProjectParticipantInvitation::STATUS_EXPIRED : $invitation->status);

        return [
            'id' => $invitation->id,
            'token' => $invitation->token,
            'status' => $status,
            'status_reason' => $invitation->status_reason,
            'role' => $invitation->role,
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
            'accepted_organization_id_snapshot' => $invitation->accepted_organization_id_snapshot,
            'invited_organization' => $invitation->invitedOrganization ? [
                'id' => $invitation->invitedOrganization->id,
                'name' => $invitation->invitedOrganization->name,
            ] : null,
        ];
    }
}
