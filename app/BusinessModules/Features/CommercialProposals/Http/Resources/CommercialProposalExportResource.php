<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Resources;

use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalAmountAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommercialProposalExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canDownload = app(CommercialProposalAmountAccess::class)->forRequest($request, (int) $this->organization_id)
            ->canDownload($this->resource);

        return [
            'id' => $this->id,
            'commercial_proposal_id' => $this->commercial_proposal_id,
            'commercial_proposal_version_id' => $this->commercial_proposal_version_id,
            'format' => $this->format,
            'status' => $this->status,
            'download_url' => $canDownload ? $this->resource->getAttribute('download_url') : null,
            'error_message' => $this->error_message,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
