<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use BackedEnum;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignModelSessionResource extends JsonResource
{
    public function toArray($request): array
    {
        $revision = $this->modelSetRevision;

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'model_set_id' => $this->model_set_id,
            'model_set_revision_id' => $revision?->id,
            'model_set_revision' => $revision?->revision,
            'title' => $this->title,
            'models' => $revision?->version_ids ?? [],
            'model_versions' => $this->when(
                $this->relationLoaded('modelVersions'),
                fn () => $this->modelVersions->map(static function (DesignArtifactVersion $version): array {
                    $derivativeStatus = $version->readyDerivative?->status;

                    return [
                        'version_id' => $version->id,
                        'model_id' => $version->artifact_id,
                        'package_id' => $version->artifact?->package_id,
                        'model_title' => $version->artifact?->title,
                        'title' => $version->title,
                        'version_number' => $version->version_number,
                        'revision' => $version->revision,
                        'derivative_status' => $derivativeStatus instanceof BackedEnum
                            ? $derivativeStatus->value
                            : ($derivativeStatus ?? 'missing'),
                    ];
                })->values()
            ),
            'transforms' => $revision?->transforms ?? [],
            'channel' => "design-model-session.{$this->id}",
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
