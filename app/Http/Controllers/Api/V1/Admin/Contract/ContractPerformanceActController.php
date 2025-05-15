<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller; // Убедимся, что базовый контроллер существует и используется
use App\Services\Contract\ContractPerformanceActService;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\StoreContractPerformanceActRequest;
use App\Http\Requests\Api\V1\Admin\Contract\PerformanceAct\UpdateContractPerformanceActRequest;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActCollection;
use Illuminate\Http\Request; // Для $request->input()
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth; // Для Auth::user()
use Exception;

class ContractPerformanceActController extends Controller
{
    protected ContractPerformanceActService $actService;

    public function __construct(ContractPerformanceActService $actService)
    {
        $this->actService = $actService;
        // Здесь можно добавить middleware для авторизации, если требуется
        // Например, $this->middleware('can:viewAny,App\Models\ContractPerformanceAct::class')->only('index');
        // Или $this->authorizeResource(App\Models\ContractPerformanceAct::class, 'performance_act');
        // Учитывая вложенность, авторизация может быть более сложной.
    }

    /**
     * Display a listing of the resource for a specific contract.
     */
    public function index(Request $request, int $contractId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id', 1); // Временно

        try {
            $acts = $this->actService->getAllActsForContract($contractId, $organizationId);
            return new ContractPerformanceActCollection($acts);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to retrieve performance acts', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Store a newly created resource in storage for a specific contract.
     */
    public function store(StoreContractPerformanceActRequest $request, int $contractId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_creation', 1); // Временно из StoreRequest или напрямую

        try {
            $actDTO = $request->toDto();
            $act = $this->actService->createActForContract($contractId, $organizationId, $actDTO);
            return (new ContractPerformanceActResource($act))
                    ->response()
                    ->setStatusCode(Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to create performance act', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, int $contractId, int $actId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_show', 1); // Временно

        try {
            $act = $this->actService->getActById($actId, $contractId, $organizationId);
            if (!$act) {
                return response()->json(['message' => 'Performance act not found'], Response::HTTP_NOT_FOUND);
            }
            return new ContractPerformanceActResource($act);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to retrieve performance act', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateContractPerformanceActRequest $request, int $contractId, int $actId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_update', 1); // Временно
        
        try {
            $actDTO = $request->toDto();
            $act = $this->actService->updateAct($actId, $contractId, $organizationId, $actDTO);
            return new ContractPerformanceActResource($act);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to update performance act', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $contractId, int $actId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_destroy', 1); // Временно

        try {
            $this->actService->deleteAct($actId, $contractId, $organizationId);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to delete performance act', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
} 