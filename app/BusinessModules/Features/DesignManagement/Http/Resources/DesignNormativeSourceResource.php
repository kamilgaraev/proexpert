<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignNormativeSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignNormativeSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignNormativeSource $source */
        $source = $this->resource;

        return [
            'id' => $source->id,
            'code' => $source->code,
            'title' => $source->title,
            'version' => $source->version,
            'effective_from' => $source->effective_from?->format('Y-m-d'),
            'effective_to' => $source->effective_to?->format('Y-m-d'),
            'source_url' => $source->source_url,
            'status' => $source->status,
            'metadata' => $source->metadata ?? [],
        ];
    }
}
