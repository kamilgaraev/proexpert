<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\Organization;
use App\Models\ProjectParticipantInvitation;
use App\Services\Project\ProjectParticipantInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ProjectParticipantInvitationController extends Controller
{
    public function __construct(private readonly ProjectParticipantInvitationService $invitations) {}

    public function show(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitations->previewByToken($token);
        } catch (ModelNotFoundException) {
            return LandingResponse::error(trans_message('customer.auth.invitation_not_found'), 404);
        }

        $status = $invitation->isCancelled()
            ? $invitation->status
            : ($invitation->isExpired() ? ProjectParticipantInvitation::STATUS_EXPIRED : $invitation->status);

        return LandingResponse::success([
            'status' => $status,
            'can_accept' => $invitation->isPending(),
            'project' => [
                'id' => $invitation->project?->id,
                'name' => $invitation->project?->name,
            ],
            'role' => $invitation->role,
            'invited_organization' => $invitation->invitedOrganization === null ? null : [
                'id' => $invitation->invitedOrganization->id,
                'name' => $invitation->invitedOrganization->name,
            ],
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'next_action' => $invitation->isPending()
                ? ($invitation->invited_organization_id === null ? 'register' : 'login')
                : 'unavailable',
        ]);
    }

    public function accept(string $token, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = (int) ($request->attributes->get('current_organization_id')
            ?? $user?->current_organization_id
            ?? 0);
        $organization = $organizationId > 0 ? Organization::query()->find($organizationId) : null;

        if ($user === null || ! $organization instanceof Organization) {
            return LandingResponse::error(trans_message('project_invitations.errors.owner_required'), 403);
        }

        try {
            $invitation = $this->invitations->acceptByTokenAsOrganizationOwner($token, $user, $organization);
        } catch (BusinessLogicException $exception) {
            return LandingResponse::error($exception->getMessage(), $exception->getCode() ?: 400);
        }

        return LandingResponse::success([
            'invitation' => [
                'id' => $invitation->id,
                'status' => $invitation->status,
                'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            ],
            'project' => [
                'id' => $invitation->project?->id,
                'name' => $invitation->project?->name,
            ],
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
        ], trans_message('project_invitations.accepted'));
    }
}
