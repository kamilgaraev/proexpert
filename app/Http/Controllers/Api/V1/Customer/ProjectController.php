<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Responses\CustomerResponse;
use App\Models\Project;
use App\Services\Customer\CustomerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class ProjectController extends CustomerController
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

            return CustomerResponse::success(
                $this->customerPortalService->getProjects($organizationId, $user),
                trans_message('customer.projects_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.projects.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.projects_load_error'), 500);
        }
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getProject($organizationId, $project, $user),
                trans_message('customer.project_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_load_error'), 500);
        }
    }

    public function documents(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getDocuments($organizationId, $project, $user),
                trans_message('customer.documents_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.documents.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.documents_load_error'), 500);
        }
    }

    public function approvals(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getApprovals($organizationId, $project, $user),
                trans_message('customer.approvals_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.approvals.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.approvals_load_error'), 500);
        }
    }

    public function conversations(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getConversations($organizationId, $project, $user),
                trans_message('customer.conversations_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.conversations.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.conversations_load_error'), 500);
        }
    }

    public function workspace(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getProjectWorkspace($organizationId, $project, $user),
                trans_message('customer.project_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.workspace.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_load_error'), 500);
        }
    }

    public function timeline(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getProjectTimeline($organizationId, $project, $user),
                trans_message('customer.project_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.timeline.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_load_error'), 500);
        }
    }

    public function risks(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $user = $request->user();

            if (!$this->canAccessProject($project, $organizationId, $user)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getProjectRisks($organizationId, $project, $user),
                trans_message('customer.project_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.risks.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.project_load_error'), 500);
        }
    }
}
