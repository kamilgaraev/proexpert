<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class DesignModelSetResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'revision' => $this->revision,
            'revisions' => $this->whenLoaded('revisions', fn () => $this->revisions->map(
                fn ($revision) => [
                    'revision' => $revision->revision,
                    'version_ids' => $revision->version_ids,
                    'transforms' => $revision->transforms,
                ]
            )),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
