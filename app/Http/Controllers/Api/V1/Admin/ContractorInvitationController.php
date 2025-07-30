<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contractor\ContractorInvitationService;
use App\Http\Requests\Api\V1\Admin\ContractorInvitation\StoreContractorInvitationRequest;
use App\Http\Resources\Api\V1\Admin\ContractorInvitation\ContractorInvitationResource;
use App\Http\Resources\Api\V1\Admin\ContractorInvitation\ContractorInvitationCollection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
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
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        $type = $request->input('type', 'sent');
        $perPage = min((int) $request->input('per_page', 15), 50);
        $filters = $request->only(['status', 'date_from', 'date_to']);

        try {
            $invitations = $this->invitationService->getInvitationsForOrganization(
                $organizationId,
                $type,
                $perPage,
                $filters
            );

            return response()->json([
                'success' => true,
                'data' => new ContractorInvitationCollection($invitations),
                'meta' => [
                    'type' => $type,
                    'filters' => $filters,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch contractor invitations', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'type' => $type,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении приглашений'
            ], 500);
        }
    }

    public function store(StoreContractorInvitationRequest $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        $validated = $request->validated();

        try {
            $invitation = $this->invitationService->createInvitation(
                $organizationId,
                $validated['invited_organization_id'],
                $user,
                $validated['message'] ?? null,
                $validated['metadata'] ?? []
            );

            return response()->json([
                'success' => true,
                'data' => new ContractorInvitationResource($invitation->load(['invitedOrganization', 'invitedBy'])),
                'message' => 'Приглашение успешно отправлено'
            ], 201);

        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            Log::error('Failed to create contractor invitation', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'invited_organization_id' => $validated['invited_organization_id'],
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании приглашения'
            ], 500);
        }
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $invitation = \App\Models\ContractorInvitation::with(['invitedOrganization', 'invitedBy', 'organization'])
                ->where('id', $id)
                ->where(function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                          ->orWhere('invited_organization_id', $organizationId);
                })
                ->firstOrFail();

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
            Log::error('Failed to fetch contractor invitation', [
                'error' => $e->getMessage(),
                'invitation_id' => $id,
                'organization_id' => $organizationId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении приглашения'
            ], 500);
        }
    }

    public function cancel(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $invitation = \App\Models\ContractorInvitation::where('id', $id)
                ->where('organization_id', $organizationId)
                ->where('status', 'pending')
                ->firstOrFail();

            $invitation->update(['status' => 'expired']);

            Log::info('Contractor invitation cancelled', [
                'invitation_id' => $id,
                'cancelled_by' => $user->id,
                'organization_id' => $organizationId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Приглашение отменено'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Приглашение не найдено или уже обработано'
            ], 404);

        } catch (\Exception $e) {
            Log::error('Failed to cancel contractor invitation', [
                'error' => $e->getMessage(),
                'invitation_id' => $id,
                'organization_id' => $organizationId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка при отмене приглашения'
            ], 500);
        }
    }

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $stats = $this->invitationService->getInvitationStats($organizationId);

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch contractor invitation stats', [
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