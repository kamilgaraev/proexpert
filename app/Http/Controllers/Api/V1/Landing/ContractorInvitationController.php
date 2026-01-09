<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\Contractor\ContractorInvitationService;
use App\Http\Resources\Api\V1\Landing\ContractorInvitation\ContractorInvitationResource;
use App\Http\Resources\Api\V1\Landing\ContractorInvitation\ContractorInvitationCollection;
use App\Exceptions\BusinessLogicException;
use App\Http\Responses\LandingResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ContractorInvitationController extends Controller
{
    protected ContractorInvitationService $invitationService;

    public function __construct(ContractorInvitationService $invitationService)
    {
        $this->invitationService = $invitationService;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return LandingResponse::error(trans_message('contract.organization_context_missing'), 400);
        }

        $perPage = min((int) $request->input('per_page', 15), 50);
        $filters = $request->only(['status', 'date_from', 'date_to']);

        try {
            $receivedInvitations = $this->invitationService->getInvitationsForOrganization(
                $organizationId,
                'received',
                $perPage,
                $filters
            );

            return LandingResponse::success([
                'data' => new ContractorInvitationCollection($receivedInvitations),
                'meta' => [
                    'type' => 'received',
                    'filters' => $filters,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch received contractor invitations', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return LandingResponse::error(trans_message('contract.invitations_retrieve_error'), 500);
        }
    }

    public function show(string $token): JsonResponse
    {
        try {
            $invitation = \App\Models\ContractorInvitation::where('token', $token)
                ->with(['organization', 'invitedOrganization', 'invitedBy'])
                ->firstOrFail();

            if ($invitation->isExpired()) {
                return LandingResponse::error(trans_message('contract.invitation_expired'), 410);
            }

            return LandingResponse::success(new ContractorInvitationResource($invitation));

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return LandingResponse::error(trans_message('contract.invitation_not_found'), 404);

        } catch (\Exception $e) {
            Log::error('Failed to fetch contractor invitation by token', [
                'error' => $e->getMessage(),
                'token' => $token,
            ]);

            return LandingResponse::error(trans_message('contract.invitation_retrieve_error'), 500);
        }
    }

    public function accept(string $token, Request $request): JsonResponse
    {
        $user = $request->user();

        try {
            $contractor = $this->invitationService->acceptInvitation($token, $user);

            Log::info('Contractor invitation accepted via landing', [
                'token' => $token,
                'accepted_by' => $user->id,
                'contractor_id' => $contractor->id,
            ]);

            return LandingResponse::success([
                'contractor' => $contractor->only(['id', 'name', 'connected_at']),
                'message' => trans_message('contract.invitation_accepted')
            ]);

        } catch (BusinessLogicException $e) {
            return LandingResponse::error($e->getMessage(), 400);

        } catch (\Exception $e) {
            Log::error('Failed to accept contractor invitation', [
                'error' => $e->getMessage(),
                'token' => $token,
                'user_id' => $user->id,
            ]);

            return LandingResponse::error(trans_message('contract.invitation_accept_error'), 500);
        }
    }

    public function decline(string $token, Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $declined = $this->invitationService->declineInvitation($token, $user);

            if (!$declined) {
                return LandingResponse::error(trans_message('contract.invitation_decline_error'), 400);
            }

            Log::info('Contractor invitation declined via landing', [
                'token' => $token,
                'declined_by' => $user->id,
                'reason' => $request->input('reason'),
            ]);

            return LandingResponse::success(null, trans_message('contract.invitation_declined'));

        } catch (BusinessLogicException $e) {
            return LandingResponse::error($e->getMessage(), 400);

        } catch (\Exception $e) {
            Log::error('Failed to decline contractor invitation', [
                'error' => $e->getMessage(),
                'token' => $token,
                'user_id' => $user->id,
            ]);

            return LandingResponse::error(trans_message('contract.invitation_decline_error'), 500);
        }
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return LandingResponse::error(trans_message('contract.organization_context_missing'), 400);
        }

        try {
            $stats = $this->invitationService->getInvitationStats($organizationId);

            return LandingResponse::success([
                'received_invitations' => $stats['received'],
                'sent_invitations' => $stats['sent'],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch contractor invitation stats for landing', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return LandingResponse::error(trans_message('contract.invitation_stats_error'), 500);
        }
    }
}