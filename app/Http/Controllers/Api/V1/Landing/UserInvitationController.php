<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\UserInvitationResource;
use App\Http\Responses\LandingResponse;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserInvitationController extends Controller
{
    public function __construct(
        private readonly UserInvitationService $invitationService
    ) {
    }

    public function index(): JsonResponse
    {
        $organizationId = Auth::user()?->current_organization_id;

        if (!$organizationId) {
            return LandingResponse::success([]);
        }

        $invitations = UserInvitation::where('organization_id', $organizationId)
            ->with(['invitedBy', 'acceptedBy', 'organization'])
            ->latest('created_at')
            ->get();

        return LandingResponse::success(UserInvitationResource::collection($invitations));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|max:255',
            'role_slugs' => 'array',
            'role_slugs.*' => 'string',
            'metadata' => 'array',
        ]);

        $creator = Auth::user();
        $organizationId = $creator?->current_organization_id;

        if (!$creator || !$organizationId) {
            return LandingResponse::error(trans_message('user_invitations.errors.organization_required'), 400);
        }

        try {
            $invitation = $this->invitationService->createInvitation(
                [
                    'email' => $data['email'],
                    'name' => $data['name'],
                    'role_slugs' => $data['role_slugs'] ?? [],
                    'metadata' => $data['metadata'] ?? null,
                ],
                (int) $organizationId,
                $creator
            );

            return LandingResponse::success(
                new UserInvitationResource($invitation->fresh(['invitedBy', 'acceptedBy', 'organization'])),
                trans_message('user_invitations.messages.created'),
                201
            );
        } catch (BusinessLogicException $e) {
            return LandingResponse::error(trans_message('user_invitations.errors.create_failed'), 422);
        }
    }

    public function show(int $invitationId): JsonResponse
    {
        $organizationId = Auth::user()?->current_organization_id;

        if (!$organizationId) {
            return LandingResponse::error(trans_message('user_invitations.errors.organization_required'), 400);
        }

        $invitation = UserInvitation::where('id', $invitationId)
            ->where('organization_id', $organizationId)
            ->with(['invitedBy', 'acceptedBy', 'organization'])
            ->first();

        if (!$invitation) {
            return LandingResponse::error(trans_message('user_invitations.errors.not_found'), 404);
        }

        return LandingResponse::success(new UserInvitationResource($invitation));
    }

    public function destroy(int $invitationId): JsonResponse
    {
        $organizationId = Auth::user()?->current_organization_id;

        if (!$organizationId) {
            return LandingResponse::error(trans_message('user_invitations.errors.organization_required'), 400);
        }

        try {
            $this->invitationService->cancelInvitation($invitationId, (int) $organizationId);

            return LandingResponse::success(null, trans_message('user_invitations.messages.cancelled'));
        } catch (BusinessLogicException $e) {
            return LandingResponse::error(trans_message('user_invitations.errors.cancel_failed'), 422);
        }
    }

    public function resend(Request $request, int $invitationId): JsonResponse
    {
        $organizationId = Auth::user()?->current_organization_id;

        if (!$organizationId) {
            return LandingResponse::error(trans_message('user_invitations.errors.organization_required'), 400);
        }

        try {
            $invitation = $this->invitationService->resendInvitation($invitationId, (int) $organizationId);

            return LandingResponse::success(
                new UserInvitationResource($invitation->fresh(['invitedBy', 'acceptedBy', 'organization'])),
                trans_message('user_invitations.messages.resent')
            );
        } catch (BusinessLogicException $e) {
            return LandingResponse::error(trans_message('user_invitations.errors.resend_failed'), 422);
        }
    }

    public function stats(Request $request): JsonResponse
    {
        $organizationId = Auth::user()?->current_organization_id;

        if (!$organizationId) {
            return LandingResponse::error(trans_message('user_invitations.errors.organization_required'), 400);
        }

        return LandingResponse::success($this->invitationService->getInvitationStats((int) $organizationId));
    }

    public function getByToken(string $token): JsonResponse
    {
        $invitation = UserInvitation::where('token', $token)
            ->with(['organization'])
            ->first();

        if (!$invitation) {
            return LandingResponse::error(trans_message('user_invitations.errors.not_found'), 404);
        }

        return LandingResponse::success([
            'email' => $invitation->email,
            'name' => $invitation->name,
            'organization_name' => $invitation->organization?->name,
            'role_names' => $invitation->role_names,
            'status' => $invitation->status->value,
            'status_text' => $invitation->status_text,
            'expires_at' => optional($invitation->expires_at)->toIso8601String(),
            'can_be_accepted' => $invitation->canBeAccepted(),
            'is_expired' => $invitation->isExpired(),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $user = $this->invitationService->acceptInvitation($token, [
                'password' => $data['password'],
            ]);

            return LandingResponse::success([
                'user_id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ], trans_message('user_invitations.messages.accepted'));
        } catch (BusinessLogicException $e) {
            return LandingResponse::error(trans_message('user_invitations.errors.accept_failed'), 422);
        }
    }
}
