<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateSyncStatusResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sync_enabled' => $this->sync_with_estimate,
            'sync_status' => $this->sync_status,
            'last_synced_at' => $this->last_synced_at?->toISOString(),
            'needs_sync' => $this->needsSync(),
            'estimate_id' => $this->estimate_id,
            'estimate_updated_at' => $this->when($this->relationLoaded('estimate') && $this->estimate, function () {
                return $this->estimate->updated_at?->toISOString();
            }),
            'is_out_of_sync' => $this->sync_status === 'out_of_sync',
            'has_conflicts' => $this->sync_status === 'conflict',
            'is_synced' => $this->sync_status === 'synced',
        ];
    }
}

