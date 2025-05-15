<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contractor\ContractorService;
use App\Http\Requests\Api\V1\Admin\Contractor\StoreContractorRequest;
use App\Http\Requests\Api\V1\Admin\Contractor\UpdateContractorRequest;
use App\Http\Resources\Api\V1\Admin\Contractor\ContractorResource;
use App\Http\Resources\Api\V1\Admin\Contractor\ContractorCollection;
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
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id', 1); // Временно

        $filters = $request->only(['name', 'inn']); // Добавьте фильтры по мере необходимости
        $sortBy = $request->input('sort_by', 'name');
        $sortDirection = $request->input('sort_direction', 'asc');
        $perPage = $request->input('per_page', 15);

        $contractors = $this->contractorService->getAllContractors($organizationId, $perPage, $filters, $sortBy, $sortDirection);
        return new ContractorCollection($contractors);
    }

    public function store(StoreContractorRequest $request)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_creation', 1); // Временно

        try {
            $contractorDTO = $request->toDto();
            $contractor = $this->contractorService->createContractor($organizationId, $contractorDTO);
            return (new ContractorResource($contractor))
                    ->response()
                    ->setStatusCode(Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to create contractor', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST); // Или HTTP_INTERNAL_SERVER_ERROR
        }
    }

    public function show(int $contractorId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = request()->input('organization_id_for_show', 1); // Временно

        $contractor = $this->contractorService->getContractorById($contractorId, $organizationId);
        if (!$contractor) {
            return response()->json(['message' => 'Contractor not found'], Response::HTTP_NOT_FOUND);
        }
        return new ContractorResource($contractor);
    }

    public function update(UpdateContractorRequest $request, int $contractorId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_update', 1); // Временно
        
        try {
            $contractorDTO = $request->toDto();
            $contractor = $this->contractorService->updateContractor($contractorId, $organizationId, $contractorDTO);
            return new ContractorResource($contractor);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to update contractor', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST); // Или HTTP_INTERNAL_SERVER_ERROR
        }
    }

    public function destroy(int $contractorId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = request()->input('organization_id_for_destroy', 1); // Временно

        try {
            $this->contractorService->deleteContractor($contractorId, $organizationId);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to delete contractor', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
} 