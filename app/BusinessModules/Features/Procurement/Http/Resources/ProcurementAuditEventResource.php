<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProcurementAuditEvent */
class ProcurementAuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'event_type' => $this->event_type->value,
            'actor_id' => $this->actor_id,
            'supplier_party_id' => $this->supplier_party_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'payload' => $this->payload,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
            ] : null),
            'supplier_party' => $this->whenLoaded(
                'supplierParty',
                fn () => $this->supplierParty ? new SupplierPartyResource($this->supplierParty) : null
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
