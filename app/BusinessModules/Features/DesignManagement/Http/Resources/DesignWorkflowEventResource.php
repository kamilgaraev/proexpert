<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignWorkflowEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignWorkflowEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignWorkflowEvent $event */
        $event = $this->resource;

        return [
            'id' => $event->id,
            'organization_id' => $event->organization_id,
            'project_id' => $event->project_id,
            'package_id' => $event->package_id,
            'actor_id' => $event->actor_id,
            'actor' => $event->relationLoaded('actor') && $event->actor ? ['id' => (int) $event->actor->id, 'name' => $event->actor->name] : null,
            'action' => $event->action,
            'action_label' => trans_message("design_management.actions.{$event->action}"),
            'from_status' => $event->from_status,
            'from_status_label' => $event->from_status ? trans_message("design_management.statuses.packages.{$event->from_status}") : null,
            'to_status' => $event->to_status,
            'to_status_label' => $event->to_status ? trans_message("design_management.statuses.packages.{$event->to_status}") : null,
            'comment' => $event->comment,
            'metadata' => array_diff_key($event->metadata ?? [], ['release_snapshot' => true]) + ['release_snapshot_available' => is_array($event->metadata['release_snapshot'] ?? null)],
            'created_at' => $event->created_at?->toIso8601String(),
            'updated_at' => $event->updated_at?->toIso8601String(),
        ];
    }
}
