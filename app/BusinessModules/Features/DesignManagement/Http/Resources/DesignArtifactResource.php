<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Enums\DesignArtifactTypeEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignArtifactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignArtifact $artifact */
        $artifact = $this->resource;
        $artifactType = $this->enumValue($artifact->artifact_type);

        return [
            'id' => $artifact->id,
            'organization_id' => $artifact->organization_id,
            'project_id' => $artifact->project_id,
            'package_id' => $artifact->package_id,
            'artifact_type' => $artifactType,
            'artifact_type_label' => trans_message("design_management.artifact_types.{$artifactType}"),
            'title' => $artifact->title,
            'discipline' => $artifact->discipline,
            'stage' => $artifact->stage,
            'status' => $artifact->status,
            'metadata' => $artifact->metadata ?? [],
            'current_version' => new DesignArtifactVersionResource($this->whenLoaded('currentVersion')),
            'versions' => DesignArtifactVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $artifact->created_at?->toIso8601String(),
            'updated_at' => $artifact->updated_at?->toIso8601String(),
        ];
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? $value->value : (string) ($value ?? DesignArtifactTypeEnum::OTHER->value);
    }
}
