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
            'version_number' => $version->version_number,
            'file_url' => $version->file_url,
            'comment' => $version->comment,
            'uploaded_at' => $version->uploaded_at?->toIso8601String(),
            'created_at' => $version->created_at?->toIso8601String(),
        ];
    }
}
