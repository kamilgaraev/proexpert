<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionStateService;
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
                $this->resource->relationLoaded('modelVersions'),
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
            'participants' => $this->whenLoaded('participants', fn () => $this->participants->values()),
            'realtime' => [
                'enabled' => config('broadcasting.default') === 'reverb',
                'broadcaster' => 'reverb',
                'key' => config('reverb.apps.apps.0.key'),
                'host' => config('reverb.apps.apps.0.options.host'),
                'port' => (int) config('reverb.apps.apps.0.options.port', 443),
                'scheme' => config('reverb.apps.apps.0.options.scheme', 'https'),
                'auth_endpoint' => '/api/v1/admin/broadcasting/auth',
                'event' => 'design-model-session.transient',
                'schema_version' => 2,
                'participant_ttl_seconds' => DesignModelSessionStateService::TTL,
                'heartbeat_interval_seconds' => DesignModelSessionStateService::HEARTBEAT_INTERVAL,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
