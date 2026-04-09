<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Responses\CustomerResponse;
use App\Services\Customer\CustomerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

class PortalController extends CustomerController
{
    public function __construct(
        private readonly CustomerPortalService $customerPortalService
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getDashboard($user, $organizationId),
                trans_message('customer.dashboard_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.dashboard.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.dashboard_load_error'), 500);
        }
    }

    public function documents(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            return CustomerResponse::success(
                $this->customerPortalService->getDocuments($organizationId, null, $request->user()),
                trans_message('customer.documents_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.documents.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.documents_load_error'), 500);
        }
    }

    public function approvals(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            return CustomerResponse::success(
                $this->customerPortalService->getApprovals($organizationId, null, $request->user()),
                trans_message('customer.approvals_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.approvals.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.approvals_load_error'), 500);
        }
    }

    public function conversations(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            return CustomerResponse::success(
                $this->customerPortalService->getConversations($organizationId, null, $request->user()),
                trans_message('customer.conversations_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.conversations.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.conversations_load_error'), 500);
        }
    }

    public function notifications(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getNotifications($user, $organizationId, $request->query()),
                trans_message('customer.notifications_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.notifications.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.notifications_load_error'), 500);
        }
    }

    public function profile(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getProfile($user, $organizationId),
                trans_message('customer.profile_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.profile.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.profile_load_error'), 500);
        }
    }

    public function permissions(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getPermissions($user, $organizationId),
                trans_message('customer.permissions_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.permissions.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.permissions_load_error'), 500);
        }
    }

    public function supportIndex(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getSupportRequests($user, $organizationId),
                trans_message('customer.support_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.support.index.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.support_load_error'), 500);
        }
    }

    public function support(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            $validated = $request->validate([
                'subject' => ['required', 'string', 'max:255'],
                'message' => ['required', 'string', 'max:5000'],
                'phone' => ['nullable', 'string', 'max:50'],
            ]);

            $payload = $this->customerPortalService->createSupportRequest(
                $user,
                $organizationId,
                $validated
            );

            return CustomerResponse::success($payload, trans_message('customer.support_created'), 201);
        } catch (ValidationException $exception) {
            return CustomerResponse::error(
                trans_message('customer.support_validation_error'),
                422,
                $exception->errors()
            );
        } catch (Throwable $exception) {
            Log::error('customer.support.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'payload' => $request->except(['message']),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.support_create_error'), 500);
        }
    }

    public function disciplineAnalytics(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getDisciplineAnalytics($user, $organizationId),
                trans_message('customer.dashboard_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.analytics.discipline.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.dashboard_load_error'), 500);
        }
    }
}
