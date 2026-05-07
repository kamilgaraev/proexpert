<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class EstimateNormativeQualityService
{
    private const VERSIONS_TABLE = 'estimate_dataset_versions';
    private const RESOURCES_TABLE = 'construction_resources';
    private const COLLECTIONS_TABLE = 'estimate_norm_collections';
    private const SECTIONS_TABLE = 'estimate_norm_sections';
    private const NORMS_TABLE = 'estimate_norms';
    private const NORM_RESOURCES_TABLE = 'estimate_norm_resources';
    private const PRICES_TABLE = 'estimate_resource_prices';

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $sourceType, string $versionKey, int $limit = 20): array
    {
        $this->assertTablesExist();

        $version = $this->resolveVersion($sourceType, $versionKey);
        $limit = max(1, min($limit, 100));

        return [
            'version' => $version,
            'totals' => $this->totals((int) $version['id']),
            'collections' => $this->collections((int) $version['id']),
            'resource_types' => $this->resourceTypes((int) $version['id']),
            'top_unlinked_resources' => $this->topUnlinkedResources((int) $version['id'], $limit),
            'sample_problem_norms' => $this->sampleProblemNorms((int) $version['id'], $limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveVersion(string $sourceType, string $versionKey): array
    {
        $version = DB::table(self::VERSIONS_TABLE)
            ->select([
                'id',
                'source_type',
                'version_key',
                'status',
                'files_count',
                'rows_read',
                'rows_imported',
                'errors_count',
                'started_at',
                'finished_at',
            ])
            ->where('source_type', $sourceType)
            ->where('version_key', $versionKey)
            ->first();

        if ($version === null) {
            throw new RuntimeException("Normative version {$sourceType}:{$versionKey} was not found.");
        }

        return (array) $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(int $versionId): array
    {
        $collectionsQuery = DB::table(self::COLLECTIONS_TABLE)->where('dataset_version_id', $versionId);
        $normsQuery = $this->normsForVersion($versionId);
        $normResourcesQuery = $this->normResourcesForVersion($versionId);
        $pricesQuery = DB::table(self::PRICES_TABLE)->where('dataset_version_id', $versionId);

        $normResources = (int) (clone $normResourcesQuery)->count();
        $linkedResources = (int) (clone $normResourcesQuery)->whereNotNull('norm_resources.construction_resource_id')->count();
        $unlinkedResources = max(0, $normResources - $linkedResources);
        $norms = (int) (clone $normsQuery)->count();
        $prices = (int) (clone $pricesQuery)->count();
        $linkedPrices = (int) (clone $pricesQuery)->whereNotNull('construction_resource_id')->count();

        return [
            'collections' => (int) $collectionsQuery->count(),
            'sections' => (int) DB::table(self::SECTIONS_TABLE . ' as sections')
                ->join(self::COLLECTIONS_TABLE . ' as collections', 'collections.id', '=', 'sections.collection_id')
                ->where('collections.dataset_version_id', $versionId)
                ->count(),
            'norms' => $norms,
            'norms_without_section' => (int) (clone $normsQuery)->whereNull('norms.section_id')->count(),
            'norms_without_resources' => $this->normsWithoutResources($versionId),
            'norm_resources' => $normResources,
            'linked_norm_resources' => $linkedResources,
            'unlinked_norm_resources' => $unlinkedResources,
            'link_rate_percent' => $this->percent($linkedResources, $normResources),
            'resource_prices' => $prices,
            'linked_resource_prices' => $linkedPrices,
            'price_link_rate_percent' => $this->percent($linkedPrices, $prices),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collections(int $versionId): array
    {
        return DB::table(self::COLLECTIONS_TABLE . ' as collections')
            ->leftJoin(self::NORMS_TABLE . ' as norms', 'norms.collection_id', '=', 'collections.id')
            ->leftJoin(self::NORM_RESOURCES_TABLE . ' as norm_resources', 'norm_resources.estimate_norm_id', '=', 'norms.id')
            ->select([
                'collections.id',
                'collections.code',
                'collections.norm_type',
                'collections.name',
                'collections.source_file',
                DB::raw('COUNT(DISTINCT norms.id) as norms_count'),
                DB::raw('COUNT(norm_resources.id) as resources_count'),
                DB::raw('COUNT(norm_resources.construction_resource_id) as linked_resources_count'),
            ])
            ->where('collections.dataset_version_id', $versionId)
            ->groupBy('collections.id', 'collections.code', 'collections.norm_type', 'collections.name', 'collections.source_file')
            ->orderBy('collections.code')
            ->get()
            ->map(function (object $collection): array {
                $row = (array) $collection;
                $resources = (int) $row['resources_count'];
                $linked = (int) $row['linked_resources_count'];
                $row['link_rate_percent'] = $this->percent($linked, $resources);

                return $row;
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resourceTypes(int $versionId): array
    {
        return $this->normResourcesForVersion($versionId)
            ->select([
                'norm_resources.resource_type',
                DB::raw('COUNT(*) as resources_count'),
                DB::raw('COUNT(norm_resources.construction_resource_id) as linked_resources_count'),
            ])
            ->groupBy('norm_resources.resource_type')
            ->orderByDesc('resources_count')
            ->get()
            ->map(function (object $type): array {
                $row = (array) $type;
                $resources = (int) $row['resources_count'];
                $linked = (int) $row['linked_resources_count'];
                $row['link_rate_percent'] = $this->percent($linked, $resources);

                return $row;
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topUnlinkedResources(int $versionId, int $limit): array
    {
        return $this->normResourcesForVersion($versionId)
            ->select([
                'norm_resources.resource_code',
                DB::raw('MIN(norm_resources.resource_name) as resource_name'),
                DB::raw('MIN(norm_resources.unit) as unit'),
                DB::raw('MIN(norm_resources.resource_type) as resource_type'),
                DB::raw('COUNT(*) as occurrences_count'),
                DB::raw('COUNT(DISTINCT norm_resources.estimate_norm_id) as norms_count'),
            ])
            ->whereNull('norm_resources.construction_resource_id')
            ->whereNotNull('norm_resources.resource_code')
            ->where('norm_resources.resource_code', '<>', '')
            ->groupBy('norm_resources.resource_code')
            ->orderByDesc('occurrences_count')
            ->limit($limit)
            ->get()
            ->map(static fn (object $resource): array => (array) $resource)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sampleProblemNorms(int $versionId, int $limit): array
    {
        return $this->normsForVersion($versionId)
            ->leftJoin(self::NORM_RESOURCES_TABLE . ' as norm_resources', 'norm_resources.estimate_norm_id', '=', 'norms.id')
            ->select([
                'collections.code as collection_code',
                'norms.code',
                'norms.name',
                DB::raw('COUNT(norm_resources.id) as resources_count'),
                DB::raw('COUNT(norm_resources.construction_resource_id) as linked_resources_count'),
            ])
            ->groupBy('collections.code', 'norms.id', 'norms.code', 'norms.name')
            ->havingRaw('COUNT(norm_resources.id) = 0 OR COUNT(norm_resources.id) > COUNT(norm_resources.construction_resource_id)')
            ->orderByDesc(DB::raw('COUNT(norm_resources.id) - COUNT(norm_resources.construction_resource_id)'))
            ->orderBy('norms.code')
            ->limit($limit)
            ->get()
            ->map(static fn (object $norm): array => (array) $norm)
            ->all();
    }

    private function normsWithoutResources(int $versionId): int
    {
        $query = $this->normsForVersion($versionId)
            ->leftJoin(self::NORM_RESOURCES_TABLE . ' as norm_resources', 'norm_resources.estimate_norm_id', '=', 'norms.id')
            ->select('norms.id')
            ->groupBy('norms.id')
            ->havingRaw('COUNT(norm_resources.id) = 0');

        return (int) DB::query()
            ->fromSub($query, 'norms_without_resources')
            ->count();
    }

    private function normsForVersion(int $versionId): \Illuminate\Database\Query\Builder
    {
        return DB::table(self::NORMS_TABLE . ' as norms')
            ->join(self::COLLECTIONS_TABLE . ' as collections', 'collections.id', '=', 'norms.collection_id')
            ->where('collections.dataset_version_id', $versionId);
    }

    private function normResourcesForVersion(int $versionId): \Illuminate\Database\Query\Builder
    {
        return DB::table(self::NORM_RESOURCES_TABLE . ' as norm_resources')
            ->join(self::NORMS_TABLE . ' as norms', 'norms.id', '=', 'norm_resources.estimate_norm_id')
            ->join(self::COLLECTIONS_TABLE . ' as collections', 'collections.id', '=', 'norms.collection_id')
            ->where('collections.dataset_version_id', $versionId);
    }

    private function percent(int $value, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(($value / $total) * 100, 2);
    }

    private function assertTablesExist(): void
    {
        foreach ([
            self::VERSIONS_TABLE,
            self::COLLECTIONS_TABLE,
            self::SECTIONS_TABLE,
            self::NORMS_TABLE,
            self::NORM_RESOURCES_TABLE,
            self::PRICES_TABLE,
        ] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("Required table {$table} does not exist.");
            }
        }
    }
}
