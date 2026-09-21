<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Resources;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExecutiveDocumentVersion */
final class ExecutiveDocumentVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ExecutiveDocumentVersion $version */
        $version = $this->resource;

        return [
            'id' => $version->id,
            'document_id' => $version->document_id,
            'revision' => (int) ($version->metadata['draft_revision'] ?? 0),
            'version_number' => $version->version_number,
            'status' => $version->status,
            'origin' => $version->metadata['origin'] ?? 'registered_external',
            'template_version' => ($version->metadata['origin'] ?? null) === 'generated_preparation' ? ($version->metadata['template_version'] ?? null) : null,
            'file_url' => $version->file_url,
            'content_hash' => $version->content_hash,
            'profile_snapshot' => $version->profile_snapshot,
            'basis_snapshot' => $version->basis_snapshot,
            'comment' => $version->comment,
            'uploaded_at' => $version->uploaded_at?->toIso8601String(),
            'created_at' => $version->created_at?->toIso8601String(),
            'approved_at' => $version->approved_at?->toIso8601String(),
        ];
    }
}
