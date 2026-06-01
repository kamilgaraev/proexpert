<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Enums\DesignVersionStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignArtifactVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignArtifactVersion $version */
        $version = $this->resource;
        $status = $this->enumValue($version->status);
        $artifact = $version->relationLoaded('artifact') ? $version->artifact : null;
        $package = $artifact?->relationLoaded('package') ? $artifact->package : null;
        $project = $artifact?->relationLoaded('project') ? $artifact->project : ($package?->relationLoaded('project') ? $package->project : null);
        $derivative = $this->preferredDerivative($version);

        return [
            'id' => $version->id,
            'organization_id' => $version->organization_id,
            'project_id' => $version->project_id,
            'artifact_id' => $version->artifact_id,
            'title' => $version->title,
            'version_number' => $version->version_number,
            'revision' => $version->revision,
            'source_format' => $version->source_format,
            'source_original_name' => $version->source_original_name,
            'source_mime_type' => $version->source_mime_type,
            'source_size_bytes' => $version->source_size_bytes,
            'source_size_human' => $this->formatBytes((int) $version->source_size_bytes),
            'model_date' => $version->model_date?->format('Y-m-d'),
            'status' => $status,
            'status_label' => trans_message("design_management.statuses.versions.{$status}"),
            'is_current' => (bool) $version->is_current,
            'metadata' => $version->metadata ?? [],
            'artifact' => $artifact ? [
                'id' => $artifact->id,
                'title' => $artifact->title,
                'artifact_type' => $this->enumValue($artifact->artifact_type),
                'discipline' => $artifact->discipline,
                'stage' => $artifact->stage,
            ] : null,
            'package' => $package ? [
                'id' => $package->id,
                'title' => $package->title,
                'stage' => $package->stage,
                'discipline' => $package->discipline,
                'status' => $this->enumValue($package->status),
            ] : null,
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
            ] : null,
            'derivative' => $derivative ? new DesignModelDerivativeResource($derivative) : null,
            'derivatives' => DesignModelDerivativeResource::collection($this->whenLoaded('derivatives')),
            'created_at' => $version->created_at?->toIso8601String(),
            'updated_at' => $version->updated_at?->toIso8601String(),
        ];
    }

    private function preferredDerivative(DesignArtifactVersion $version): ?DesignModelDerivative
    {
        if ($version->relationLoaded('readyDerivative') && $version->readyDerivative instanceof DesignModelDerivative) {
            return $version->readyDerivative;
        }

        if ($version->relationLoaded('derivatives')) {
            return $version->derivatives
                ->first(static fn (DesignModelDerivative $item): bool => $item->viewer_provider === 'thatopen'
                    && $item->derivative_format === 'thatopen_frag');
        }

        return null;
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? $value->value : (string) ($value ?? DesignVersionStatusEnum::UPLOADED->value);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 Б';
        }

        $units = ['Б', 'КБ', 'МБ', 'ГБ'];
        $size = (float) $bytes;
        $index = 0;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, $index === 0 ? 0 : 1) . ' ' . $units[$index];
    }
}
