<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeInvitation;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Brigades\StoreBrigadeInvitationRequest;
use App\Http\Resources\Brigades\BrigadeInvitationResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrigadeInvitationManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;
        $invitations = BrigadeInvitation::query()
            ->with(['brigade.specializations', 'project', 'contractorOrganization'])
            ->where('contractor_organization_id', $organizationId)
            ->latest()
            ->get();

        return AdminResponse::success(BrigadeInvitationResource::collection($invitations));
    }

    public function store(StoreBrigadeInvitationRequest $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;
        $invitation = BrigadeInvitation::create([
            ...$request->validated(),
            'contractor_organization_id' => $organizationId,
            'status' => BrigadeStatuses::INVITATION_PENDING,
        ]);

        return AdminResponse::success(
            new BrigadeInvitationResource($invitation->load(['brigade.specializations', 'project', 'contractorOrganization'])),
            trans_message('brigades.invitation_created'),
            201
        );
    }

    public function cancel(Request $request, int $invitationId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;
        $invitation = BrigadeInvitation::query()
            ->where('contractor_organization_id', $organizationId)
            ->findOrFail($invitationId);

        $invitation->update(['status' => BrigadeStatuses::INVITATION_CANCELLED]);

        return AdminResponse::success(
            new BrigadeInvitationResource($invitation->load(['project', 'contractorOrganization'])),
            trans_message('brigades.invitation_cancelled')
        );
    }
}
