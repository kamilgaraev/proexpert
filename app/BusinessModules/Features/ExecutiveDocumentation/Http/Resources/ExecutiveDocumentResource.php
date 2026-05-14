<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExecutiveDocument */
final class ExecutiveDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ExecutiveDocument $document */
        $document = $this->resource;
        $workflow = app(ExecutiveDocumentationWorkflowService::class)->forDocument($document);

        return [
            'id' => $document->id,
            'organization_id' => $document->organization_id,
            'project_id' => $document->project_id,
            'document_set_id' => $document->document_set_id,
            'document_type' => $document->document_type->value,
            'document_type_label' => $document->document_type->label(),
            'title' => $document->title,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'status_color' => $document->status->color(),
            'work_type_name' => $document->work_type_name,
            'section_name' => $document->section_name,
            'completed_work_id' => $document->completed_work_id,
            'inspection_date' => $document->inspection_date?->format('Y-m-d'),
            'participants' => $document->participants,
            'workflow_summary' => $workflow->toArray(),
            'problem_flags' => $workflow->problemFlags,
            'available_actions' => $workflow->availableActions,
            'versions' => ExecutiveDocumentVersionResource::collection($this->whenLoaded('versions')),
            'remarks' => ExecutiveDocumentRemarkResource::collection($this->whenLoaded('remarks')),
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ] : null),
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }
}
