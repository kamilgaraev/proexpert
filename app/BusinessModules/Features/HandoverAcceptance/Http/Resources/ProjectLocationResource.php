<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\BusinessModules\Features\HandoverAcceptance\Models\ProjectLocation;

/** @mixin ProjectLocation */
final class ProjectLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $location = $this->resource;

        if (!$location instanceof ProjectLocation) {
            return [];
        }

        return [
            'id' => $location->id,
            'project_id' => $location->project_id,
            'parent_id' => $location->parent_id,
            'location_type' => $location->location_type,
            'name' => $location->name,
            'code' => $location->code,
            'path' => $location->path,
            'level' => $location->level,
        ];
    }
}
