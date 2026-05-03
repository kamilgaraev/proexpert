<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Landing\Organization;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Organization
 */
class OrganizationSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'verification_status' => $this->verification_status,
        ];
    }
}
