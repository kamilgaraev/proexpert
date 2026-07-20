<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use App\Services\LegalArchive\LegalArchiveDictionary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalArchiveDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $workflowSummary = $this->resource->getAttribute('api_workflow_summary');
        $problemFlags = is_array($workflowSummary) ? (array) ($workflowSummary['problem_flags'] ?? []) : [];
        $linkedContract = $this->resource->relationLoaded('links')
            ? $this->links->first(static fn ($link): bool => in_array((string) $link->linked_type, ['contract', 'App\\Models\\Contract'], true))
            : null;

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'primary_project_id' => $this->primary_project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'status' => $this->project->status,
            ]),
            'title' => $this->title,
            'document_number' => $this->document_number,
            'document_type' => $this->document_type,
            'document_type_label' => LegalArchiveDictionary::label('types', $this->document_type),
            'type_profile' => $this->resource->getAttribute('api_type_profile') ?? [
                'code' => (string) ($this->type_profile_code ?: $this->document_type),
                'base_code' => (string) $this->document_type,
                'name' => LegalArchiveDictionary::label('types', (string) $this->document_type),
                'label' => LegalArchiveDictionary::label('types', (string) $this->document_type),
            ],
            'status' => $this->status,
            'status_label' => LegalArchiveDictionary::label('statuses', $this->status),
            'source_create_status' => $this->source_create_status,
            'source_create_failed_at' => $this->source_create_failed_at?->toISOString(),
            'create_recovery' => [
                'operation_id' => $this->create_operation_id,
                'status' => $this->source_create_status,
                'retry_action' => $this->source_create_retry_action,
                'attempt_count' => (int) $this->source_create_attempt_count,
                'started_at' => $this->source_create_started_at?->toISOString(),
                'heartbeat_at' => $this->source_create_heartbeat_at?->toISOString(),
                'lease_expires_at' => $this->source_create_lease_expires_at?->toISOString(),
            ],
            'direction' => $this->direction,
            'direction_label' => LegalArchiveDictionary::label('directions', $this->direction),
            'source_system' => $this->source_system,
            'counterparty_name' => $this->counterparty_name,
            'document_date' => $this->document_date?->toDateString(),
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'description' => $this->description,
            'legal_significance_status' => $this->legal_significance_status,
            'legal_significance_status_label' => LegalArchiveDictionary::label(
                'legal_significance_statuses',
                $this->legal_significance_status
            ),
            'edo_status' => $this->edo_status,
            'one_c_status' => $this->one_c_status,
            'retention' => [
                'policy' => $this->retention_policy,
                'basis' => $this->retention_basis,
                'started_at' => $this->retention_started_at?->toISOString(),
                'until' => $this->retention_until?->toISOString(),
                'legal_hold' => (bool) $this->legal_hold,
            ],
            'obligations' => $this->whenLoaded('obligations', fn () => $this->obligations->map(fn ($obligation) => [
                'id' => $obligation->id, 'title' => $obligation->title, 'status' => $obligation->status,
                'due_at' => $obligation->due_at?->toISOString(), 'amount' => $obligation->amount,
                'volume' => $obligation->volume, 'unit' => $obligation->unit,
            ])->values()),
            'lock_version' => (int) $this->lock_version,
            'current_version' => new LegalArchiveDocumentVersionResource($this->whenLoaded('currentVersion')),
            'current_primary_version' => new LegalArchiveDocumentVersionResource($this->whenLoaded('currentVersion')),
            'versions' => LegalArchiveDocumentVersionResource::collection($this->whenLoaded('versions')),
            'files' => LegalArchiveFileResource::collection($this->whenLoaded('files')),
            'signatures' => LegalArchiveSignatureResource::collection($this->whenLoaded('signatures')),
            'links' => LegalArchiveDocumentLinkResource::collection($this->whenLoaded('links')),
            'workflow_summary' => $workflowSummary ?? [
                'status' => 'not_loaded',
                'current_steps' => [],
                'available_action_details' => [],
                'problem_flags' => [],
            ],
            'problem_flags' => $problemFlags,
            'completeness' => is_array($workflowSummary) ? ($workflowSummary['completeness'] ?? null) : null,
            'linked_contract' => $linkedContract === null ? null : [
                'id' => $linkedContract->linked_id,
                'number' => $linkedContract->display_name,
                'status' => data_get($linkedContract->metadata, 'status'),
            ],
            'metadata' => $this->metadata ?? [],
            'created_by_user_id' => $this->created_by_user_id,
            'updated_by_user_id' => $this->updated_by_user_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
