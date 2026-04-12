<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Http\Controllers\Controller;
use App\Http\Resources\Brigades\BrigadeInvitationResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

class BrigadeInvitationController extends Controller
{
    public function __construct(private readonly BrigadeWorkflowService $workflowService)
    {
    }

    public function index(): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade(auth()->user());
        $invitations = $brigade->invitations()->with(['project', 'contractorOrganization'])->latest()->get();

        return AdminResponse::success(BrigadeInvitationResource::collection($invitations));
    }

    public function accept(int $invitationId): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade(auth()->user());
        $invitation = $brigade->invitations()->with(['project', 'contractorOrganization'])->whereKey($invitationId)->firstOrFail();
        $invitation->update(['status' => BrigadeStatuses::INVITATION_ACCEPTED]);
        $assignment = $this->workflowService->createAssignmentFromInvitation($invitation);

        return AdminResponse::success([
            'invitation' => new BrigadeInvitationResource($invitation),
            'assignment_id' => $assignment->id,
        ], trans_message('brigades.invitation_accepted'));
    }

    public function decline(int $invitationId): JsonResponse
    {
        $brigade = $this->workflowService->getOwnedBrigade(auth()->user());
        $invitation = $brigade->invitations()->whereKey($invitationId)->firstOrFail();
        $invitation->update(['status' => BrigadeStatuses::INVITATION_DECLINED]);

        return AdminResponse::success(new BrigadeInvitationResource($invitation), trans_message('brigades.invitation_declined'));
    }
}
