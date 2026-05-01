<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierPartyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'display_name' => $this->display_name,
            'contact' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'tax_id' => $this->tax_id,
            'snapshot' => $this->snapshot,
        ];
    }
}
