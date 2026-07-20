<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalArchiveWorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'document_id' => (int) $this->document_id,
            'document_version_id' => (int) $this->document_version_id,
            'status' => (string) $this->status,
            'lock_version' => (int) $this->lock_version,
            'template' => [
                'id' => (int) $this->template_id,
                'version' => (int) $this->template_version,
                'snapshot_hash' => (string) $this->snapshot_hash,
            ],
            'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(static fn ($step): array => [
                'id' => (int) $step->id,
                'key' => (string) $step->step_key,
                'label' => (string) $step->label,
                'status' => (string) $step->status,
                'sequence' => (int) $step->sequence,
                'actor_type' => (string) $step->actor_type,
                'actor_reference' => (string) $step->actor_reference,
                'lock_version' => (int) $step->lock_version,
                'due_at' => $step->due_at?->toAtomString(),
            ])->values()->all()),
            'submitted_at' => $this->submitted_at?->toAtomString(),
            'due_at' => $this->due_at?->toAtomString(),
            'completed_at' => $this->completed_at?->toAtomString(),
        ];
    }
}
