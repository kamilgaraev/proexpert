<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Responses\CustomerResponse;
use App\Models\CustomerRequest;
use App\Services\Customer\CustomerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

class CustomerRequestController extends CustomerController
{
    public function __construct(
        private readonly CustomerPortalService $customerPortalService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'customer.requests.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getRequests($organizationId, $request->query()),
                trans_message('customer.requests_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.requests.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.requests_load_error'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            if (!$this->hasPermission($request, 'customer.requests.manage', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'request_type' => ['required', 'string', 'max:120'],
                'body' => ['required', 'string', 'max:5000'],
                'project_id' => ['nullable', 'integer', 'exists:projects,id'],
                'contract_id' => ['nullable', 'integer', 'exists:contracts,id'],
                'due_date' => ['nullable', 'date_format:Y-m-d'],
                'attachments' => ['nullable', 'array'],
                'attachments.*.label' => ['nullable', 'string', 'max:255'],
                'attachments.*.url' => ['nullable', 'string', 'max:2048'],
            ]);

            return CustomerResponse::success(
                $this->customerPortalService->createRequest($user, $organizationId, $validated),
                trans_message('customer.request_created'),
                201
            );
        } catch (ValidationException $exception) {
            return CustomerResponse::error(trans_message('customer.validation_failed'), 422, $exception->errors());
        } catch (Throwable $exception) {
            Log::error('customer.request.create.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.request_create_error'), 500);
        }
    }

    public function show(Request $request, CustomerRequest $requestModel): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'customer.requests.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $payload = $this->customerPortalService->getRequest($organizationId, $requestModel);

            if ($payload === null) {
                return CustomerResponse::error(trans_message('customer.request_not_found'), 404);
            }

            return CustomerResponse::success($payload, trans_message('customer.request_loaded'));
        } catch (Throwable $exception) {
            Log::error('customer.request.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'request_id' => $requestModel->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.request_load_error'), 500);
        }
    }

    public function addComment(Request $request, CustomerRequest $requestModel): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            if (!$this->hasPermission($request, 'customer.requests.manage', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $validated = $request->validate([
                'body' => ['required', 'string', 'max:5000'],
                'attachments' => ['nullable', 'array'],
                'attachments.*.label' => ['nullable', 'string', 'max:255'],
                'attachments.*.url' => ['nullable', 'string', 'max:2048'],
            ]);

            $payload = $this->customerPortalService->addRequestComment($user, $organizationId, $requestModel, $validated);

            if ($payload === null) {
                return CustomerResponse::error(trans_message('customer.request_not_found'), 404);
            }

            return CustomerResponse::success($payload, trans_message('customer.comment_created'), 201);
        } catch (ValidationException $exception) {
            return CustomerResponse::error(trans_message('customer.validation_failed'), 422, $exception->errors());
        } catch (Throwable $exception) {
            Log::error('customer.request.comment.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'request_id' => $requestModel->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.comment_create_error'), 500);
        }
    }
}
