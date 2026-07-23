<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CustomerLegalArchiveDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $workflow = $this->resource->getAttribute('customer_workflow_summary') ?? [];

        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'document_number' => $this->document_number,
            'document_type' => $this->document_type,
            'status' => $this->status,
            'lock_version' => (int) $this->lock_version,
            'document_date' => $this->document_date?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => (int) $this->project->id,
                'name' => $this->project->name,
            ]),
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion === null ? null : $this->version($this->currentVersion)),
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->map(fn ($version) => $this->version($version))->values()),
            'workflow_summary' => $workflow,
            'obligations' => $this->whenLoaded('obligations', fn () => $this->obligations->map(fn ($obligation) => ['id' => $obligation->id, 'title' => $obligation->title, 'status' => $obligation->status, 'due_at' => $obligation->due_at?->toISOString()])->values()),
            'signature_requests' => $this->whenLoaded('signatureRequests', fn () => $this->signatureRequests->where('status', 'pending')->map(fn ($request) => ['id' => $request->id, 'method' => $request->method])->values()),
        ];
    }

    private function version(object $version): array
    {
        return [
            'id' => (int) $version->id,
            'version_number' => $version->version_number,
            'version_label' => $version->version_label,
            'status' => $version->status,
            'processing_status' => $version->processing_status,
            'original_filename' => $version->original_filename,
            'mime_type' => $version->mime_type,
            'size_bytes' => (int) $version->size_bytes,
            'uploaded_at' => $version->uploaded_at?->toISOString(),
        ];
    }
}
