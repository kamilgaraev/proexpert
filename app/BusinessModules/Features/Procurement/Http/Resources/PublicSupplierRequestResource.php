<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupplierRequest */
class PublicSupplierRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $snapshot = is_array($this->supplier_snapshot) ? $this->supplier_snapshot : [];

        return [
            'request_number' => $this->request_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'expires_at' => $this->public_token_expires_at?->toIso8601String(),
            'can_submit' => $this->canReceivePublicProposal(),
            'organization' => $this->whenLoaded('organization', fn () => [
                'name' => $this->organization?->name,
            ]),
            'supplier' => [
                'name' => $snapshot['display_name']
                    ?? $this->supplier?->name
                    ?? $this->externalSupplierContact?->name,
                'contact_person' => $snapshot['contact_person']
                    ?? $this->externalSupplierContact?->contact_person,
            ],
            'comment' => $this->comment,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(static fn ($line): array => [
                'id' => $line->id,
                'name' => $line->name,
                'quantity' => (float) $line->quantity,
                'unit' => $line->unit,
                'specification' => $line->specification,
            ])->values()),
        ];
    }
}
