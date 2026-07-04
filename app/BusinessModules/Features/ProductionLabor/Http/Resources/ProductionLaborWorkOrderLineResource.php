<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Http\Resources;

use App\BusinessModules\Features\ProductionLabor\Models\ProductionLaborWorkOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductionLaborWorkOrderLine */
final class ProductionLaborWorkOrderLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProductionLaborWorkOrderLine $line */
        $line = $this->resource;
        $planned = (float) $line->planned_quantity;
        $accepted = (float) $line->accepted_quantity;
        $metadata = is_array($line->metadata) ? $line->metadata : [];
        $safetySummary = $metadata['safety_requirements_summary'] ?? null;
        $safetyBlockersCount = is_array($safetySummary)
            ? count($safetySummary['blockers'] ?? [])
            : (int) ($metadata['safety_blockers_count'] ?? 0);

        return [
            'id' => $line->id,
            'work_order_id' => $line->work_order_id,
            'work_type_id' => $line->work_type_id,
            'estimate_item_id' => $line->estimate_item_id,
            'schedule_task_id' => $line->schedule_task_id,
            'name' => $line->name,
            'unit' => $line->unit,
            'planned_quantity' => $planned,
            'accepted_quantity' => $accepted,
            'remaining_quantity' => max(round($planned - $accepted, 4), 0),
            'unit_rate' => (float) $line->unit_rate,
            'planned_hours' => (float) $line->planned_hours,
            'hour_rate' => (float) $line->hour_rate,
            'pay_basis' => $line->pay_basis,
            'requires_safety_permit' => $line->requires_safety_permit,
            'work_category' => $metadata['work_category'] ?? $metadata['safety_work_category'] ?? null,
            'safety_admission_status' => $metadata['safety_admission_status'] ?? null,
            'safety_blockers_count' => $safetyBlockersCount,
            'safety_requirements_summary' => $safetySummary,
            'metadata' => $line->metadata,
        ];
    }
}
