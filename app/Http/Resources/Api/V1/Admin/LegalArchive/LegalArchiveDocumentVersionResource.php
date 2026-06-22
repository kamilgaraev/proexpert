<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use App\Services\LegalArchive\LegalArchiveDictionary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalArchiveDocumentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $downloadUrl = $this->resource->getAttribute('download_url');

        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'version_number' => $this->version_number,
            'version_label' => $this->version_label,
            'is_current' => (bool) $this->is_current,
            'status' => $this->status,
            'status_label' => LegalArchiveDictionary::label('version_statuses', $this->status),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'content_hash' => $this->content_hash,
            'metadata_hash' => $this->metadata_hash,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'uploaded_at' => $this->uploaded_at?->toISOString(),
            'download_url' => $this->when($downloadUrl !== null, $downloadUrl),
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
