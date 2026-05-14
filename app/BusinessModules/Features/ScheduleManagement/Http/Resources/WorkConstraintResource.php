<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Http\Resources;

use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkConstraint */
final class WorkConstraintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $constraint = $this->resource;

        if (!$constraint instanceof WorkConstraint) {
            return [];
        }

        return [
            'id' => $constraint->id,
            'lookahead_plan_task_id' => $constraint->lookahead_plan_task_id,
            'schedule_task_id' => $constraint->schedule_task_id,
            'constraint_type' => $constraint->constraint_type,
            'title' => $constraint->title,
            'description' => $constraint->description,
            'severity' => $constraint->severity,
            'status' => $constraint->status,
            'due_date' => $constraint->due_date?->format('Y-m-d'),
            'resolved_at' => $constraint->resolved_at?->toIso8601String(),
            'overridden_at' => $constraint->overridden_at?->toIso8601String(),
            'override_reason' => $constraint->override_reason,
            'linked_entity' => $constraint->metadata['linked_action'] ?? $constraint->metadata['linked_entity'] ?? null,
        ];
    }
}
