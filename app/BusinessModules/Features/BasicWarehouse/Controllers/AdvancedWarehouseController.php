<?php

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\TurnoverAnalyticsRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ForecastRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\AbcXyzAnalysisRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ReserveAssetRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\AutoReorderRuleRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\AutoReorderRule;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvancedWarehouseController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService
    ) {}

    public function turnoverAnalytics(TurnoverAnalyticsRequest $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        $analytics = $this->warehouseService->getTurnoverAnalytics($organizationId, $request->validated());
        
        return AdminResponse::success($analytics);
    }

    public function forecast(ForecastRequest $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        $forecast = $this->warehouseService->getForecastData($organizationId, $request->validated());
        
        return AdminResponse::success($forecast);
    }

    public function abcXyzAnalysis(AbcXyzAnalysisRequest $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        $analysis = $this->warehouseService->getAbcXyzAnalysis($organizationId, $request->validated());
        
        return AdminResponse::success($analysis);
    }

    public function reserve(ReserveAssetRequest $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        $validated = $request->validated();
        
        $result = $this->warehouseService->reserveAssets(
            $organizationId,
            $validated['warehouse_id'],
            $validated['material_id'],
            $validated['quantity'],
            [
                'project_id' => $validated['project_id'] ?? null,
                'user_id' => $request->user()->id,
                'expires_hours' => $validated['expires_hours'] ?? 24,
                'reason' => $validated['reason'] ?? null,
            ]
        );
        
        return AdminResponse::success($result, trans('warehouse::messages.assets_reserved'), 201);
    }

    public function unreserve(Request $request, int $reservationId): JsonResponse
    {
        $this->warehouseService->unreserveAssets($reservationId);
        
        return AdminResponse::success(null, trans('warehouse::messages.reservation_cancelled'));
    }

    public function reservations(Request $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        
        $reservations = AssetReservation::where('organization_id', $organizationId)
            ->with(['material', 'warehouse', 'project', 'reservedBy'])
            ->when($request->input('status'), function ($q, $status) {
                $q->where('status', $status);
            })
            ->when($request->input('warehouse_id'), function ($q, $warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        
        return AdminResponse::success($reservations);
    }

    public function createAutoReorderRule(AutoReorderRuleRequest $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        $validated = $request->validated();
        
        $result = $this->warehouseService->createAutoReorderRule(
            $organizationId,
            $validated['material_id'],
            $validated
        );
        
        $message = $result['action'] === 'created' 
            ? trans('warehouse::messages.auto_reorder_rule_created')
            : trans('warehouse::messages.auto_reorder_rule_updated');
        
        return AdminResponse::success($result, $message, $result['action'] === 'created' ? 201 : 200);
    }

    public function autoReorderRules(Request $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        
        $rules = AutoReorderRule::where('organization_id', $organizationId)
            ->with(['material', 'warehouse', 'defaultSupplier'])
            ->when($request->input('warehouse_id'), function ($q, $warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            })
            ->when($request->boolean('active_only'), function ($q) {
                $q->where('is_active', true);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        
        return AdminResponse::success($rules);
    }

    public function checkAutoReorder(Request $request): JsonResponse
    {
        $organizationId = $request->user()->current_organization_id;
        $result = $this->warehouseService->checkAutoReorder($organizationId);
        
        return AdminResponse::success($result);
    }
}
