<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Resources;

use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDeliveryEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMaterialDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProjectMaterialDelivery $delivery */
        $delivery = $this->resource;

        return [
            'id' => $delivery->id,
            'source_type' => $delivery->source_type,
            'status' => $delivery->status?->value,
            'status_label' => $delivery->status?->label(),
            'status_color' => $delivery->status?->color(),
            'requested_quantity' => (float) $delivery->requested_quantity,
            'reserved_quantity' => (float) $delivery->reserved_quantity,
            'shipped_quantity' => (float) $delivery->shipped_quantity,
            'accepted_quantity' => (float) $delivery->accepted_quantity,
            'used_quantity' => $delivery->usedQuantity(),
            'available_quantity' => $delivery->availableQuantity(),
            'remaining_to_ship' => $delivery->remainingQuantityToShip(),
            'remaining_to_accept' => $delivery->remainingQuantityToAccept(),
            'planned_delivery_date' => $delivery->planned_delivery_date?->toDateString(),
            'shipped_at' => $delivery->shipped_at?->toDateTimeString(),
            'delivered_at' => $delivery->delivered_at?->toDateTimeString(),
            'accepted_at' => $delivery->accepted_at?->toDateTimeString(),
            'can_receive' => $delivery->canReceive(),
            'notes' => $delivery->notes,
            'metadata' => $delivery->metadata ?? [],
            'project' => $this->whenLoaded('project', fn (): ?array => $delivery->project ? [
                'id' => $delivery->project->id,
                'name' => $delivery->project->name,
            ] : null),
            'material' => $this->whenLoaded('material', fn (): ?array => $delivery->material ? [
                'id' => $delivery->material->id,
                'name' => $delivery->material->name,
                'code' => $delivery->material->code,
                'measurement_unit' => $delivery->material->relationLoaded('measurementUnit') && $delivery->material->measurementUnit ? [
                    'id' => $delivery->material->measurementUnit->id,
                    'name' => $delivery->material->measurementUnit->name,
                    'short_name' => $delivery->material->measurementUnit->short_name,
                ] : null,
            ] : null),
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $delivery->warehouse ? [
                'id' => $delivery->warehouse->id,
                'name' => $delivery->warehouse->name,
            ] : null),
            'linked_entities' => [
                'allocation_id' => $delivery->warehouse_project_allocation_id,
                'site_request_id' => $delivery->site_request_id,
                'purchase_request_id' => $delivery->purchase_request_id,
                'purchase_order_id' => $delivery->purchase_order_id,
                'outbound_movement_id' => $delivery->outbound_movement_id,
                'inbound_movement_id' => $delivery->inbound_movement_id,
            ],
            'responsible_user' => $this->whenLoaded('responsibleUser', fn (): ?array => $delivery->responsibleUser ? [
                'id' => $delivery->responsibleUser->id,
                'name' => $delivery->responsibleUser->name,
            ] : null),
            'receiver_user' => $this->whenLoaded('receiverUser', fn (): ?array => $delivery->receiverUser ? [
                'id' => $delivery->receiverUser->id,
                'name' => $delivery->receiverUser->name,
            ] : null),
            'latest_event' => $this->whenLoaded('latestEvent', fn (): ?array => $this->mapEvent($delivery->latestEvent)),
            'events' => $this->whenLoaded('events', fn () => $delivery->events
                ->map(fn (ProjectMaterialDeliveryEvent $event): array => $this->mapEvent($event))
                ->values()
                ->all()),
        ];
    }

    private function mapEvent(?ProjectMaterialDeliveryEvent $event): ?array
    {
        if (!$event) {
            return null;
        }

        return [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'quantity' => $event->quantity === null ? null : (float) $event->quantity,
            'notes' => $event->notes,
            'metadata' => $event->metadata ?? [],
            'occurred_at' => $event->occurred_at?->toDateTimeString(),
            'user' => $event->relationLoaded('user') && $event->user ? [
                'id' => $event->user->id,
                'name' => $event->user->name,
            ] : null,
        ];
    }
}
