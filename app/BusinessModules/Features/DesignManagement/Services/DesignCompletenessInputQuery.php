<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use Illuminate\Support\Collection;

final class DesignCompletenessInputQuery
{
    public const ARTIFACT_FIELDS = ['id', 'section_id', 'document_code', 'title', 'requires_sheet_registry', 'status', 'metadata', 'updated_at'];

    public const VERSION_FIELDS = ['id', 'artifact_id', 'status', 'is_current', 'revision', 'revision_label', 'source_format', 'file_format', 'source_file_path', 'page_count', 'sheet_count', 'metadata', 'updated_at'];

    public const SHEET_FIELDS = ['id', 'version_id', 'status', 'revision', 'updated_at'];

    public const COMMENT_FIELDS = ['id', 'status', 'severity', 'updated_at'];

    public function fingerprints(array $packages): array
    {
        if ($packages === []) {
            return [];
        }
        $ids = array_map(static fn (DesignPackage $package): int => (int) $package->id, $packages);
        $sections = DesignPackageSection::query()->whereIn('package_id', $ids)
            ->orderBy('sort_order')->orderBy('code')->orderBy('id')->get()->groupBy('package_id');
        $artifacts = DesignArtifact::query()->whereIn('package_id', $ids)->orderBy('id')
            ->get([...self::ARTIFACT_FIELDS, 'package_id'])->groupBy('package_id');
        $versions = DesignArtifactVersion::query()
            ->whereHas('artifact', static fn ($query) => $query->whereIn('package_id', $ids))
            ->select(self::VERSION_FIELDS)
            ->selectSub(DesignArtifact::query()->select('package_id')->whereColumn('id', 'design_artifact_versions.artifact_id'), 'fingerprint_package_id')
            ->orderBy('id')->get()->groupBy('fingerprint_package_id');
        $sheets = DesignDocumentSheet::query()->whereIn('package_id', $ids)->orderBy('id')
            ->get([...self::SHEET_FIELDS, 'package_id'])->groupBy('package_id');
        $comments = DesignReviewComment::query()->whereIn('package_id', $ids)->orderBy('id')
            ->get([...self::COMMENT_FIELDS, 'package_id'])->groupBy('package_id');
        $issuesQuery = null;
        foreach ($packages as $package) {
            $branch = DesignPackageBlockingIssueQuery::forPackage($package)
                ->select(['id', 'status', 'metadata', 'updated_at'])
                ->selectRaw('? as fingerprint_package_id', [(int) $package->id])->toBase();
            if ($issuesQuery === null) {
                $issuesQuery = $branch;
            } else {
                $issuesQuery->unionAll($branch);
            }
        }
        $issues = QualityDefect::hydrate($issuesQuery->orderBy('id')->get()
            ->map(static fn ($row): array => (array) $row)->all())->groupBy('fingerprint_package_id');
        $revisionIds = array_values(array_unique(array_filter(array_map(
            static fn (DesignPackage $package) => $package->composition_revision_id, $packages
        ), static fn ($id): bool => $id !== null)));
        $revisions = $revisionIds === [] ? collect() : DesignCompositionRevision::query()
            ->whereKey($revisionIds)->with('exclusions')->get()->keyBy('id');
        $fingerprints = [];
        foreach ($packages as $package) {
            $id = $package->id;
            $exclusions = $package->composition_revision_id === null
                ? collect()
                : $revisions->get($package->composition_revision_id)?->exclusions;
            $fingerprints[spl_object_id($package)] = self::fingerprint(
                $sections->get($id, collect()), $artifacts->get($id, collect()),
                $versions->get($id, collect()), $sheets->get($id, collect()),
                $comments->get($id, collect()), $issues->get($id, collect()), $exclusions,
            );
        }

        return $fingerprints;
    }

    public static function fingerprint(
        Collection $sections,
        Collection $artifacts,
        Collection $versions,
        Collection $sheets,
        Collection $comments,
        Collection $issues,
        ?Collection $exclusions,
    ): string {
        return hash('sha256', json_encode([
            'sections' => $sections->map(static fn ($item): array => [
                'id' => $item->id, 'code' => $item->code, 'required' => $item->required,
                'metadata' => array_diff_key($item->metadata ?? [], array_flip(['readiness_status', 'readiness_results', 'readiness_check_id'])),
            ])->values()->all(),
            'artifacts' => $artifacts->map(static fn (DesignArtifact $item): array => $item->only(self::ARTIFACT_FIELDS))->values()->all(),
            'versions' => $versions->map(static fn (DesignArtifactVersion $item): array => $item->only(self::VERSION_FIELDS))->values()->all(),
            'sheets' => $sheets->map(static fn (DesignDocumentSheet $item): array => $item->only(self::SHEET_FIELDS))->values()->all(),
            'comments' => $comments->map(static fn (DesignReviewComment $item): array => $item->only(self::COMMENT_FIELDS))->values()->all(),
            'issues' => $issues->map(static fn ($item): array => [
                'id' => $item->id, 'status' => $item->status, 'metadata' => $item->metadata,
                'updated_at' => $item->updated_at?->toISOString(),
            ])->values()->all(),
            'exclusions' => $exclusions?->map(static fn ($item): array => [
                'id' => $item->id, 'item_key' => $item->item_key, 'reason' => $item->reason,
                'created_at' => $item->created_at?->toISOString(),
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
