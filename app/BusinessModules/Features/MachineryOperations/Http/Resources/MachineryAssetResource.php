<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Resources;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryAssetProjection;
use App\BusinessModules\Features\MachineryOperations\Services\MachineryWorkflowPolicy;
use App\BusinessModules\Features\MachineryOperations\Support\MachineryStatusLabel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MachineryAsset */
final class MachineryAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MachineryAsset $asset */
        $asset = $this->resource;
        $view = app(MachineryAssetProjection::class)->project($asset);
        $actor = $request->user();
        $actions = app(MachineryWorkflowPolicy::class)->availableActions(
            $asset,
            $actor instanceof User ? $actor : null,
        );
        $status = (string) $view['status'];
        $machinery = $asset->organizationAsset?->machinery ?? $asset->machinery;
        $currentProject = $asset->organizationAsset?->currentProject ?? $asset->currentProject;
        $operationProfile = $asset->organizationAsset?->operationProfile;

        return [
            ...$view,
            'asset_type' => 'machinery',
            'asset_type_label' => trans_message('machinery_operations.asset_types.machinery'),
            'operational_mode' => $operationProfile?->operational_mode?->value,
            'operation_profile' => $operationProfile ? [
                'operational_mode' => $operationProfile->operational_mode->value,
                'tracks_meter' => $operationProfile->tracks_meter,
                'tracks_fuel' => $operationProfile->tracks_fuel,
                'tracks_production' => $operationProfile->tracks_production,
                'maintenance_enabled' => $operationProfile->maintenance_enabled,
                'meter_unit' => $operationProfile->meter_unit,
            ] : null,
            'status_label' => MachineryStatusLabel::for('asset_statuses', $status),
            'workflow_summary' => [
                'stage' => $status,
                'status' => $status,
                'stage_label' => MachineryStatusLabel::for('asset_statuses', $status),
                'next_action' => $actions[0] ?? null,
                'next_action_label' => $actions === [] ? null : trans_message("machinery_operations.actions.{$actions[0]}"),
                'available_actions' => $actions,
                'blockers' => $this->problemFlags($asset),
                'warnings' => [],
            ],
            'problem_flags' => $this->problemFlags($asset),
            'available_actions' => $actions,
            'linked_entities' => [
                'machinery_id' => $view['machinery_id'],
                'organization_asset_id' => $asset->organization_asset_id,
                'project_id' => $view['current_project_id'],
                'schedule_task_id' => $asset->current_schedule_task_id,
            ],
            'machinery' => $machinery ? [
                'id' => $machinery->id,
                'name' => $machinery->name,
                'code' => $machinery->code,
                'category' => $machinery->category,
            ] : null,
            'current_project' => $currentProject ? [
                'id' => $currentProject->id,
                'name' => $currentProject->name,
            ] : null,
            'current_schedule_task' => $this->whenLoaded('currentScheduleTask', fn () => $asset->currentScheduleTask ? [
                'id' => $asset->currentScheduleTask->id,
                'name' => $asset->currentScheduleTask->name,
            ] : null),
            'current_assignment' => $this->whenLoaded('currentAssignment', fn () => $asset->currentAssignment ? [
                'id' => $asset->currentAssignment->id,
                'asset_id' => $asset->currentAssignment->asset_id,
                'organization_asset_id' => $asset->currentAssignment->organization_asset_id,
                'project_id' => $asset->currentAssignment->project_id,
                'schedule_task_id' => $asset->currentAssignment->schedule_task_id,
                'status' => $asset->currentAssignment->status,
                'status_label' => MachineryStatusLabel::for('assignment_statuses', $asset->currentAssignment->status),
                'planned_start_at' => $asset->currentAssignment->planned_start_at?->toIso8601String(),
                'planned_end_at' => $asset->currentAssignment->planned_end_at?->toIso8601String(),
                'actual_start_at' => $asset->currentAssignment->actual_start_at?->toIso8601String(),
            ] : null),
            'archived_at' => $asset->archived_at?->toIso8601String(),
            'created_at' => $asset->created_at?->toIso8601String(),
            'updated_at' => $asset->updated_at?->toIso8601String(),
        ];
    }

    private function problemFlags(MachineryAsset $asset): array
    {
        $status = app(MachineryWorkflowPolicy::class)->status($asset);
        if ($status === 'unavailable') {
            return [[
                'code' => 'asset_unavailable',
                'severity' => 'warning',
                'message' => trans_message('machinery_operations.problem_flags.asset_unavailable'),
            ]];
        }

        if ($status === 'maintenance') {
            return [[
                'code' => 'asset_in_maintenance',
                'severity' => 'warning',
                'message' => trans_message('machinery_operations.problem_flags.asset_in_maintenance'),
            ]];
        }

        return [];
    }
}
