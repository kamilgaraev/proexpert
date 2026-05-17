<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Http\Resources;

use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDeliveryEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectMaterialDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'status_color' => $this->status?->color(),
            'requested_quantity' => (float) $this->requested_quantity,
            'reserved_quantity' => (float) $this->reserved_quantity,
            'shipped_quantity' => (float) $this->shipped_quantity,
            'accepted_quantity' => (float) $this->accepted_quantity,
            'remaining_to_ship' => $this->remainingQuantityToShip(),
            'remaining_to_accept' => $this->remainingQuantityToAccept(),
            'planned_delivery_date' => $this->planned_delivery_date?->toDateString(),
            'shipped_at' => $this->shipped_at?->toDateTimeString(),
            'delivered_at' => $this->delivered_at?->toDateTimeString(),
            'accepted_at' => $this->accepted_at?->toDateTimeString(),
            'can_receive' => $this->canReceive(),
            'notes' => $this->notes,
            'metadata' => $this->metadata ?? [],
            'project' => $this->whenLoaded('project', fn (): ?array => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ] : null),
            'material' => $this->whenLoaded('material', fn (): ?array => $this->material ? [
                'id' => $this->material->id,
                'name' => $this->material->name,
                'code' => $this->material->code,
                'measurement_unit' => $this->material->relationLoaded('measurementUnit') && $this->material->measurementUnit ? [
                    'id' => $this->material->measurementUnit->id,
                    'name' => $this->material->measurementUnit->name,
                    'short_name' => $this->material->measurementUnit->short_name,
                ] : null,
            ] : null),
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->warehouse ? [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ] : null),
            'linked_entities' => [
                'allocation_id' => $this->warehouse_project_allocation_id,
                'site_request_id' => $this->site_request_id,
                'purchase_request_id' => $this->purchase_request_id,
                'purchase_order_id' => $this->purchase_order_id,
                'outbound_movement_id' => $this->outbound_movement_id,
                'inbound_movement_id' => $this->inbound_movement_id,
            ],
            'responsible_user' => $this->whenLoaded('responsibleUser', fn (): ?array => $this->responsibleUser ? [
                'id' => $this->responsibleUser->id,
                'name' => $this->responsibleUser->name,
            ] : null),
            'receiver_user' => $this->whenLoaded('receiverUser', fn (): ?array => $this->receiverUser ? [
                'id' => $this->receiverUser->id,
                'name' => $this->receiverUser->name,
            ] : null),
            'latest_event' => $this->whenLoaded('latestEvent', fn (): ?array => $this->mapEvent($this->latestEvent)),
            'events' => $this->whenLoaded('events', fn () => $this->events
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
