<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Enums\DesignPackageStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignPackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignPackage $package */
        $package = $this->resource;
        $status = $this->enumValue($package->status);
        $currentVersion = $this->currentVersion($package);
        $derivative = $this->preferredDerivative($currentVersion);
        $problemFlags = $this->problemFlags($package, $currentVersion, $derivative);
        $artifactsCount = $package->relationLoaded('artifacts') ? $package->artifacts->count() : 0;

        return [
            'id' => $package->id,
            'organization_id' => $package->organization_id,
            'project_id' => $package->project_id,
            'title' => $package->title,
            'stage' => $package->stage,
            'discipline' => $package->discipline,
            'status' => $status,
            'status_label' => trans_message("design_management.statuses.packages.{$status}"),
            'planned_issue_date' => $package->planned_issue_date?->format('Y-m-d'),
            'metadata' => $package->metadata ?? [],
            'project' => $this->whenLoaded('project', fn () => $package->project ? [
                'id' => $package->project->id,
                'name' => $package->project->name,
            ] : null),
            'models_count' => $package->relationLoaded('artifacts') ? $package->artifacts->count() : null,
            'models' => DesignArtifactResource::collection($this->whenLoaded('artifacts')),
            'artifacts' => DesignArtifactResource::collection($this->whenLoaded('artifacts')),
            'current_version' => $currentVersion ? new DesignArtifactVersionResource($currentVersion) : null,
            'derivative' => $derivative ? new DesignModelDerivativeResource($derivative) : $this->missingDerivative(),
            'problem_flags' => $problemFlags,
            'workflow_summary' => [
                'artifacts_count' => $artifactsCount,
                'models_count' => $artifactsCount,
                'current_version_id' => $currentVersion?->id,
                'derivative_status' => $derivative
                    ? $this->enumValue($derivative->status)
                    : DesignDerivativeStatusEnum::MISSING->value,
                'has_ready_viewer' => $derivative !== null
                    && $this->enumValue($derivative->status) === DesignDerivativeStatusEnum::READY->value,
                'is_overdue' => in_array('planned_issue_overdue', $problemFlags, true),
            ],
            'created_at' => $package->created_at?->toIso8601String(),
            'updated_at' => $package->updated_at?->toIso8601String(),
        ];
    }

    private function currentVersion(DesignPackage $package): ?DesignArtifactVersion
    {
        if (!$package->relationLoaded('artifacts')) {
            return null;
        }

        foreach ($package->artifacts as $artifact) {
            if ($artifact->relationLoaded('currentVersion') && $artifact->currentVersion instanceof DesignArtifactVersion) {
                return $artifact->currentVersion;
            }
        }

        foreach ($package->artifacts as $artifact) {
            if ($artifact->relationLoaded('versions')) {
                $version = $artifact->versions->first();

                if ($version instanceof DesignArtifactVersion) {
                    return $version;
                }
            }
        }

        return null;
    }

    private function preferredDerivative(?DesignArtifactVersion $version): ?DesignModelDerivative
    {
        if (!$version instanceof DesignArtifactVersion) {
            return null;
        }

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

    private function problemFlags(
        DesignPackage $package,
        ?DesignArtifactVersion $currentVersion,
        ?DesignModelDerivative $derivative
    ): array {
        $flags = [];
        $status = $this->enumValue($package->status);

        if (!$currentVersion instanceof DesignArtifactVersion) {
            $flags[] = 'model_missing';
        }

        if ($currentVersion instanceof DesignArtifactVersion && !$derivative instanceof DesignModelDerivative) {
            $flags[] = 'viewer_not_prepared';
        }

        if (
            $derivative instanceof DesignModelDerivative
            && $this->enumValue($derivative->status) === DesignDerivativeStatusEnum::FAILED->value
        ) {
            $flags[] = 'viewer_preparation_failed';
        }

        if (
            $package->planned_issue_date !== null
            && $package->planned_issue_date->isPast()
            && !in_array($status, [
                DesignPackageStatusEnum::APPROVED->value,
                DesignPackageStatusEnum::ISSUED->value,
            ], true)
        ) {
            $flags[] = 'planned_issue_overdue';
        }

        return $flags;
    }

    private function missingDerivative(): array
    {
        return [
            'id' => null,
            'status' => DesignDerivativeStatusEnum::MISSING->value,
            'viewer_provider' => 'thatopen',
            'derivative_format' => 'thatopen_frag',
        ];
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? $value->value : (string) ($value ?? DesignPackageStatusEnum::DRAFT->value);
    }
}
