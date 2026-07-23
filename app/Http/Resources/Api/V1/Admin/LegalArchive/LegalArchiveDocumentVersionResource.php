<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use App\Services\LegalArchive\LegalArchiveDictionary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function trans_message;

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
            'processing_status' => $this->processing_status,
            'processing_status_label' => trans_message(
                'legal_archive.processing_statuses.'.(string) $this->processing_status,
            ),
            'status_label' => LegalArchiveDictionary::label('version_statuses', $this->status),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'content_hash' => $this->content_hash,
            'preview_available' => $this->processing_status === 'ready'
                && in_array($this->mime_type, ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'], true),
            'editable' => $this->processing_status === 'ready'
                && (bool) $this->is_current
                && $this->status === 'uploaded'
                && $this->mime_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                && preg_match('/^[a-f0-9]{64}$/D', (string) $this->content_hash) === 1,
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
