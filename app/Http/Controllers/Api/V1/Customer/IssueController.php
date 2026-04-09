<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Responses\CustomerResponse;
use App\Models\CustomerIssue;
use App\Services\Customer\CustomerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

class IssueController extends CustomerController
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

            if (!$this->hasPermission($request, 'customer.issues.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getIssues($organizationId, $request->query(), $user),
                trans_message('customer.issues_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.issues.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.issues_load_error'), 500);
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

            if (!$this->hasPermission($request, 'customer.issues.manage', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'issue_reason' => ['required', 'string', 'max:120'],
                'body' => ['required', 'string', 'max:5000'],
                'project_id' => ['nullable', 'integer', 'exists:projects,id'],
                'contract_id' => ['nullable', 'integer', 'exists:contracts,id'],
                'performance_act_id' => ['nullable', 'integer', 'exists:contract_performance_acts,id'],
                'file_id' => ['nullable', 'integer', 'exists:files,id'],
                'due_date' => ['nullable', 'date_format:Y-m-d'],
                'attachments' => ['nullable', 'array'],
                'attachments.*.label' => ['nullable', 'string', 'max:255'],
                'attachments.*.url' => ['nullable', 'string', 'max:2048'],
            ]);

            return CustomerResponse::success(
                $this->customerPortalService->createIssue($user, $organizationId, $validated),
                trans_message('customer.issue_created'),
                201
            );
        } catch (ValidationException $exception) {
            return CustomerResponse::error(trans_message('customer.validation_failed'), 422, $exception->errors());
        } catch (Throwable $exception) {
            Log::error('customer.issue.create.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.issue_create_error'), 500);
        }
    }

    public function show(Request $request, CustomerIssue $issue): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->hasPermission($request, 'customer.issues.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $payload = $this->customerPortalService->getIssue($organizationId, $issue, $user);

            if ($payload === null) {
                return CustomerResponse::error(trans_message('customer.issue_not_found'), 404);
            }

            return CustomerResponse::success($payload, trans_message('customer.issue_loaded'));
        } catch (Throwable $exception) {
            Log::error('customer.issue.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'issue_id' => $issue->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.issue_load_error'), 500);
        }
    }

    public function addComment(Request $request, CustomerIssue $issue): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            if (!$this->hasPermission($request, 'customer.issues.manage', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $validated = $request->validate([
                'body' => ['required', 'string', 'max:5000'],
                'attachments' => ['nullable', 'array'],
                'attachments.*.label' => ['nullable', 'string', 'max:255'],
                'attachments.*.url' => ['nullable', 'string', 'max:2048'],
            ]);

            $payload = $this->customerPortalService->addIssueComment($user, $organizationId, $issue, $validated);

            if ($payload === null) {
                return CustomerResponse::error(trans_message('customer.issue_not_found'), 404);
            }

            return CustomerResponse::success($payload, trans_message('customer.comment_created'), 201);
        } catch (ValidationException $exception) {
            return CustomerResponse::error(trans_message('customer.validation_failed'), 422, $exception->errors());
        } catch (Throwable $exception) {
            Log::error('customer.issue.comment.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'issue_id' => $issue->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.comment_create_error'), 500);
        }
    }

    public function resolve(Request $request, CustomerIssue $issue): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$user) {
                return CustomerResponse::error(trans_message('customer.unauthorized'), 401);
            }

            if (!$this->hasPermission($request, 'customer.issues.manage', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            $validated = $request->validate([
                'status' => ['required', 'string', 'in:resolved,rejected'],
            ]);

            $payload = $this->customerPortalService->resolveIssue($user, $organizationId, $issue, $validated['status']);

            if ($payload === null) {
                return CustomerResponse::error(trans_message('customer.issue_not_found'), 404);
            }

            return CustomerResponse::success($payload, trans_message('customer.issue_resolved'));
        } catch (ValidationException $exception) {
            return CustomerResponse::error(trans_message('customer.validation_failed'), 422, $exception->errors());
        } catch (Throwable $exception) {
            Log::error('customer.issue.resolve.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'issue_id' => $issue->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.issue_resolve_error'), 500);
        }
    }
}
