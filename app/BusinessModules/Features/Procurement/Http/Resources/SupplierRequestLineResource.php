<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierRequestLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_request_id' => $this->supplier_request_id,
            'purchase_request_line_id' => $this->purchase_request_line_id,
            'material_id' => $this->material_id,
            'name' => $this->name,
            'quantity' => (float) $this->quantity,
            'unit' => $this->unit,
            'specification' => $this->specification,
            'metadata' => $this->metadata,
        ];
    }
}
