<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseProjectAllocation;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProjectMaterialDeliveryService
{
    public function __construct(
        private readonly ProjectWarehouseService $projectWarehouseService
    ) {
    }

    public function createFromAllocation(
        WarehouseProjectAllocation $allocation,
        User $user,
        array $data = []
    ): ProjectMaterialDelivery {
        return DB::transaction(function () use ($allocation, $user, $data): ProjectMaterialDelivery {
            $delivery = ProjectMaterialDelivery::query()->firstOrNew([
                'warehouse_project_allocation_id' => $allocation->id,
            ]);

            $quantity = (float) ($data['quantity'] ?? $allocation->allocated_quantity);

            $delivery->fill([
                'organization_id' => $allocation->organization_id,
                'project_id' => $allocation->project_id,
                'material_id' => $allocation->material_id,
                'warehouse_id' => $allocation->warehouse_id,
                'warehouse_project_allocation_id' => $allocation->id,
                'source_type' => 'warehouse',
                'status' => ProjectMaterialDeliveryStatusEnum::RESERVED,
                'requested_quantity' => $quantity,
                'reserved_quantity' => $quantity,
                'responsible_user_id' => $user->id,
                'planned_delivery_date' => $data['planned_delivery_date'] ?? $delivery->planned_delivery_date,
                'notes' => $data['notes'] ?? $delivery->notes,
                'metadata' => array_filter([
                    'created_from' => 'warehouse_project_allocation',
                    'allocation_id' => $allocation->id,
                ]),
            ]);

            $this->assertDeliveryCanBeSaved($delivery);
            $delivery->save();

            $this->recordEvent(
                $delivery,
                $user,
                $delivery->wasRecentlyCreated ? 'created_from_allocation' : 'updated_from_allocation',
                null,
                $delivery->status,
                $quantity,
                $data['notes'] ?? null
            );

            return $delivery->load(['project', 'material.measurementUnit', 'warehouse', 'latestEvent']);
        });
    }

    public function createOrLinkFromSiteRequest(
        SiteRequest $siteRequest,
        User $user,
        ?PurchaseRequest $purchaseRequest = null
    ): ProjectMaterialDelivery {
        return DB::transaction(function () use ($siteRequest, $user, $purchaseRequest): ProjectMaterialDelivery {
            if (!$siteRequest->material_id) {
                throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.material_required'));
            }

            $delivery = ProjectMaterialDelivery::query()->firstOrNew([
                'site_request_id' => $siteRequest->id,
            ]);

            $delivery->fill([
                'organization_id' => $siteRequest->organization_id,
                'project_id' => $siteRequest->project_id,
                'material_id' => $siteRequest->material_id,
                'site_request_id' => $siteRequest->id,
                'purchase_request_id' => $purchaseRequest?->id ?? $delivery->purchase_request_id,
                'source_type' => 'purchase',
                'status' => $delivery->exists
                    ? $delivery->status
                    : ProjectMaterialDeliveryStatusEnum::PROCESSING,
                'requested_quantity' => (float) ($siteRequest->material_quantity ?? 0),
                'responsible_user_id' => $purchaseRequest?->assigned_to ?? $delivery->responsible_user_id,
                'planned_delivery_date' => $siteRequest->required_date,
                'notes' => $siteRequest->notes ?? $delivery->notes,
                'metadata' => array_filter([
                    'created_from' => 'site_request',
                    'site_request_id' => $siteRequest->id,
                ]),
            ]);

            $this->assertDeliveryCanBeSaved($delivery);
            $delivery->save();

            $this->recordEvent(
                $delivery,
                $user,
                $delivery->wasRecentlyCreated ? 'created_from_site_request' : 'linked_site_request',
                null,
                $delivery->status,
                (float) $delivery->requested_quantity
            );

            return $delivery->load(['project', 'material.measurementUnit', 'latestEvent']);
        });
    }

    public function linkPurchaseRequest(
        ProjectMaterialDelivery $delivery,
        PurchaseRequest $purchaseRequest,
        User $user
    ): ProjectMaterialDelivery {
        return DB::transaction(function () use ($delivery, $purchaseRequest, $user): ProjectMaterialDelivery {
            $this->assertSameOrganization($delivery, (int) $purchaseRequest->organization_id);

            $delivery->purchase_request_id = $purchaseRequest->id;
            $delivery->source_type = 'purchase';
            $delivery->status = ProjectMaterialDeliveryStatusEnum::PROCESSING;
            $this->assertDeliveryCanBeSaved($delivery);
            $delivery->save();

            $this->recordEvent($delivery, $user, 'linked_purchase_request', null, $delivery->status);

            return $delivery->refresh();
        });
    }

    public function linkPurchaseOrder(
        ProjectMaterialDelivery $delivery,
        PurchaseOrder $purchaseOrder,
        User $user
    ): ProjectMaterialDelivery {
        return DB::transaction(function () use ($delivery, $purchaseOrder, $user): ProjectMaterialDelivery {
            $this->assertSameOrganization($delivery, (int) $purchaseOrder->organization_id);

            $delivery->purchase_order_id = $purchaseOrder->id;
            $delivery->source_type = 'purchase';
            $delivery->planned_delivery_date = $purchaseOrder->delivery_date ?? $delivery->planned_delivery_date;
            $this->assertDeliveryCanBeSaved($delivery);
            $delivery->save();

            $this->recordEvent($delivery, $user, 'linked_purchase_order', null, $delivery->status);

            return $delivery->refresh();
        });
    }

    public function ship(ProjectMaterialDelivery $delivery, User $user, array $data): ProjectMaterialDelivery
    {
        return DB::transaction(function () use ($delivery, $user, $data): ProjectMaterialDelivery {
            $quantity = (float) ($data['quantity'] ?? $delivery->remainingQuantityToShip());
            $newShippedQuantity = (float) $delivery->shipped_quantity + $quantity;
            $expectedQuantity = max((float) $delivery->reserved_quantity, (float) $delivery->requested_quantity);

            if ($quantity <= 0 || $newShippedQuantity > $expectedQuantity) {
                throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.invalid_shipped_quantity'));
            }

            $fromStatus = $delivery->status;
            $movement = $this->projectWarehouseService->shipToProject(
                $delivery,
                $user,
                $quantity,
                isset($data['responsible_user_id']) ? (int) $data['responsible_user_id'] : null,
                $data['notes'] ?? null
            );

            $delivery->forceFill([
                'shipped_quantity' => $newShippedQuantity,
                'outbound_movement_id' => $movement->id,
                'project_warehouse_id' => $movement->to_warehouse_id,
                'status' => ProjectMaterialDeliveryStatusEnum::IN_TRANSIT,
                'shipped_at' => $delivery->shipped_at ?? now(),
                'responsible_user_id' => $data['responsible_user_id'] ?? $delivery->responsible_user_id ?? $user->id,
                'notes' => $data['notes'] ?? $delivery->notes,
            ]);
            $this->assertDeliveryCanBeSaved($delivery);
            $delivery->save();

            $this->recordEvent($delivery, $user, 'shipped', $fromStatus, $delivery->status, $quantity, $data['notes'] ?? null);

            return $delivery->refresh();
        });
    }

    public function receive(
        ProjectMaterialDelivery $delivery,
        User $user,
        float $quantity,
        ?string $notes = null
    ): ProjectMaterialDelivery {
        return DB::transaction(function () use ($delivery, $user, $quantity, $notes): ProjectMaterialDelivery {
            if (!$delivery->canReceive()) {
                throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.cannot_receive'));
            }

            $newAcceptedQuantity = (float) $delivery->accepted_quantity + $quantity;

            if ($quantity <= 0 || $newAcceptedQuantity > (float) $delivery->shipped_quantity) {
                throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.invalid_accepted_quantity'));
            }

            $fromStatus = $delivery->status;
            $movement = $this->projectWarehouseService->receiveOnProject($delivery, $user, $quantity, $notes);

            $delivery->forceFill([
                'accepted_quantity' => $newAcceptedQuantity,
                'inbound_movement_id' => $movement->id,
                'project_warehouse_id' => $movement->warehouse_id,
                'receiver_user_id' => $user->id,
                'delivered_at' => $delivery->delivered_at ?? now(),
                'accepted_at' => $newAcceptedQuantity >= (float) $delivery->shipped_quantity ? now() : null,
                'status' => $newAcceptedQuantity >= (float) $delivery->shipped_quantity
                    ? ProjectMaterialDeliveryStatusEnum::ACCEPTED
                    : ProjectMaterialDeliveryStatusEnum::PARTIALLY_DELIVERED,
                'notes' => $notes ?? $delivery->notes,
            ])->save();

            $this->recordEvent($delivery, $user, 'received', $fromStatus, $delivery->status, $quantity, $notes);

            return $delivery->refresh();
        });
    }

    public function cancel(ProjectMaterialDelivery $delivery, User $user, ?string $notes = null): ProjectMaterialDelivery
    {
        return DB::transaction(function () use ($delivery, $user, $notes): ProjectMaterialDelivery {
            if ($delivery->status?->isFinal()) {
                throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.final_status'));
            }

            $fromStatus = $delivery->status;
            $delivery->status = ProjectMaterialDeliveryStatusEnum::CANCELLED;
            $delivery->notes = $notes ?? $delivery->notes;
            $delivery->save();

            $this->recordEvent($delivery, $user, 'cancelled', $fromStatus, $delivery->status, null, $notes);

            return $delivery->refresh();
        });
    }

    public function buildProjectSummary(int $organizationId, int $projectId): array
    {
        $query = ProjectMaterialDelivery::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId);

        return [
            'total' => (clone $query)->count(),
            'in_progress' => (clone $query)->whereNotIn('status', [
                ProjectMaterialDeliveryStatusEnum::ACCEPTED->value,
                ProjectMaterialDeliveryStatusEnum::CANCELLED->value,
            ])->count(),
            'in_transit' => (clone $query)->where('status', ProjectMaterialDeliveryStatusEnum::IN_TRANSIT->value)->count(),
            'accepted' => (clone $query)->where('status', ProjectMaterialDeliveryStatusEnum::ACCEPTED->value)->count(),
            'problem' => (clone $query)->where('status', ProjectMaterialDeliveryStatusEnum::PROBLEM->value)->count(),
            'requested_quantity' => (float) (clone $query)->sum('requested_quantity'),
            'shipped_quantity' => (float) (clone $query)->sum('shipped_quantity'),
            'accepted_quantity' => (float) (clone $query)->sum('accepted_quantity'),
        ];
    }

    private function assertDeliveryCanBeSaved(ProjectMaterialDelivery $delivery): void
    {
        if (!$delivery->organization_id || !$delivery->project_id || !$delivery->material_id) {
            throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.required_context'));
        }

        if (
            !$delivery->warehouse_project_allocation_id
            && !$delivery->site_request_id
            && !$delivery->purchase_request_id
            && !$delivery->purchase_order_id
        ) {
            throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.source_required'));
        }
    }

    private function assertSameOrganization(ProjectMaterialDelivery $delivery, int $organizationId): void
    {
        if ((int) $delivery->organization_id !== $organizationId) {
            throw new DomainException(trans_message('basic_warehouse.project_material_deliveries.errors.organization_mismatch'));
        }
    }

    private function recordEvent(
        ProjectMaterialDelivery $delivery,
        User $user,
        string $eventType,
        ?ProjectMaterialDeliveryStatusEnum $fromStatus = null,
        ?ProjectMaterialDeliveryStatusEnum $toStatus = null,
        ?float $quantity = null,
        ?string $notes = null
    ): void {
        $delivery->events()->create([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus?->value,
            'quantity' => $quantity,
            'notes' => $notes,
            'occurred_at' => now(),
        ]);
    }
}
