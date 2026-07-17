<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;

final readonly class EloquentNormativeContextPinSource implements NormativeContextPinSource
{
    public function __construct(
        private Connection $database,
        private NormativeIntentCandidateRanker $ranker = new NormativeIntentCandidateRanker,
        private NormativeSearchQueryBuilder $queryBuilder = new NormativeSearchQueryBuilder,
        private NormativeResourceCoverage $resourceCoverage = new NormativeResourceCoverage,
    ) {}

    public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData
    {
        $dataset = $this->database->table('estimate_dataset_versions')
            ->where('id', $requested->datasetId)
            ->where('source_type', 'fsnb_2022')
            ->where('status', 'parsed')
            ->where('version_key', $requested->datasetVersion)
            ->exists();
        $prices = $this->database->table('estimate_regional_price_versions')
            ->where('id', $requested->regionalPriceVersionId)
            ->where('region_id', $requested->regionId)
            ->where('price_zone_id', $requested->priceZoneId)
            ->where('period_id', $requested->periodId)
            ->where('version_key', $requested->priceVersion)
            ->whereIn('status', ['checked', 'active'])
            ->exists();

        if (! $dataset || ! $prices) {
            $this->telemetry('identity_rejected', ['dataset_ready' => $dataset, 'prices_ready' => $prices]);

            return null;
        }
        if ($intents === [] || count($intents) > 64) {
            $this->telemetry('intents_rejected', ['intents_count' => count($intents)]);

            return null;
        }
        $norms = collect();
        $poolCandidatesCount = 0;
        foreach ($intents as $intent) {
            $search = mb_strtolower(trim((string) ($intent['search_text'] ?? '')));
            $unit = trim((string) ($intent['unit'] ?? ''));
            $code = mb_strtolower(trim((string) ($intent['code'] ?? '')));
            if ($search === '' || $unit === '') {
                return null;
            }
            $lexicalQuery = $this->queryBuilder->build($search);
            $query = $this->database->table('estimate_norms as norms')
                ->join('estimate_norm_collections as collections', 'collections.id', '=', 'norms.collection_id')
                ->where('collections.dataset_version_id', $requested->datasetId)
                ->whereExists(function ($priced) use ($requested): void {
                    $priced->selectRaw('1')
                        ->from('estimate_norm_resources as pin_resources')
                        ->join('estimate_resource_prices as pin_prices', function ($join) use ($requested): void {
                            $join->on('pin_prices.resource_code', '=', 'pin_resources.resource_code')
                                ->on('pin_prices.price_type', '=', 'pin_resources.resource_type')
                                ->where('pin_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                                ->where('pin_prices.region_id', $requested->regionId)
                                ->where('pin_prices.price_zone_id', $requested->priceZoneId)
                                ->where('pin_prices.period_id', $requested->periodId);
                        })
                        ->whereColumn('pin_resources.estimate_norm_id', 'norms.id')
                        ->where('pin_prices.base_price', '>', 0)
                        ->where(function ($identity): void {
                            $identity->whereColumn('pin_resources.construction_resource_id', 'pin_prices.construction_resource_id')
                                ->orWhereNull('pin_resources.construction_resource_id')
                                ->orWhereNull('pin_prices.construction_resource_id');
                        });
                })
                ->whereNotExists(function ($invalidQuantity): void {
                    $invalidQuantity->selectRaw('1')
                        ->from('estimate_norm_resources as invalid_resources')
                        ->whereColumn('invalid_resources.estimate_norm_id', 'norms.id')
                        ->where(function ($invalid): void {
                            $invalid->whereNull('invalid_resources.quantity')
                                ->orWhere('invalid_resources.quantity', '<=', 0);
                        });
                })
                ->whereNotExists(function ($unpriced) use ($requested): void {
                    $unpriced->selectRaw('1')
                        ->from('estimate_norm_resources as required_resources')
                        ->whereColumn('required_resources.estimate_norm_id', 'norms.id')
                        ->whereNotExists(function ($validPrice) use ($requested): void {
                            $validPrice->selectRaw('1')
                                ->from('estimate_resource_prices as valid_prices')
                                ->whereColumn('valid_prices.resource_code', 'required_resources.resource_code')
                                ->whereColumn('valid_prices.price_type', 'required_resources.resource_type')
                                ->where('valid_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                                ->where('valid_prices.region_id', $requested->regionId)
                                ->where('valid_prices.price_zone_id', $requested->priceZoneId)
                                ->where('valid_prices.period_id', $requested->periodId)
                                ->where('valid_prices.base_price', '>', 0)
                                ->where(function ($identity): void {
                                    $identity->whereColumn('required_resources.construction_resource_id', 'valid_prices.construction_resource_id')
                                        ->orWhereNull('required_resources.construction_resource_id')
                                        ->orWhereNull('valid_prices.construction_resource_id');
                                });
                        });
                })
                ->where(function ($query) use ($code, $lexicalQuery): void {
                    if ($code !== '') {
                        $query->orWhereRaw('LOWER(norms.code) = ?', [$code]);
                    }
                    $query->orWhereRaw("norms.search_vector @@ websearch_to_tsquery('russian', ?)", [$lexicalQuery]);
                })
                ->select([
                    'norms.id', 'norms.code', 'norms.name', 'norms.canonical_unit', 'norms.unit',
                    'norms.unit_dimension', 'norms.material', 'norms.technology', 'norms.structure',
                    'norms.object_type', 'norms.region_code', 'norms.valid_from', 'norms.valid_to',
                    'norms.section_code', 'norms.section_name', 'norms.work_composition',
                    'collections.code as collection_code', 'collections.name as collection_name', 'collections.norm_type',
                ])
                ->selectRaw("ts_rank_cd(norms.search_vector, websearch_to_tsquery('russian', ?)) AS pin_lexical_score", [$lexicalQuery])
                ->orderByRaw('CASE WHEN LOWER(norms.code) = ? THEN 0 ELSE 1 END', [$code])
                ->orderByDesc('pin_lexical_score')
                ->orderBy('norms.id')
                ->limit(32)
                ->get();
            if ($query->isEmpty()) {
                continue;
            }
            $poolCandidatesCount += $query->count();
            $selectedForIntent = $this->ranker->select($query->all(), [$intent]);
            if ($selectedForIntent !== null) {
                $norms = $norms->concat($selectedForIntent);
            }
        }
        $norms = $norms->unique('id')->values();
        if ($norms->isEmpty()) {
            $this->telemetry('norms_rejected', ['intents_count' => count($intents), 'norms_count' => $poolCandidatesCount]);

            return null;
        }
        $ids = $norms->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $expectedResourceCounts = $this->database->table('estimate_norm_resources')
            ->whereIn('estimate_norm_id', $ids)
            ->groupBy('estimate_norm_id')
            ->get(['estimate_norm_id', $this->database->raw('COUNT(*) AS resource_count')])
            ->mapWithKeys(static fn (object $row): array => [(int) $row->estimate_norm_id => (int) $row->resource_count])
            ->all();
        $resourceRows = $this->database->table('estimate_norm_resources as resources')
            ->join('estimate_resource_prices as prices', function ($join) use ($requested): void {
                $join->on('prices.resource_code', '=', 'resources.resource_code')
                    ->on('prices.price_type', '=', 'resources.resource_type')
                    ->where('prices.regional_price_version_id', $requested->regionalPriceVersionId)
                    ->where('prices.region_id', $requested->regionId)
                    ->where('prices.price_zone_id', $requested->priceZoneId)
                    ->where('prices.period_id', $requested->periodId);
            })
            ->whereIn('resources.estimate_norm_id', $ids)
            ->where('prices.base_price', '>', 0)
            ->where(function ($identity): void {
                $identity->whereColumn('resources.construction_resource_id', 'prices.construction_resource_id')
                    ->orWhereNull('resources.construction_resource_id')
                    ->orWhereNull('prices.construction_resource_id');
            })
            ->orderBy('resources.estimate_norm_id')->orderBy('resources.id')
            ->limit(10_001)
            ->get([
                'resources.id as norm_resource_id', 'resources.estimate_norm_id', 'resources.construction_resource_id', 'resources.resource_code',
                'resources.resource_name', 'resources.unit', 'resources.quantity', 'resources.resource_type',
                'prices.id as price_id', 'prices.construction_resource_id as price_construction_resource_id',
                'prices.resource_code as price_resource_code', 'prices.price_type',
            ]);
        if ($resourceRows->count() > 10_000) {
            $this->telemetry('resources_limit_exceeded', ['selected_count' => $norms->count(), 'resource_rows_count' => $resourceRows->count()]);

            return null;
        }
        $resources = [];
        foreach ($resourceRows as $row) {
            try {
                $mapped = NormativeResourceRowData::fromDatabaseRow($row);
            } catch (\InvalidArgumentException) {
                return null;
            }
            $resources[$mapped->estimateNormId][$mapped->group][] = $mapped->resource;
        }
        $candidates = [];
        foreach ($norms as $norm) {
            $groups = $resources[(int) $norm->id] ?? [];
            $groups = [
                'materials' => $groups['materials'] ?? [], 'labor' => $groups['labor'] ?? [],
                'machinery' => $groups['machinery'] ?? [], 'other' => $groups['other'] ?? [],
            ];
            if (! $this->resourceCoverage->complete((int) ($expectedResourceCounts[(int) $norm->id] ?? 0), $groups)) {
                continue;
            }
            $composition = is_array($norm->work_composition)
                ? $norm->work_composition
                : json_decode((string) $norm->work_composition, true);
            $candidates[] = [
                'candidate_id' => (string) $norm->id, 'normative_id' => (int) $norm->id,
                'dataset_id' => $requested->datasetId, 'dataset_version' => $requested->datasetVersion,
                'dataset_status' => 'parsed', 'code' => (string) $norm->code, 'name' => (string) $norm->name,
                'unit' => (string) ($norm->canonical_unit ?: $norm->unit),
                'retrieval_metadata' => [
                    'unit_dimension' => $norm->unit_dimension, 'material' => $norm->material,
                    'technology' => $norm->technology, 'structure' => $norm->structure,
                    'object_type' => $norm->object_type, 'region_code' => $norm->region_code,
                    'valid_from' => $norm->valid_from, 'valid_to' => $norm->valid_to,
                ],
                'collection' => ['code' => (string) $norm->collection_code, 'name' => (string) $norm->collection_name, 'norm_type' => (string) $norm->norm_type],
                'section' => ['code' => (string) $norm->section_code, 'name' => (string) $norm->section_name],
                'work_composition' => is_array($composition) ? array_values($composition) : [],
                'resources' => $groups,
            ];
        }
        if ($candidates === []) {
            $this->telemetry('priced_candidates_empty', ['selected_count' => $norms->count(), 'resource_rows_count' => $resourceRows->count()]);

            return null;
        }
        $this->telemetry('approved', ['intents_count' => count($intents), 'selected_count' => $norms->count(), 'resource_rows_count' => $resourceRows->count(), 'candidates_count' => count($candidates)]);
        $canonical = json_encode($candidates, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return new NormativeContextPinData(
            $requested->datasetId, $requested->datasetVersion, $requested->applicabilityDate,
            $requested->regionId, $requested->priceZoneId, $requested->periodId,
            $requested->regionalPriceVersionId, $requested->priceVersion,
            $candidates, hash('sha256', $canonical),
        );
    }

    private function telemetry(string $phase, array $context): void
    {
        if (Log::getFacadeRoot() !== null) {
            Log::info('estimate_generation.normative_pin_source', ['phase' => $phase, ...$context]);
        }
    }
}
