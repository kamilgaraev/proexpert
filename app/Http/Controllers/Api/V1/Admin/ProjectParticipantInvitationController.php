<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ProjectParticipantInvitation\StoreProjectParticipantInvitationRequest;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use App\Models\Project;
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
            $user = $request->user();
            $currentOrg = Organization::find($user?->current_organization_id);

            if (!$currentOrg instanceof Organization) {
                return AdminResponse::error(trans_message('project.organization_not_found'), 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return AdminResponse::error(trans_message('project.access_denied'), 403);
            }

            return AdminResponse::success([
                'items' => $this->invitationService->list($project)->map(function ($invitation): array {
                    return [
                        'id' => $invitation->id,
                        'token' => $invitation->token,
                        'status' => $invitation->status,
                        'role' => $invitation->role,
                        'organization_name' => $invitation->organization_name ?? $invitation->invitedOrganization?->name,
                        'email' => $invitation->email,
                        'inn' => $invitation->inn,
                        'contact_name' => $invitation->contact_name,
                        'phone' => $invitation->phone,
                        'message' => $invitation->message,
                        'expires_at' => optional($invitation->expires_at)?->toIso8601String(),
                        'accepted_at' => optional($invitation->accepted_at)?->toIso8601String(),
                        'invited_organization' => $invitation->invitedOrganization ? [
                            'id' => $invitation->invitedOrganization->id,
                            'name' => $invitation->invitedOrganization->name,
                        ] : null,
                    ];
                })->values()->all(),
            ]);
        } catch (Throwable $exception) {
            Log::error('project.participant_invitations.index.failed', [
                'project_id' => $project->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('project.participants_error'), 500);
        }
    }

    public function store(StoreProjectParticipantInvitationRequest $request, Project $project): JsonResponse
    {
        try {
            $user = $request->user();
            $currentOrg = Organization::find($user?->current_organization_id);

            if (!$currentOrg instanceof Organization) {
                return AdminResponse::error(trans_message('project.organization_not_found'), 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($project, $currentOrg)) {
                return AdminResponse::error(trans_message('project.access_denied'), 403);
            }

            $projectContext = $this->projectContextService->getContext($project, $currentOrg);

            if (!$projectContext->roleConfig->canInviteParticipants) {
                return AdminResponse::error(trans_message('project.no_invite_permission'), 403);
            }

            $invitation = $this->invitationService->create(
                $project,
                $currentOrg->id,
                $user,
                $request->validated()
            );

            return AdminResponse::success([
                'invitation' => [
                    'id' => $invitation->id,
                    'token' => $invitation->token,
                    'status' => $invitation->status,
                    'role' => $invitation->role,
                    'organization_name' => $invitation->organization_name,
                    'email' => $invitation->email,
                    'inn' => $invitation->inn,
                    'contact_name' => $invitation->contact_name,
                    'phone' => $invitation->phone,
                    'message' => $invitation->message,
                    'expires_at' => optional($invitation->expires_at)?->toIso8601String(),
                ],
            ], trans_message('project.participant_added'), 201);
        } catch (Throwable $exception) {
            Log::error('project.participant_invitations.store.failed', [
                'project_id' => $project->id,
                'user_id' => $request->user()?->id,
                'payload' => $request->except(['message']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 400);
        }
    }
}
