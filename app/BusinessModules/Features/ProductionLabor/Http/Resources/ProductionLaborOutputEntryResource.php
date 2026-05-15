<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Http\Resources;

use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborOutputEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductionLaborOutputEntry */
final class ProductionLaborOutputEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductionLaborOutputEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'work_order_id' => $entry->work_order_id,
            'work_order_line_id' => $entry->work_order_line_id,
            'project_id' => $entry->project_id,
            'schedule_task_id' => $entry->schedule_task_id,
            'work_date' => $entry->work_date?->toDateString(),
            'quantity' => (float) $entry->quantity,
            'hours' => (float) $entry->hours,
            'status' => $entry->status,
            'status_label' => trans_message("production_labor.output_statuses.{$entry->status}"),
            'workflow_summary' => [
                'stage' => $entry->status,
                'status' => $entry->status,
                'stage_label' => trans_message("production_labor.output_statuses.{$entry->status}"),
                'available_actions' => [],
                'blockers' => [],
                'warnings' => [],
            ],
            'problem_flags' => [],
            'available_actions' => [],
            'comment' => $entry->comment,
            'line' => $this->whenLoaded('line', fn () => $entry->line ? [
                'id' => $entry->line->id,
                'name' => $entry->line->name,
                'unit' => $entry->line->unit,
            ] : null),
            'work_order' => $this->whenLoaded('workOrder', fn () => $entry->workOrder ? [
                'id' => $entry->workOrder->id,
                'order_number' => $entry->workOrder->order_number,
                'title' => $entry->workOrder->title,
            ] : null),
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
