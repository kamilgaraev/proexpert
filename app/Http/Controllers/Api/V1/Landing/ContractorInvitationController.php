<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\Contractor\ContractorInvitationService;
use App\Http\Resources\Api\V1\Landing\ContractorInvitation\ContractorInvitationResource;
use App\Http\Resources\Api\V1\Landing\ContractorInvitation\ContractorInvitationCollection;
use App\Exceptions\BusinessLogicException;
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
            return response()->json(['message' => 'Не определён контекст организации'], 400);
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

            return response()->json([
                'success' => true,
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

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении приглашений'
            ], 500);
        }
    }

    public function show(string $token): JsonResponse
    {
        try {
            $invitation = \App\Models\ContractorInvitation::where('token', $token)
                ->with(['organization', 'invitedOrganization', 'invitedBy'])
                ->firstOrFail();

            if ($invitation->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Срок действия приглашения истек'
                ], 410);
            }

            return response()->json([
                'success' => true,
                'data' => new ContractorInvitationResource($invitation)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Приглашение не найдено'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Failed to fetch contractor invitation by token', [
                'error' => $e->getMessage(),
                'token' => $token,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении приглашения'
            ], 500);
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

            return response()->json([
                'success' => true,
                'data' => [
                    'contractor' => $contractor->only(['id', 'name', 'connected_at']),
                    'message' => 'Приглашение принято. Теперь вы можете работать с данной организацией как подрядчик.'
                ]
            ]);

        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            Log::error('Failed to accept contractor invitation', [
                'error' => $e->getMessage(),
                'token' => $token,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при принятии приглашения'
            ], 500);
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
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось отклонить приглашение'
                ], 400);
            }

            Log::info('Contractor invitation declined via landing', [
                'token' => $token,
                'declined_by' => $user->id,
                'reason' => $request->input('reason'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Приглашение отклонено'
            ]);

        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            Log::error('Failed to decline contractor invitation', [
                'error' => $e->getMessage(),
                'token' => $token,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при отклонении приглашения'
            ], 500);
        }
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $stats = $this->invitationService->getInvitationStats($organizationId);

            return response()->json([
                'success' => true,
                'data' => [
                    'received_invitations' => $stats['received'],
                    'sent_invitations' => $stats['sent'],
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch contractor invitation stats for landing', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении статистики'
            ], 500);
        }
    }
}