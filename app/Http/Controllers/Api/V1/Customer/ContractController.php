<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Responses\CustomerResponse;
use App\Models\Contract;
use App\Models\Project;
use App\Services\Customer\CustomerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class ContractController extends CustomerController
{
    public function __construct(
        private readonly CustomerPortalService $customerPortalService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            return CustomerResponse::success(
                $this->customerPortalService->getContracts($organizationId),
                trans_message('customer.contracts_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.contracts.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.contracts_load_error'), 500);
        }
    }

    public function projectContracts(Request $request, Project $project): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);

            if (!$this->canAccessProject($project, $organizationId)) {
                return CustomerResponse::error(trans_message('customer.project_not_found'), 404);
            }

            return CustomerResponse::success(
                $this->customerPortalService->getContracts($organizationId, $project),
                trans_message('customer.contracts_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.project.contracts.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'project_id' => $project->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.contracts_load_error'), 500);
        }
    }

    public function show(Request $request, Contract $contract): JsonResponse
    {
        try {
            $organizationId = $this->resolveOrganizationId($request);
            $payload = $this->customerPortalService->getContract($organizationId, $contract);

            if ($payload === null) {
                return CustomerResponse::error(trans_message('customer.contract_not_found'), 404);
            }

            return CustomerResponse::success(
                $payload,
                trans_message('customer.contract_loaded')
            );
        } catch (Throwable $exception) {
            Log::error('customer.contract.failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'contract_id' => $contract->id ?? null,
                'error' => $exception->getMessage(),
            ]);

            return CustomerResponse::error(trans_message('customer.contract_load_error'), 500);
        }
    }
}
