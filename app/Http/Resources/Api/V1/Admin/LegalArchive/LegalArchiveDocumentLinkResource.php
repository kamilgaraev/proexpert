<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\LegalArchive;

use App\Services\LegalArchive\LegalArchiveDictionary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalArchiveDocumentLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $linkType = $this->resource->getAttribute('link_type');

        return [
            'id' => $this->id,
            'link_type' => $linkType,
            'link_type_label' => LegalArchiveDictionary::label('link_types', is_string($linkType) ? $linkType : null),
            'linked_type' => $this->linked_type,
            'linked_id' => $this->linked_id,
            'external_key' => $this->external_key,
            'display_name' => $this->display_name,
            'url' => $this->url,
            'metadata' => $this->metadata ?? [],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
