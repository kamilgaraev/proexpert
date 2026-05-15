<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Resources;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MachineryAsset */
final class MachineryAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MachineryAsset $asset */
        $asset = $this->resource;
        $actions = $this->actions($asset->status);

        return [
            'id' => $asset->id,
            'organization_id' => $asset->organization_id,
            'machinery_id' => $asset->machinery_id,
            'current_project_id' => $asset->current_project_id,
            'current_schedule_task_id' => $asset->current_schedule_task_id,
            'asset_code' => $asset->asset_code,
            'name' => $asset->name,
            'inventory_number' => $asset->inventory_number,
            'ownership_type' => $asset->ownership_type,
            'status' => $asset->status,
            'status_label' => trans_message("machinery_operations.asset_statuses.{$asset->status}"),
            'operating_cost_per_hour' => $asset->operating_cost_per_hour,
            'fuel_type' => $asset->fuel_type,
            'fuel_consumption_rate' => $asset->fuel_consumption_rate,
            'meter_hours' => $asset->meter_hours,
            'workflow_summary' => [
                'stage' => $asset->status,
                'status' => $asset->status,
                'stage_label' => trans_message("machinery_operations.asset_statuses.{$asset->status}"),
                'next_action' => $actions[0] ?? null,
                'next_action_label' => $actions === [] ? null : trans_message("machinery_operations.actions.{$actions[0]}"),
                'available_actions' => $actions,
                'blockers' => $this->problemFlags($asset),
                'warnings' => [],
            ],
            'problem_flags' => $this->problemFlags($asset),
            'available_actions' => $actions,
            'linked_entities' => [
                'machinery_id' => $asset->machinery_id,
                'project_id' => $asset->current_project_id,
                'schedule_task_id' => $asset->current_schedule_task_id,
            ],
            'machinery' => $this->whenLoaded('machinery', fn () => $asset->machinery ? [
                'id' => $asset->machinery->id,
                'name' => $asset->machinery->name,
                'code' => $asset->machinery->code,
                'category' => $asset->machinery->category,
            ] : null),
            'current_project' => $this->whenLoaded('currentProject', fn () => $asset->currentProject ? [
                'id' => $asset->currentProject->id,
                'name' => $asset->currentProject->name,
            ] : null),
            'current_schedule_task' => $this->whenLoaded('currentScheduleTask', fn () => $asset->currentScheduleTask ? [
                'id' => $asset->currentScheduleTask->id,
                'name' => $asset->currentScheduleTask->name,
            ] : null),
            'metadata' => $asset->metadata,
            'archived_at' => $asset->archived_at?->toIso8601String(),
            'created_at' => $asset->created_at?->toIso8601String(),
            'updated_at' => $asset->updated_at?->toIso8601String(),
        ];
    }

    private function actions(string $status): array
    {
        return match ($status) {
            'available' => ['assign', 'maintenance', 'unavailable', 'archive'],
            'assigned' => ['start_operation', 'return_available', 'maintenance'],
            'in_operation' => ['return_available', 'maintenance', 'unavailable'],
            'maintenance' => ['return_available'],
            'unavailable' => ['return_available', 'maintenance', 'archive'],
            default => [],
        };
    }

    private function problemFlags(MachineryAsset $asset): array
    {
        if ($asset->status === 'unavailable') {
            return [[
                'code' => 'asset_unavailable',
                'severity' => 'warning',
                'message' => trans_message('machinery_operations.problem_flags.asset_unavailable'),
            ]];
        }

        if ($asset->status === 'maintenance') {
            return [[
                'code' => 'asset_in_maintenance',
                'severity' => 'warning',
                'message' => trans_message('machinery_operations.problem_flags.asset_in_maintenance'),
            ]];
        }

        return [];
    }
}
