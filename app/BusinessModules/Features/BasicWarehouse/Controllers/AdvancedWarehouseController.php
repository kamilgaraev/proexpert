<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\AbcXyzAnalysisRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\AutoReorderRuleRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ForecastRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\ReserveAssetRequest;
use App\BusinessModules\Features\BasicWarehouse\Http\Requests\TurnoverAnalyticsRequest;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\AutoReorderRule;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdvancedWarehouseController extends Controller
{
    public function __construct(
        protected WarehouseService $warehouseService
    ) {
    }

    public function turnoverAnalytics(TurnoverAnalyticsRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $analytics = $this->warehouseService->getTurnoverAnalyticsReport($organizationId, $request->validated());

            return AdminResponse::success($analytics);
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::turnoverAnalytics error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'filters' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.analytics.turnover_error'), 500);
        }
    }

    public function forecast(ForecastRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $forecast = $this->warehouseService->getForecastData($organizationId, $request->validated());

            return AdminResponse::success($forecast);
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::forecast error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'filters' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.analytics.forecast_error'), 500);
        }
    }

    public function abcXyzAnalysis(AbcXyzAnalysisRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $analysis = $this->warehouseService->getAbcXyzAnalysis($organizationId, $request->validated());

            return AdminResponse::success($analysis);
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::abcXyzAnalysis error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'filters' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.analytics.abc_xyz_error'), 500);
        }
    }

    public function reserve(ReserveAssetRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $validated = $request->validated();

            $result = $this->warehouseService->reserveAssets(
                $organizationId,
                (int) $validated['warehouse_id'],
                (int) $validated['material_id'],
                (float) $validated['quantity'],
                [
                    'project_id' => $validated['project_id'] ?? null,
                    'user_id' => $request->user()->id,
                    'expires_hours' => $validated['expires_hours'] ?? 24,
                    'reason' => $validated['reason'] ?? null,
                ]
            );

            $reservation = AssetReservation::query()
                ->where('organization_id', $organizationId)
                ->where('id', (int) ($result['reservation_id'] ?? 0))
                ->with(['material', 'warehouse', 'project', 'reservedBy'])
                ->first();

            return AdminResponse::success(
                $reservation ? $this->makeReservationPayload($reservation) : null,
                trans_message('basic_warehouse.reservation.created'),
                201
            );
        } catch (\InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::reserve error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'payload' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.reservation.create_error'), 500);
        }
    }

    public function unreserve(Request $request, int $reservationId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $reservation = AssetReservation::query()
                ->where('organization_id', $organizationId)
                ->findOrFail($reservationId);

            $this->warehouseService->unreserveAssets($reservation->id);

            return AdminResponse::success(null, trans_message('basic_warehouse.reservation.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.reservation.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::unreserve error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'reservation_id' => $reservationId,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.reservation.delete_error'), 500);
        }
    }

    public function reservations(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

            $reservationsQuery = AssetReservation::query()
                ->where('organization_id', $organizationId)
                ->with(['material', 'warehouse', 'project', 'reservedBy'])
                ->when($request->filled('warehouse_id'), fn (Builder $query) => $query->where('warehouse_id', (int) $request->input('warehouse_id')));

            $this->applyReservationStatusFilter($reservationsQuery, $request->input('status'));

            $reservations = $reservationsQuery
                ->orderByDesc('reserved_at')
                ->paginate($perPage);

            $reservations->setCollection(
                $reservations->getCollection()->map(
                    fn (AssetReservation $reservation) => $this->makeReservationPayload($reservation)
                )
            );

            return AdminResponse::success($reservations);
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::reservations error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'filters' => $request->only(['status', 'warehouse_id', 'page', 'per_page']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.reservation.list_error'), 500);
        }
    }

    public function createAutoReorderRule(AutoReorderRuleRequest $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $validated = $request->validated();
            $result = $this->warehouseService->createAutoReorderRule(
                $organizationId,
                (int) $validated['material_id'],
                $validated
            );

            $rule = AutoReorderRule::query()
                ->where('organization_id', $organizationId)
                ->with(['material', 'warehouse', 'defaultSupplier'])
                ->findOrFail((int) $result['rule_id']);

            $messageKey = $result['action'] === 'created'
                ? 'basic_warehouse.auto_reorder.created'
                : 'basic_warehouse.auto_reorder.updated';

            return AdminResponse::success(
                $this->makeAutoReorderRulePayload($rule),
                trans_message($messageKey),
                $result['action'] === 'created' ? 201 : 200
            );
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::createAutoReorderRule error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'payload' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.auto_reorder.create_error'), 500);
        }
    }

    public function autoReorderRules(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

            $rules = AutoReorderRule::query()
                ->where('organization_id', $organizationId)
                ->with(['material', 'warehouse', 'defaultSupplier'])
                ->when($request->filled('warehouse_id'), fn (Builder $query) => $query->where('warehouse_id', (int) $request->input('warehouse_id')))
                ->when($request->boolean('active_only'), fn (Builder $query) => $query->where('is_active', true))
                ->orderByDesc('updated_at')
                ->paginate($perPage);

            $rules->setCollection(
                $rules->getCollection()->map(
                    fn (AutoReorderRule $rule) => $this->makeAutoReorderRulePayload($rule)
                )
            );

            return AdminResponse::success($rules);
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::autoReorderRules error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'filters' => $request->only(['warehouse_id', 'active_only', 'page', 'per_page']),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.auto_reorder.list_error'), 500);
        }
    }

    public function updateAutoReorderRule(AutoReorderRuleRequest $request, int $ruleId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $rule = AutoReorderRule::query()
                ->where('organization_id', $organizationId)
                ->with(['material', 'warehouse', 'defaultSupplier'])
                ->findOrFail($ruleId);

            $validated = $request->validated();
            $candidateWarehouseId = (int) ($validated['warehouse_id'] ?? $rule->warehouse_id);
            $candidateMaterialId = (int) ($validated['material_id'] ?? $rule->material_id);

            $duplicateExists = AutoReorderRule::query()
                ->where('organization_id', $organizationId)
                ->where('warehouse_id', $candidateWarehouseId)
                ->where('material_id', $candidateMaterialId)
                ->where('id', '!=', $rule->id)
                ->exists();

            if ($duplicateExists) {
                return AdminResponse::error(trans_message('basic_warehouse.auto_reorder.duplicate_rule'), 422);
            }

            $rule->fill($validated);
            $rule->save();

            return AdminResponse::success(
                $this->makeAutoReorderRulePayload($rule->fresh(['material', 'warehouse', 'defaultSupplier'])),
                trans_message('basic_warehouse.auto_reorder.updated')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.auto_reorder.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::updateAutoReorderRule error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'rule_id' => $ruleId,
                'payload' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.auto_reorder.update_error'), 500);
        }
    }

    public function deleteAutoReorderRule(Request $request, int $ruleId): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $rule = AutoReorderRule::query()
                ->where('organization_id', $organizationId)
                ->findOrFail($ruleId);

            $rule->delete();

            return AdminResponse::success(null, trans_message('basic_warehouse.auto_reorder.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('basic_warehouse.auto_reorder.not_found'), 404);
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::deleteAutoReorderRule error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'rule_id' => $ruleId,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.auto_reorder.delete_error'), 500);
        }
    }

    public function checkAutoReorder(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->current_organization_id;

        try {
            $warehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;
            $result = $this->warehouseService->checkAutoReorder($organizationId);
            $items = collect($result['orders'] ?? [])
                ->when(
                    $warehouseId !== null,
                    fn ($collection) => $collection->where('warehouse_id', $warehouseId)
                )
                ->map(fn (array $item) => $this->makeAutoReorderCheckItemPayload($item))
                ->values()
                ->all();

            return AdminResponse::success([
                'items_to_reorder' => $items,
                'total_items' => count($items),
                'checked_at' => $result['checked_at'] ?? now()->toDateTimeString(),
                'summary' => [
                    'critical_items' => collect($items)->where('priority', 'critical')->count(),
                    'high_items' => collect($items)->where('priority', 'high')->count(),
                    'normal_items' => collect($items)->where('priority', 'normal')->count(),
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('AdvancedWarehouseController::checkAutoReorder error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'warehouse_id' => $request->input('warehouse_id'),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.auto_reorder.check_error'), 500);
        }
    }

    private function applyReservationStatusFilter(Builder $query, ?string $status): void
    {
        if ($status === null || $status === '') {
            return;
        }

        match ($status) {
            'active' => $query->where('status', AssetReservation::STATUS_ACTIVE)
                ->where('expires_at', '>', now()),
            'expired' => $query->where('status', AssetReservation::STATUS_ACTIVE)
                ->where('expires_at', '<=', now()),
            'released' => $query->where('status', AssetReservation::STATUS_CANCELLED),
            'consumed' => $query->where('status', AssetReservation::STATUS_FULFILLED),
            default => $query->where('status', $status),
        };
    }

    private function mapReservationStatus(AssetReservation $reservation): string
    {
        if ($reservation->status === AssetReservation::STATUS_ACTIVE && $reservation->expires_at?->isPast()) {
            return 'expired';
        }

        return match ($reservation->status) {
            AssetReservation::STATUS_CANCELLED => 'released',
            AssetReservation::STATUS_FULFILLED => 'consumed',
            default => 'active',
        };
    }

    private function makeReservationPayload(AssetReservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'warehouse_id' => $reservation->warehouse_id,
            'material_id' => $reservation->material_id,
            'quantity' => (float) $reservation->quantity,
            'status' => $this->mapReservationStatus($reservation),
            'project_id' => $reservation->project_id,
            'reserved_by_id' => $reservation->reserved_by,
            'expires_at' => optional($reservation->expires_at)?->toDateTimeString(),
            'reason' => $reservation->reason,
            'created_at' => optional($reservation->created_at)?->toDateTimeString(),
            'material' => $reservation->material ? [
                'id' => $reservation->material->id,
                'name' => $reservation->material->name,
                'unit' => $reservation->material->unit?->name ?? $reservation->material->measurement_unit ?? 'шт',
            ] : null,
            'warehouse' => $reservation->warehouse ? [
                'id' => $reservation->warehouse->id,
                'name' => $reservation->warehouse->name,
            ] : null,
            'project' => $reservation->project ? [
                'id' => $reservation->project->id,
                'name' => $reservation->project->name,
            ] : null,
            'reserved_by' => $reservation->reservedBy ? [
                'id' => $reservation->reservedBy->id,
                'name' => trim(
                    implode(' ', array_filter([
                        $reservation->reservedBy->first_name ?? null,
                        $reservation->reservedBy->last_name ?? null,
                    ]))
                ) ?: ($reservation->reservedBy->name ?? $reservation->reservedBy->email ?? ('#' . $reservation->reserved_by)),
            ] : null,
        ];
    }

    private function makeAutoReorderRulePayload(AutoReorderRule $rule): array
    {
        return [
            'id' => $rule->id,
            'warehouse_id' => $rule->warehouse_id,
            'material_id' => $rule->material_id,
            'min_stock_level' => (float) $rule->min_stock,
            'max_stock_level' => (float) $rule->max_stock,
            'reorder_point' => (float) $rule->reorder_point,
            'reorder_quantity' => (float) $rule->reorder_quantity,
            'supplier_id' => $rule->default_supplier_id,
            'is_active' => (bool) $rule->is_active,
            'last_triggered_at' => optional($rule->last_ordered_at)?->toDateTimeString(),
            'last_checked_at' => optional($rule->last_checked_at)?->toDateTimeString(),
            'notes' => $rule->notes,
            'material' => $rule->material ? [
                'id' => $rule->material->id,
                'name' => $rule->material->name,
                'unit' => $rule->material->unit?->name ?? $rule->material->measurement_unit ?? 'шт',
            ] : null,
            'warehouse' => $rule->warehouse ? [
                'id' => $rule->warehouse->id,
                'name' => $rule->warehouse->name,
            ] : null,
            'supplier' => $rule->defaultSupplier ? [
                'id' => $rule->defaultSupplier->id,
                'name' => $rule->defaultSupplier->name,
            ] : null,
        ];
    }

    private function makeAutoReorderCheckItemPayload(array $item): array
    {
        $priority = (int) ($item['priority'] ?? 0);

        return [
            'material_id' => (int) $item['material_id'],
            'material_name' => $item['material_name'] ?? '',
            'warehouse_id' => (int) $item['warehouse_id'],
            'warehouse_name' => $item['warehouse_name'] ?? '',
            'current_stock' => (float) ($item['current_stock'] ?? 0),
            'reorder_point' => (float) ($item['reorder_point'] ?? 0),
            'reorder_quantity' => (float) ($item['recommended_order_quantity'] ?? 0),
            'default_supplier_id' => $item['supplier_id'] ?? null,
            'estimated_delivery_date' => null,
            'estimated_stock_out_days' => $item['estimated_stock_out_days'] ?? null,
            'priority' => $priority >= 8 ? 'critical' : ($priority >= 5 ? 'high' : 'normal'),
        ];
    }
}
