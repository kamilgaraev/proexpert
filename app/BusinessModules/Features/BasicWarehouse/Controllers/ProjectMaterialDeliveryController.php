<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Resources\ProjectMaterialDeliveryResource;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialStockService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function trans_message;

class ProjectMaterialDeliveryController extends Controller
{
    public function __construct(
        private readonly ProjectMaterialDeliveryService $deliveryService,
        private readonly ProjectMaterialStockService $stockService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->user()->current_organization_id;

            $deliveries = ProjectMaterialDelivery::query()
                ->where('organization_id', $organizationId)
                ->with(['project', 'material.measurementUnit', 'warehouse', 'latestEvent'])
                ->when($request->integer('project_id') > 0, fn ($query) => $query->where('project_id', $request->integer('project_id')))
                ->when($request->integer('material_id') > 0, fn ($query) => $query->where('material_id', $request->integer('material_id')))
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
                ->when($request->filled('source_type'), fn ($query) => $query->where('source_type', $request->string('source_type')->toString()))
                ->orderByRaw('planned_delivery_date is null')
                ->orderBy('planned_delivery_date')
                ->orderByDesc('created_at')
                ->paginate((int) $request->integer('per_page', 20));

            return AdminResponse::success(ProjectMaterialDeliveryResource::collection($deliveries));
        } catch (\Throwable $exception) {
            Log::error('warehouse.project_material_deliveries.index.error', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.project_material_deliveries.errors.load_failed'), 500);
        }
    }

    public function projectStock(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['nullable', 'integer', 'min:1'],
            ]);

            $stock = $this->stockService->getProjectStock(
                (int) $request->user()->current_organization_id,
                isset($validated['project_id']) ? (int) $validated['project_id'] : null
            );

            return AdminResponse::success($stock);
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $exception->errors());
        } catch (\Throwable $exception) {
            Log::error('warehouse.project_material_deliveries.stock.error', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'project_id' => $request->integer('project_id') ?: null,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.project_material_deliveries.errors.load_failed'), 500);
        }
    }

    public function show(Request $request, int $deliveryId): JsonResponse
    {
        try {
            $delivery = $this->findDelivery($request, $deliveryId)
                ->load([
                    'project',
                    'material.measurementUnit',
                    'warehouse',
                    'responsibleUser',
                    'receiverUser',
                    'latestEvent',
                    'events.user',
                ]);

            return AdminResponse::success(new ProjectMaterialDeliveryResource($delivery));
        } catch (\Throwable $exception) {
            Log::error('warehouse.project_material_deliveries.show.error', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'delivery_id' => $deliveryId,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.project_material_deliveries.errors.not_found'), 404);
        }
    }

    public function ship(Request $request, int $deliveryId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'quantity' => ['nullable', 'numeric', 'min:0.001'],
                'responsible_user_id' => ['nullable', 'integer'],
                'notes' => ['nullable', 'string'],
            ]);

            $delivery = $this->findDelivery($request, $deliveryId);
            $updated = $this->deliveryService->ship($delivery, $request->user(), $validated)
                ->load(['project', 'material.measurementUnit', 'warehouse', 'latestEvent']);

            return AdminResponse::success(
                new ProjectMaterialDeliveryResource($updated),
                trans_message('basic_warehouse.project_material_deliveries.shipped')
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('warehouse.project_material_deliveries.ship.error', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'delivery_id' => $deliveryId,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.project_material_deliveries.errors.ship_failed'), 500);
        }
    }

    public function cancel(Request $request, int $deliveryId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'notes' => ['nullable', 'string'],
            ]);

            $delivery = $this->findDelivery($request, $deliveryId);
            $updated = $this->deliveryService->cancel($delivery, $request->user(), $validated['notes'] ?? null)
                ->load(['project', 'material.measurementUnit', 'warehouse', 'latestEvent']);

            return AdminResponse::success(
                new ProjectMaterialDeliveryResource($updated),
                trans_message('basic_warehouse.project_material_deliveries.cancelled')
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('warehouse.project_material_deliveries.cancel.error', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'delivery_id' => $deliveryId,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('basic_warehouse.project_material_deliveries.errors.cancel_failed'), 500);
        }
    }

    private function findDelivery(Request $request, int $deliveryId): ProjectMaterialDelivery
    {
        return ProjectMaterialDelivery::query()
            ->where('organization_id', (int) $request->user()->current_organization_id)
            ->findOrFail($deliveryId);
    }
}
