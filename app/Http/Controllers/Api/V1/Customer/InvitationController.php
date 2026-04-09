<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Http\Requests\Api\V1\Customer\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Customer\Auth\RegisterRequest;
use App\Http\Responses\CustomerResponse;
use App\Models\Organization;
use App\Services\Customer\Auth\CustomerAuthService;
use App\Services\Project\ProjectParticipantInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class InvitationController extends CustomerController
{
    private const GUARD = 'api_landing';

    public function __construct(
        private readonly ProjectParticipantInvitationService $invitationService,
        private readonly CustomerAuthService $customerAuthService,
    ) {
    }

    public function resolve(string $token): JsonResponse
    {
        try {
            $result = $this->customerAuthService->resolveInvitation($token);

            if (!$result['success']) {
                return CustomerResponse::error(
                    $result['message'] ?? trans_message('customer.auth.invitation_resolve_error'),
                    $result['status_code'] ?? 404
                );
            }

            return CustomerResponse::success(
                $result,
                trans_message('customer.auth.invitation_resolved')
            );
        } catch (Throwable $exception) {
            Log::error('customer.invitation.resolve.failed', [
                'token' => $token,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.auth.invitation_resolve_error'), 500);
        }
    }

    public function login(LoginRequest $request, string $token): JsonResponse
    {
        try {
            $result = $this->customerAuthService->loginByInvitation(
                $token,
                LoginDTO::fromRequest($request->validated()),
                self::GUARD
            );

            if (!$result['success']) {
                return CustomerResponse::error(
                    $result['message'] ?? trans_message('customer.auth.login_error'),
                    $result['status_code'] ?? 401,
                    null,
                    $this->extractExtraFields($result)
                );
            }

            return CustomerResponse::success(
                [
                    'token' => $result['token'],
                    'user' => $result['user'],
                    'organization' => $result['organization'],
                    'email_verified' => $result['email_verified'],
                    'available_interfaces' => $result['available_interfaces'],
                    'invitation' => $result['invitation'] ?? null,
                ],
                trans_message('customer.auth.invitation_login_success')
            );
        } catch (Throwable $exception) {
            Log::error('customer.invitation.login.failed', [
                'email' => $request->input('email'),
                'token' => $token,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.auth.invitation_login_error'), 500);
        }
    }

    public function register(RegisterRequest $request, string $token): JsonResponse
    {
        try {
            $result = $this->customerAuthService->registerByInvitation(
                $token,
                RegisterDTO::fromRequest($request->validated()),
                (string) config('app.customer_frontend_url')
            );

            if (!$result['success']) {
                return CustomerResponse::error(
                    $result['message'] ?? trans_message('customer.auth.register_error'),
                    $result['status_code'] ?? 400
                );
            }

            return CustomerResponse::success(
                $result,
                trans_message('customer.auth.invitation_register_success'),
                201
            );
        } catch (Throwable $exception) {
            Log::error('customer.invitation.register.failed', [
                'email' => $request->input('email'),
                'token' => $token,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.auth.invitation_register_error'), 500);
        }
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

    public function decline(string $token): JsonResponse
    {
        try {
            $invitation = $this->invitationService->declineByToken($token);

            return CustomerResponse::success([
                'invitation' => [
                    'id' => $invitation->id,
                    'status' => $invitation->status,
                    'cancelled_at' => optional($invitation->cancelled_at)?->toIso8601String(),
                ],
            ], trans_message('customer.auth.invitation_decline_success'));
        } catch (Throwable $exception) {
            Log::error('customer.invitation.decline.failed', [
                'token' => $token,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(
                $exception->getMessage() ?: trans_message('customer.auth.invitation_decline_error'),
                $exception->getCode() ?: 400
            );
        }
    }

    private function extractExtraFields(array $result): array
    {
        $extra = [];

        foreach (['status', 'email_verified', 'email'] as $field) {
            if (array_key_exists($field, $result)) {
                $extra[$field] = $result[$field];
            }
        }

        return $extra;
    }
}
