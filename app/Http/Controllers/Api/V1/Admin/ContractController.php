<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contract\ContractService;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractRequest; // Создадим позже
use App\Http\Requests\Api\V1\Admin\Contract\UpdateContractRequest; // Создадим позже
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource; // Создадим позже
use App\Http\Resources\Api\V1\Admin\Contract\ContractCollection; // Создадим позже
use App\Models\Organization; // Для получения ID организации, например, из аутентифицированного пользователя
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Exception;

class ContractController extends Controller
{
    protected ContractService $contractService;

    public function __construct(ContractService $contractService)
    {
        $this->contractService = $contractService;
        // Middleware для авторизации можно добавить здесь или в роутах
        // $this->middleware('can:viewAny,App\Models\Contract')->only('index');
        // $this->middleware('can:create,App\Models\Contract')->only('store');
        // $this->middleware('can:view,contract')->only('show');
        // $this->middleware('can:update,contract')->only('update');
        // $this->middleware('can:delete,contract')->only('destroy');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Предполагаем, что ID организации берется из текущего пользователя
        // или другого источника, например, если админ может выбирать организацию.
        // Для простоты, пока захардкодим или будем ожидать в запросе.
        // $organizationId = Auth::user()->organization_id; 
        $organizationId = $request->input('organization_id', 1); // Временно, для примера

        $filters = $request->only(['contractor_id', 'project_id', 'status', 'type', 'number', 'date_from', 'date_to']);
        $sortBy = $request->input('sort_by', 'date');
        $sortDirection = $request->input('sort_direction', 'desc');
        $perPage = $request->input('per_page', 15);

        $contracts = $this->contractService->getAllContracts($organizationId, $perPage, $filters, $sortBy, $sortDirection);
        return new ContractCollection($contracts);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreContractRequest $request)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_creation', 1); // Временно

        try {
            $contractDTO = $request->toDto(); // Метод toDto() нужно будет добавить в StoreContractRequest
            $contract = $this->contractService->createContract($organizationId, $contractDTO);
            return (new ContractResource($contract))
                    ->response()
                    ->setStatusCode(Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to create contract', 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(int $contractId) // Laravel автоматически внедрит модель, если использовать Route Model Binding (Contract $contract)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = request()->input('organization_id_for_show', 1); // Временно

        $contract = $this->contractService->getContractById($contractId, $organizationId);
        if (!$contract) {
            return response()->json(['message' => 'Contract not found'], Response::HTTP_NOT_FOUND);
        }
        return new ContractResource($contract);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateContractRequest $request, int $contractId) // (UpdateContractRequest $request, Contract $contract)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_update', 1); // Временно
        
        try {
            $contractDTO = $request->toDto(); // Метод toDto() нужно будет добавить в UpdateContractRequest
            $contract = $this->contractService->updateContract($contractId, $organizationId, $contractDTO);
            return new ContractResource($contract);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to update contract', 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $contractId) // (Contract $contract)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = request()->input('organization_id_for_destroy', 1); // Временно

        try {
            $this->contractService->deleteContract($contractId, $organizationId);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to delete contract', 'error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
} 