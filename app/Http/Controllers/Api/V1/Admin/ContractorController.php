<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contractor\ContractorService;
use App\Http\Requests\Api\V1\Admin\Contractor\StoreContractorRequest;
use App\Http\Requests\Api\V1\Admin\Contractor\UpdateContractorRequest;
use App\Http\Resources\Api\V1\Admin\Contractor\ContractorResource;
use App\Http\Resources\Api\V1\Admin\Contractor\ContractorCollection;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Exception;

class ContractorController extends Controller
{
    protected ContractorService $contractorService;

    public function __construct(ContractorService $contractorService)
    {
        $this->contractorService = $contractorService;
        // $this->middleware('can:viewAny,App\Models\Contractor::class')->only('index');
        // ... (другие middleware авторизации)
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
        }
        $filters = $request->only(['name', 'inn']);
        $sortBy = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $perPage = $request->input('per_page', 15);

        $contractors = $this->contractorService->getAllContractors($organizationId, $perPage, $filters, $sortBy, $sortDirection);
        return new ContractorCollection($contractors);
    }

    public function store(StoreContractorRequest $request)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
        }
        try {
            $contractorDTO = $request->toDto();
            $contractor = $this->contractorService->createContractor($organizationId, $contractorDTO);
            return AdminResponse::success(new ContractorResource($contractor), null, Response::HTTP_CREATED);
        } catch (Exception $e) {
            return AdminResponse::error(trans_message('contract.contractor_create_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }

    public function show(int $contractorId, Request $request)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
        }
        $contractor = $this->contractorService->getContractorById($contractorId, $organizationId);
        if (!$contractor) {
            return AdminResponse::error(trans_message('contract.contractor_not_found'), Response::HTTP_NOT_FOUND);
        }
        return new ContractorResource($contractor);
    }

    public function update(UpdateContractorRequest $request, int $contractorId)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
        }
        try {
            $contractorDTO = $request->toDto();
            $contractor = $this->contractorService->updateContractor($contractorId, $organizationId, $contractorDTO);
            return new ContractorResource($contractor);
        } catch (Exception $e) {
            return AdminResponse::error(trans_message('contract.contractor_update_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }

    public function destroy(int $contractorId, Request $request)
    {
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        if (!$organizationId) {
            return AdminResponse::error(trans_message('contract.organization_context_missing'), 400);
        }
        try {
            $this->contractorService->deleteContractor($contractorId, $organizationId);
            return AdminResponse::success(null,(trans_message('contract.contractor_deleted')), Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return AdminResponse::error(trans_message('contract.contractor_delete_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }
} 