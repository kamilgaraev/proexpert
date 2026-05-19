<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationWorkflowService;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
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
        $profile = app(ExecutiveDocumentProfileRegistry::class)->find($document->document_type->value);

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
            'profile' => $profile,
            'work_type' => $document->workType ? [
                'id' => $document->workType->id,
                'name' => $document->workType->name,
                'code' => $document->workType->code,
            ] : null,
            'work_type_id' => $document->work_type_id,
            'work_type_name' => $document->work_type_name,
            'section_name' => $document->section_name,
            'completed_work_id' => $document->completed_work_id,
            'document_date' => $document->document_date?->format('Y-m-d'),
            'copies_count' => $document->copies_count,
            'form_variant' => $document->form_variant,
            'journal_entry_id' => $document->journal_entry_id,
            'journal_entry' => $document->journalEntry ? [
                'id' => $document->journalEntry->id,
                'entry_number' => $document->journalEntry->entry_number,
                'entry_date' => $document->journalEntry->entry_date?->format('Y-m-d'),
                'work_description' => $document->journalEntry->work_description,
            ] : null,
            'inspection_date' => $document->inspection_date?->format('Y-m-d'),
            'participants' => $document->participants,
            'profile_data' => $document->profile_data,
            'signatories' => $document->signatories,
            'metadata' => $document->metadata,
            'workflow_summary' => $workflow->toArray(),
            'problem_flags' => $workflow->problemFlags,
            'available_actions' => $workflow->availableActions,
            'relations' => $this->whenLoaded('relations', fn () => $document->relations->map(static fn ($relation): array => [
                'id' => $relation->id,
                'relation_type' => $relation->relation_type,
                'target_type' => $relation->target_type,
                'target_id' => $relation->target_id,
                'label' => $relation->label,
                'metadata' => $relation->metadata,
            ])->values()),
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
