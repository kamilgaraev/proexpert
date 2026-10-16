<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use BackedEnum;
use App\Models\User;
use DomainException;
use App\BusinessModules\Features\DesignManagement\Models\DesignWorkflowEvent;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessScope;

final class DesignPackageIssueRegisterService
{
    public function build(DesignPackage $package, ?int $workflowEventId = null, ?User $actor = null): array
    {
        app(DesignAccessService::class)->assertPackage($package, app(DesignAccessService::class)->requireActor($actor), 'design-management.export');
        $events = DesignWorkflowEvent::query()->where('organization_id', $package->organization_id)
            ->where('project_id', $package->project_id)->where('package_id', $package->id)
            ->where('action', 'issue')->orderByDesc('id')->get();
        $selected = $workflowEventId === null ? $events->first() : $events->firstWhere('id', $workflowEventId);
        if ($workflowEventId !== null && $selected === null) {
            throw new DomainException(trans_message('design_management.errors.release_not_found'));
        }
        $releases = $events->map(static fn (DesignWorkflowEvent $event): array => [
            'workflow_event_id' => $event->id, 'issued_at' => $event->created_at?->toISOString(),
            'composition_revision_id' => $event->metadata['composition_revision_id'] ?? null,
            'snapshot_available' => is_array($event->metadata['release_snapshot'] ?? null),
        ])->values()->all();
        if ($selected !== null) {
            $snapshot = $selected->metadata['release_snapshot'] ?? null;
            if (! is_array($snapshot)) {
                throw new DomainException(trans_message('design_management.errors.release_snapshot_unavailable'));
            }
            return $snapshot + ['release' => ['workflow_event_id' => $selected->id,
                'issued_at' => $selected->created_at?->toISOString(),
                'composition_revision_id' => $selected->metadata['composition_revision_id'] ?? null,
                'composition_fingerprint' => $snapshot['composition']['fingerprint'] ?? null],
                'releases' => $releases, 'is_snapshot' => true];
        }
        return $this->snapshot($package) + ['release' => null, 'releases' => [], 'is_snapshot' => false];
    }

    public function snapshot(DesignPackage $package): array
    {
        $package->loadMissing([
            'project:id,name,organization_id',
            'sections.artifacts.currentVersion.sheets',
            'latestCompletenessCheck',
        ]);

        $revision = $package->composition_revision_id === null ? null : \App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision::query()->whereKey($package->composition_revision_id)->where('package_id', $package->id)->where('organization_id', $package->organization_id)->where('project_id', $package->project_id)->with('exclusions')->first();
        $scope = new DesignCompletenessScope($package);
        $selectedArtifacts = $scope->artifacts()->keyBy('id');
        $selectedSections = collect($scope->sections())->map(static fn (array $entry): int => (int) $entry['section']->id)->all();
        return [
            'composition' => $revision === null ? null : ['revision_id' => $revision->id, 'revision_number' => $revision->revision_number, 'fingerprint' => $revision->fingerprint, 'composition' => $revision->composition, 'exclusions' => $revision->exclusions->map(static fn ($exclusion): array => ['item_key' => $exclusion->item_key, 'reason' => $exclusion->reason])->all()],
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
                ->filter(static fn (DesignPackageSection $section): bool => in_array((int) $section->id, $selectedSections, true))
                ->map(static fn (DesignPackageSection $section): array => [
                    'id' => $section->id,
                    'code' => $section->code,
                    'title' => $section->title,
                    'status' => $section->status instanceof BackedEnum ? $section->status->value : (string) $section->status,
                    'documents' => $section->artifacts
                        ->filter(static fn (DesignArtifact $artifact): bool => $selectedArtifacts->has($artifact->id))
                        ->map(static fn (DesignArtifact $artifact): array => [
                            'id' => $artifact->id,
                            'document_code' => $artifact->document_code,
                            'title' => $artifact->document_title ?: $artifact->title,
                            'artifact_type' => $artifact->artifact_type instanceof BackedEnum ? $artifact->artifact_type->value : (string) $artifact->artifact_type,
                            'current_version_id' => $artifact->currentVersion?->id,
                            'version_number' => $artifact->currentVersion?->version_number,
                            'file_format' => $artifact->currentVersion?->file_format,
                            'revision' => $artifact->currentVersion?->revision_label ?: $artifact->currentVersion?->revision,
                            'source_original_name' => $artifact->currentVersion?->source_original_name,
                            'source_sha256' => $artifact->currentVersion?->source_sha256,
                            'sheets' => $artifact->currentVersion?->sheets->map(static fn ($sheet): array => $sheet->only(['id', 'sheet_number', 'sheet_code', 'sheet_title', 'revision', 'file_page_number', 'total_sheets', 'status']))->all() ?? [],
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
