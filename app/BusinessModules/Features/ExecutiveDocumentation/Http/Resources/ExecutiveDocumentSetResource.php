<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExecutiveDocumentSet */
final class ExecutiveDocumentSetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ExecutiveDocumentSet $set */
        $set = $this->resource;
        $workflow = app(ExecutiveDocumentationWorkflowService::class)->forSet($set);

        return [
            'id' => $set->id,
            'organization_id' => $set->organization_id,
            'project_id' => $set->project_id,
            'set_number' => $set->set_number,
            'title' => $set->title,
            'status' => $set->status->value,
            'status_label' => $set->status->label(),
            'status_color' => $set->status->color(),
            'stage_name' => $set->stage_name,
            'zone_name' => $set->zone_name,
            'planned_transmittal_date' => $set->planned_transmittal_date?->format('Y-m-d'),
            'transmitted_at' => $set->transmitted_at?->toIso8601String(),
            'workflow_summary' => $workflow->toArray(),
            'problem_flags' => $workflow->problemFlags,
            'available_actions' => $workflow->availableActions,
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ] : null),
            'documents' => ExecutiveDocumentResource::collection($this->whenLoaded('documents')),
            'transmittal' => $this->whenLoaded('transmittal', fn () => $this->transmittal ? [
                'id' => $this->transmittal->id,
                'transmittal_number' => $this->transmittal->transmittal_number,
                'comment' => $this->transmittal->comment,
                'acknowledged' => $this->transmittal->acknowledged_at !== null,
                'acknowledgement_comment' => $this->transmittal->acknowledgement_comment,
                'transmitted_at' => $this->transmittal->transmitted_at?->toIso8601String(),
                'acknowledged_at' => $this->transmittal->acknowledged_at?->toIso8601String(),
            ] : null),
            'created_at' => $set->created_at?->toIso8601String(),
            'updated_at' => $set->updated_at?->toIso8601String(),
        ];
    }
}
