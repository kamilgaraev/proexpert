<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Responses\CustomerResponse;
use App\Models\Organization;
use App\Services\Project\ProjectParticipantInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class InvitationController extends CustomerController
{
    public function __construct(
        private readonly ProjectParticipantInvitationService $invitationService
    ) {
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        try {
            $user = $request->user();
            $organizationId = $this->resolveOrganizationId($request);
            $organization = Organization::find($organizationId);

            if (!$user || !$organization instanceof Organization) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            $invitation = $this->invitationService->acceptByToken($token, $user, $organization);

            return CustomerResponse::success([
                'invitation' => [
                    'id' => $invitation->id,
                    'status' => $invitation->status,
                    'accepted_at' => optional($invitation->accepted_at)?->toIso8601String(),
                ],
            ], trans_message('customer.invitation_accepted'));
        } catch (Throwable $exception) {
            Log::error('customer.invitation.accept.failed', [
                'user_id' => $request->user()?->id,
                'token' => $token,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error($exception->getMessage(), 400);
        }
    }
}
