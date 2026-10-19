<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionRevision;
use App\BusinessModules\Features\DesignManagement\Models\DesignCompositionExclusion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackageSection;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessFingerprint;
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
        $contexts = [];
        $keys = [];
        foreach ($packages as $package) {
            $key = spl_object_id($package);
            $contexts[$key] = new DesignCompletenessFingerprint;
            $keys[(int) $package->id] = $key;
        }
        $ids = array_keys($keys);
        $this->appendDataset($contexts, $keys, 'sections', DesignPackageSection::query()->whereIn('package_id', $ids)
            ->orderBy('sort_order')->orderBy('code')->orderBy('id')->cursor(), self::sectionRow(...));
        $this->appendDataset($contexts, $keys, 'artifacts', DesignArtifact::query()->whereIn('package_id', $ids)->orderBy('id')
            ->select([...self::ARTIFACT_FIELDS, 'package_id'])->cursor(), self::artifactRow(...));
        $this->appendDataset($contexts, $keys, 'versions', DesignArtifactVersion::query()
            ->whereHas('artifact', static fn ($query) => $query->whereIn('package_id', $ids))
            ->select(self::VERSION_FIELDS)
            ->selectSub(DesignArtifact::query()->select('package_id')->whereColumn('id', 'design_artifact_versions.artifact_id'), 'fingerprint_package_id')
            ->orderBy('id')->cursor(), self::versionRow(...), 'fingerprint_package_id');
        $this->appendDataset($contexts, $keys, 'sheets', DesignDocumentSheet::query()->whereIn('package_id', $ids)->orderBy('id')
            ->select([...self::SHEET_FIELDS, 'package_id'])->cursor(), self::sheetRow(...));
        $this->appendDataset($contexts, $keys, 'comments', DesignReviewComment::query()->whereIn('package_id', $ids)->orderBy('id')
            ->select([...self::COMMENT_FIELDS, 'package_id'])->cursor(), self::commentRow(...));
        $issues = DesignPackageBlockingIssueQuery::forPackages($packages)->cursor()
            ->map(static fn ($row): QualityDefect => QualityDefect::hydrate([(array) $row])->sole());
        $this->appendDataset($contexts, $keys, 'issues', $issues, self::issueRow(...), 'fingerprint_package_id');
        $revisionKeys = [];
        foreach ($packages as $package) {
            if ($package->composition_revision_id !== null) {
                $revisionKeys[(int) $package->composition_revision_id][] = spl_object_id($package);
            }
        }
        $existingRevisions = $revisionKeys === [] ? [] : DesignCompositionRevision::query()
            ->whereKey(array_keys($revisionKeys))->pluck('id')->all();
        foreach ($packages as $package) {
            $contexts[spl_object_id($package)]->beginDataset('exclusions', $package->composition_revision_id !== null
                && ! in_array((int) $package->composition_revision_id, $existingRevisions, true));
        }
        if ($existingRevisions !== []) {
            foreach (DesignCompositionExclusion::query()->whereIn('revision_id', $existingRevisions)->orderByDesc('id')->cursor() as $row) {
                $value = self::exclusionRow($row);
                foreach ($revisionKeys[(int) $row->revision_id] as $key) {
                    $contexts[$key]->append($value);
                }
            }
        }
        $fingerprints = [];
        foreach ($contexts as $key => $context) {
            $context->endDataset();
            $fingerprints[$key] = $context->finish();
        }

        return $fingerprints;
    }

    private function appendDataset(array $contexts, array $keys, string $name, iterable $rows, callable $mapper, string $column = 'package_id'): void
    {
        foreach ($contexts as $context) {
            $context->beginDataset($name);
        }
        foreach ($rows as $row) {
            $contexts[$keys[(int) $row->{$column}]]->append($mapper($row));
        }
        foreach ($contexts as $context) {
            $context->endDataset();
        }
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
            'sections' => $sections->map(self::sectionRow(...))->values()->all(),
            'artifacts' => $artifacts->map(self::artifactRow(...))->values()->all(),
            'versions' => $versions->map(self::versionRow(...))->values()->all(),
            'sheets' => $sheets->map(self::sheetRow(...))->values()->all(),
            'comments' => $comments->map(self::commentRow(...))->values()->all(),
            'issues' => $issues->map(self::issueRow(...))->values()->all(),
            'exclusions' => $exclusions?->map(self::exclusionRow(...))->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private static function sectionRow(DesignPackageSection $item): array
    {
        return ['id' => $item->id, 'code' => $item->code, 'required' => $item->required,
            'metadata' => array_diff_key($item->metadata ?? [], array_flip(['readiness_status', 'readiness_results', 'readiness_check_id']))];
    }

    private static function artifactRow(DesignArtifact $item): array
    {
        return $item->only(self::ARTIFACT_FIELDS);
    }

    private static function versionRow(DesignArtifactVersion $item): array
    {
        return $item->only(self::VERSION_FIELDS);
    }

    private static function sheetRow(DesignDocumentSheet $item): array
    {
        return $item->only(self::SHEET_FIELDS);
    }

    private static function commentRow(DesignReviewComment $item): array
    {
        return $item->only(self::COMMENT_FIELDS);
    }

    private static function issueRow(QualityDefect $item): array
    {
        return ['id' => $item->id, 'status' => $item->status, 'metadata' => $item->metadata,
            'updated_at' => $item->updated_at?->toISOString()];
    }

    private static function exclusionRow(DesignCompositionExclusion $item): array
    {
        return ['id' => $item->id, 'item_key' => $item->item_key, 'reason' => $item->reason,
            'created_at' => $item->created_at?->toISOString()];
    }
}
