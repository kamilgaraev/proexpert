<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use BackedEnum;

final class DesignPackageIssueRegisterService
{
    public function build(DesignPackage $package): array
    {
        $package->loadMissing([
            'project:id,name,organization_id',
            'sections.artifacts.currentVersion.sheets',
            'latestCompletenessCheck',
        ]);

        return [
            'package' => [
                'id' => $package->id,
                'title' => $package->title,
                'project_stage' => $this->value($package->project_stage),
                'object_type' => $this->value($package->object_type),
                'normative_profile_code' => $package->normative_profile_code,
                'status' => $this->value($package->status),
                'issued_at' => $package->issued_at?->toIso8601String(),
            ],
            'project' => $package->project ? [
                'id' => $package->project->id,
                'name' => $package->project->name,
            ] : null,
            'completeness_check' => $package->latestCompletenessCheck ? [
                'id' => $package->latestCompletenessCheck->id,
                'status' => $this->value($package->latestCompletenessCheck->status),
                'blocking_count' => $package->latestCompletenessCheck->blocking_count,
                'warning_count' => $package->latestCompletenessCheck->warning_count,
                'checked_at' => $package->latestCompletenessCheck->checked_at?->toIso8601String(),
            ] : null,
            'sections' => $package->sections
                ->map(static fn (DesignPackageSection $section): array => [
                    'id' => $section->id,
                    'code' => $section->code,
                    'title' => $section->title,
                    'status' => $section->status instanceof BackedEnum ? $section->status->value : (string) $section->status,
                    'documents' => $section->artifacts
                        ->map(static fn (DesignArtifact $artifact): array => [
                            'id' => $artifact->id,
                            'document_code' => $artifact->document_code,
                            'title' => $artifact->document_title ?: $artifact->title,
                            'artifact_type' => $artifact->artifact_type instanceof BackedEnum ? $artifact->artifact_type->value : (string) $artifact->artifact_type,
                            'current_version_id' => $artifact->currentVersion?->id,
                            'file_format' => $artifact->currentVersion?->file_format,
                            'revision' => $artifact->currentVersion?->revision_label ?: $artifact->currentVersion?->revision,
                            'source_original_name' => $artifact->currentVersion?->source_original_name,
                            'sheets_count' => $artifact->currentVersion?->relationLoaded('sheets') ? $artifact->currentVersion->sheets->count() : 0,
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    private function value(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : ($value !== null ? (string) $value : null);
    }
}
