<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Responses\CustomerResponse;
use App\Models\User;
use App\Services\Customer\CustomerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

class TeamController extends CustomerController
{
    public function __construct(
        private readonly CustomerPortalService $customerPortalService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            if (!$this->hasPermission($request, 'customer.team.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getTeam($user, $organizationId),
                trans_message('customer.team_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.team.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.team_load_error'), 500);
        }
    }

    public function show(Request $request, User $member): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            if (!$this->hasPermission($request, 'customer.team.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $payload = $this->customerPortalService->getTeamMember($user, $organizationId, $member);

            if ($payload === null) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 404);
            }

            return CustomerResponse::success($payload, trans_message('customer.team_loaded'));
        } catch (Throwable $exception) {
            Log::error('customer.team.member.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'member_id' => $member->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.team_load_error'), 500);
        }
    }

    public function notificationSettings(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getNotificationSettings($user, $organizationId),
                trans_message('customer.notification_settings_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.notification_settings.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.notification_settings_load_error'), 500);
        }
    }

    public function updateNotificationSettings(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            if (!$this->hasPermission($request, 'customer.notification_settings.manage', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $validated = $request->validate([
                'channels' => ['required', 'array'],
                'channels.in_app' => ['required', 'boolean'],
                'channels.email' => ['required', 'boolean'],
                'events' => ['required', 'array'],
                'events.new_contract' => ['required', 'boolean'],
                'events.new_approval' => ['required', 'boolean'],
                'events.issue_waiting_response' => ['required', 'boolean'],
                'events.request_deadline' => ['required', 'boolean'],
                'events.contract_amount_changed' => ['required', 'boolean'],
                'events.new_document' => ['required', 'boolean'],
                'events.request_status_changed' => ['required', 'boolean'],
                'events.project_deadline_changed' => ['required', 'boolean'],
                'events.access_updated' => ['required', 'boolean'],
                'events.finance_risk_detected' => ['required', 'boolean'],
            ]);

            return CustomerResponse::success(
                $this->customerPortalService->updateNotificationSettings($user, $organizationId, $validated),
                trans_message('customer.notification_settings_updated')
            );
        } catch (ValidationException $exception) {
            return CustomerResponse::error(trans_message('customer.validation_failed'), 422, $exception->errors());
        } catch (Throwable $exception) {
            Log::error('customer.notification_settings.update.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.notification_settings_update_error'), 500);
        }
    }
}
