<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalArchiveFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'document_id' => (int) $this->document_id,
            'role' => (string) $this->role,
            'title' => (string) $this->title,
            'is_required' => (bool) $this->is_required,
            'sort_order' => (int) $this->sort_order,
            'current_version' => new LegalArchiveDocumentVersionResource($this->whenLoaded('currentVersion')),
            'versions' => LegalArchiveDocumentVersionResource::collection($this->whenLoaded('versions')),
        ];
    }
}
