<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Resources;

use App\BusinessModules\Features\MachineryOperations\Models\AssetRequest;
use App\BusinessModules\Features\MachineryOperations\Support\MachineryStatusLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssetRequest */
final class AssetRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'schedule_task_id' => $this->schedule_task_id,
            'requested_by_user_id' => $this->requested_by_user_id,
            'approved_by_user_id' => $this->approved_by_user_id,
            'organization_asset_id' => $this->organization_asset_id,
            'status' => $this->status,
            'status_label' => MachineryStatusLabel::for('request_statuses', $this->status),
            'priority' => $this->priority,
            'priority_label' => MachineryStatusLabel::for('priorities', $this->priority),
            'planned_start_at' => $this->planned_start_at?->toIso8601String(),
            'planned_end_at' => $this->planned_end_at?->toIso8601String(),
            'required_profile' => $this->required_profile,
            'purpose' => $this->purpose,
            'decision_comment' => $this->decision_comment,
            'events_count' => $this->events_count ?? null,
            'project' => $this->whenLoaded('project'),
            'organization_asset' => $this->whenLoaded('organizationAsset'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
