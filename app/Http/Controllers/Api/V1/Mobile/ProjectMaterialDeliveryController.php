<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Features\BasicWarehouse\Http\Resources\ProjectMaterialDeliveryResource;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialStockService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
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
            $user = $request->user();
            $organizationId = (int) $user->current_organization_id;

            $deliveries = ProjectMaterialDelivery::query()
                ->where('organization_id', $organizationId)
                ->with(['project', 'material.measurementUnit', 'warehouse', 'projectWarehouse', 'latestEvent'])
                ->when(!$user->isOrganizationAdmin($organizationId), function ($query) use ($user): void {
                    $query->whereHas('project.users', function ($usersQuery) use ($user): void {
                        $usersQuery->where('users.id', $user->id);
                    });
                })
                ->when($request->integer('project_id') > 0, fn ($query) => $query->where('project_id', $request->integer('project_id')))
                ->whereNotIn('status', ['accepted', 'cancelled'])
                ->orderByRaw('planned_delivery_date is null')
                ->orderBy('planned_delivery_date')
                ->orderByDesc('created_at')
                ->get();

            return MobileResponse::success([
                'items' => ProjectMaterialDeliveryResource::collection($deliveries),
            ]);
        } catch (\Throwable $exception) {
            Log::error('mobile.project_material_deliveries.index.error', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('basic_warehouse.project_material_deliveries.errors.load_failed'), 500);
        }
    }

    public function projectStock(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['nullable', 'integer', 'min:1'],
            ]);

            $user = $request->user();
            $stock = $this->stockService->getProjectStock(
                (int) $user->current_organization_id,
                isset($validated['project_id']) ? (int) $validated['project_id'] : null,
                $user
            );

            return MobileResponse::success($stock);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('errors.validation_failed'), 422, $exception->errors());
        } catch (\Throwable $exception) {
            Log::error('mobile.project_material_deliveries.stock.error', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'project_id' => $request->integer('project_id') ?: null,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('basic_warehouse.project_material_deliveries.errors.load_failed'), 500);
        }
    }

    public function show(Request $request, int $deliveryId): JsonResponse
    {
        try {
            $delivery = $this->findDeliveryForUser($request, $deliveryId)
                ->load(['project', 'material.measurementUnit', 'warehouse', 'projectWarehouse', 'responsibleUser', 'receiverUser', 'events.user']);

            return MobileResponse::success(new ProjectMaterialDeliveryResource($delivery));
        } catch (\Throwable $exception) {
            Log::error('mobile.project_material_deliveries.show.error', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'delivery_id' => $deliveryId,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('basic_warehouse.project_material_deliveries.errors.not_found'), 404);
        }
    }

    public function receive(Request $request, int $deliveryId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'quantity' => ['required', 'numeric', 'min:0.001'],
                'notes' => ['nullable', 'string'],
            ]);

            $delivery = $this->findDeliveryForUser($request, $deliveryId);
            $updated = $this->deliveryService
                ->receive($delivery, $request->user(), (float) $validated['quantity'], $validated['notes'] ?? null)
                ->load(['project', 'material.measurementUnit', 'warehouse', 'projectWarehouse', 'latestEvent']);

            return MobileResponse::success(
                new ProjectMaterialDeliveryResource($updated),
                trans_message('basic_warehouse.project_material_deliveries.received')
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.project_material_deliveries.receive.error', [
                'organization_id' => $request->user()?->current_organization_id,
                'user_id' => $request->user()?->id,
                'delivery_id' => $deliveryId,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('basic_warehouse.project_material_deliveries.errors.receive_failed'), 500);
        }
    }

    private function findDeliveryForUser(Request $request, int $deliveryId): ProjectMaterialDelivery
    {
        $user = $request->user();
        $organizationId = (int) $user->current_organization_id;

        return ProjectMaterialDelivery::query()
            ->where('organization_id', $organizationId)
            ->when(!$user->isOrganizationAdmin($organizationId), function ($query) use ($user): void {
                $query->whereHas('project.users', function ($usersQuery) use ($user): void {
                    $usersQuery->where('users.id', $user->id);
                });
            })
            ->findOrFail($deliveryId);
    }
}
