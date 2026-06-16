<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommercialProposalFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'commercial_proposal_id' => $this->commercial_proposal_id,
            'commercial_proposal_version_id' => $this->commercial_proposal_version_id,
            'version_id' => $this->commercial_proposal_version_id,
            'category' => $this->category,
            'original_name' => $this->original_name,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'size' => $this->size_bytes,
            'download_url' => $this->getAttribute('download_url'),
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn (): ?array => $this->uploadedBy === null ? null : [
                'id' => $this->uploadedBy->id,
                'name' => $this->uploadedBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
