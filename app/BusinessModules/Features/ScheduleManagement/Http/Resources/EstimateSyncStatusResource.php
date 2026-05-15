<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

use App\Models\ProjectSchedule;
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
        $schedule = $this->resource;

        if (!$schedule instanceof ProjectSchedule) {
            return [];
        }

        return [
            'sync_enabled' => $schedule->sync_with_estimate,
            'sync_status' => $schedule->sync_status,
            'last_synced_at' => $schedule->last_synced_at?->toISOString(),
            'needs_sync' => $schedule->needsSync(),
            'estimate_id' => $schedule->estimate_id,
            'estimate_updated_at' => $this->when($schedule->relationLoaded('estimate') && $schedule->estimate, function () use ($schedule) {
                return $schedule->estimate->updated_at?->toISOString();
            }),
            'is_out_of_sync' => $schedule->sync_status === 'out_of_sync',
            'has_conflicts' => $schedule->sync_status === 'conflict',
            'is_synced' => $schedule->sync_status === 'synced',
        ];
    }
}

