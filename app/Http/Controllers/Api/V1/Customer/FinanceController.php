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

class FinanceController extends CustomerController
{
    public function __construct(
        private readonly CustomerPortalService $customerPortalService
    ) {
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->hasPermission($request, 'customer.finance.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getFinanceSummary($organizationId),
                trans_message('customer.finance_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.finance.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.finance_load_error'), 500);
        }
    }

    public function projectSummary(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->canAccessProject($project, $organizationId)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            if (!$this->hasPermission($request, 'customer.finance.view', $organizationId)) {
                return CustomerResponse::error(trans_message('customer.forbidden'), 403);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getProjectFinanceSummary($organizationId, $project),
                trans_message('customer.finance_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.finance.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.finance_load_error'), 500);
        }
    }
}
