<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSemanticCompatibilityService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;

final readonly class EloquentNormativeContextPinSource implements NormativeContextPinSource
{
    private const CANDIDATE_POOL_LIMIT = 300;

    public function __construct(
        private Connection $database,
        private NormativeIntentCandidateRanker $ranker = new NormativeIntentCandidateRanker,
        private NormativeSearchQueryBuilder $queryBuilder = new NormativeSearchQueryBuilder,
        private NormativeResourceCoverage $resourceCoverage = new NormativeResourceCoverage,
        private NormativeSemanticCompatibilityService $semanticCompatibility = new NormativeSemanticCompatibilityService,
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
            ->where('status', 'active')
            ->exists();

        if (! $dataset || ! $prices) {
            $this->telemetry('identity_rejected', ['dataset_ready' => $dataset, 'prices_ready' => $prices]);

            return null;
        }
        if ($intents === [] || count($intents) > 64) {
            $this->telemetry('intents_rejected', ['intents_count' => count($intents)]);

            return null;
        }
        $basePriceDatasetId = $this->database->table('estimate_dataset_versions')
            ->whereIn('source_type', ['fsbc', 'fsnb_2022'])
            ->where('status', 'parsed')
            ->whereExists(function ($resourcePrices): void {
                $resourcePrices->selectRaw('1')
                    ->from('estimate_resource_prices')
                    ->whereColumn('estimate_resource_prices.dataset_version_id', 'estimate_dataset_versions.id')
                    ->whereNull('regional_price_version_id')
                    ->where('base_price', '>', 0);
            })
            ->orderByRaw("CASE WHEN source_type = 'fsbc' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->limit(1)
            ->value('id');
        $basePriceDatasetId = is_numeric($basePriceDatasetId) ? (int) $basePriceDatasetId : 0;
        $norms = collect();
        $poolCandidatesCount = 0;
        foreach ($intents as $intent) {
            $search = mb_strtolower(trim((string) ($intent['search_text'] ?? '')));
            $unit = trim((string) ($intent['unit'] ?? ''));
            $code = mb_strtolower(trim((string) ($intent['code'] ?? '')));
            $normativeSection = trim((string) ($intent['normative_section'] ?? ''));
            $normativeSections = is_array($intent['normative_sections'] ?? null)
                ? array_values(array_filter(
                    $intent['normative_sections'],
                    static fn (mixed $section): bool => is_string($section) && $section !== '',
                ))
                : [];
            if ($normativeSections === [] && $normativeSection !== '') {
                $normativeSections = [$normativeSection];
            }
            if ($search === '' || $unit === '') {
                return null;
            }
            $lexicalQuery = $this->queryBuilder->build($search);
            $actionMarkers = $this->semanticCompatibility->markersForAction((string) ($intent['action'] ?? ''));
            $semanticPrioritySql = '0 AS pin_semantic_priority';
            $semanticPriorityBindings = [];
            if ($actionMarkers !== []) {
                $semanticConditions = [];
                foreach ($actionMarkers as $marker) {
                    $semanticConditions[] = "(LOWER(COALESCE(norms.name, '')) LIKE ? OR LOWER(COALESCE(CAST(norms.work_composition AS TEXT), '')) LIKE ?)";
                    $semanticPriorityBindings[] = '%'.mb_strtolower($marker).'%';
                    $semanticPriorityBindings[] = '%'.mb_strtolower($marker).'%';
                }
                $semanticPrioritySql = 'CASE WHEN '.implode(' OR ', $semanticConditions).' THEN 0 ELSE 1 END AS pin_semantic_priority';
            }
            $query = $this->database->table('estimate_norms as norms')
                ->join('estimate_norm_collections as collections', 'collections.id', '=', 'norms.collection_id')
                ->where('collections.dataset_version_id', $requested->datasetId)
                ->when($normativeSections !== [], static function ($sectionQuery) use ($normativeSections): void {
                    $sectionQuery->where(static function ($allowedSections) use ($normativeSections): void {
                        foreach ($normativeSections as $index => $section) {
                            $method = $index === 0 ? 'where' : 'orWhere';
                            $allowedSections->{$method}('norms.section_code', 'like', $section.'%');
                        }
                    });
                })
                ->whereExists(function ($priced) use ($requested, $basePriceDatasetId): void {
                    $priced->selectRaw('1')
                        ->from('estimate_norm_resources as pin_resources')
                        ->join('estimate_resource_prices as pin_prices', function ($join) use ($requested, $basePriceDatasetId): void {
                            $join->on('pin_prices.resource_code', '=', 'pin_resources.resource_code')
                                ->where(function ($priceContext) use ($requested, $basePriceDatasetId): void {
                                    $priceContext->where(function ($regional) use ($requested): void {
                                        $regional->where('pin_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                                            ->where('pin_prices.region_id', $requested->regionId)
                                            ->where('pin_prices.price_zone_id', $requested->priceZoneId)
                                            ->where('pin_prices.period_id', $requested->periodId);
                                    })->orWhere(function ($base) use ($basePriceDatasetId): void {
                                        $base->where('pin_prices.dataset_version_id', $basePriceDatasetId)
                                            ->whereNull('pin_prices.regional_price_version_id');
                                    });
                                });
                        })
                        ->whereColumn('pin_resources.estimate_norm_id', 'norms.id')
                        ->where('pin_resources.quantity', '>', 0)
                        ->where('pin_resources.resource_type', '<>', 'summary')
                        ->where('pin_prices.base_price', '>', 0)
                        ->where(function ($compatibleUnit): void {
                            $compatibleUnit->whereRaw('pin_prices.unit IS NOT DISTINCT FROM pin_resources.unit')
                                ->orWhereExists(function ($conversion): void {
                                    $conversion->selectRaw('1')
                                        ->from('estimate_generation_unit_conversions as pin_conversions')
                                        ->whereColumn('pin_conversions.from_unit', 'pin_resources.unit')
                                        ->whereColumn('pin_conversions.to_unit', 'pin_prices.unit')
                                        ->where('pin_conversions.version', 1)
                                        ->where('pin_conversions.is_active', true)
                                        ->where('pin_conversions.factor', '>', 0);
                                });
                        });
                })
                ->whereExists(function ($positiveQuantity): void {
                    $positiveQuantity->selectRaw('1')
                        ->from('estimate_norm_resources as positive_resources')
                        ->whereColumn('positive_resources.estimate_norm_id', 'norms.id')
                        ->where('positive_resources.quantity', '>', 0)
                        ->where('positive_resources.resource_type', '<>', 'summary');
                })
                ->whereNotExists(function ($negativeQuantity): void {
                    $negativeQuantity->selectRaw('1')
                        ->from('estimate_norm_resources as negative_resources')
                        ->whereColumn('negative_resources.estimate_norm_id', 'norms.id')
                        ->where('negative_resources.quantity', '<', 0);
                })
                ->whereNotExists(function ($unpriced) use ($requested, $basePriceDatasetId): void {
                    $unpriced->selectRaw('1')
                        ->from('estimate_norm_resources as required_resources')
                        ->whereColumn('required_resources.estimate_norm_id', 'norms.id')
                        ->where('required_resources.quantity', '>', 0)
                        ->where('required_resources.resource_type', '<>', 'summary')
                        ->whereNotExists(function ($validPrice) use ($requested, $basePriceDatasetId): void {
                            $validPrice->selectRaw('1')
                                ->from('estimate_resource_prices as valid_prices')
                                ->whereColumn('valid_prices.resource_code', 'required_resources.resource_code')
                                ->where(function ($priceContext) use ($requested, $basePriceDatasetId): void {
                                    $priceContext->where(function ($regional) use ($requested): void {
                                        $regional->where('valid_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                                            ->where('valid_prices.region_id', $requested->regionId)
                                            ->where('valid_prices.price_zone_id', $requested->priceZoneId)
                                            ->where('valid_prices.period_id', $requested->periodId);
                                    })->orWhere(function ($base) use ($basePriceDatasetId): void {
                                        $base->where('valid_prices.dataset_version_id', $basePriceDatasetId)
                                            ->whereNull('valid_prices.regional_price_version_id');
                                    });
                                })
                                ->where('valid_prices.base_price', '>', 0)
                                ->where(function ($compatibleUnit): void {
                                    $compatibleUnit->whereRaw('valid_prices.unit IS NOT DISTINCT FROM required_resources.unit')
                                        ->orWhereExists(function ($conversion): void {
                                            $conversion->selectRaw('1')
                                                ->from('estimate_generation_unit_conversions as valid_conversions')
                                                ->whereColumn('valid_conversions.from_unit', 'required_resources.unit')
                                                ->whereColumn('valid_conversions.to_unit', 'valid_prices.unit')
                                                ->where('valid_conversions.version', 1)
                                                ->where('valid_conversions.is_active', true)
                                                ->where('valid_conversions.factor', '>', 0);
                                        });
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
                ->selectRaw($semanticPrioritySql, $semanticPriorityBindings)
                ->orderByRaw('CASE WHEN LOWER(norms.code) = ? THEN 0 ELSE 1 END', [$code])
                ->orderBy('pin_semantic_priority')
                ->orderByDesc('pin_lexical_score')
                ->orderBy('norms.id')
                ->limit(self::CANDIDATE_POOL_LIMIT)
                ->get();
            if ($query->isEmpty()) {
                $this->telemetry('intent_candidates_empty', [
                    'search_text' => $search,
                    'action' => $intent['action'] ?? null,
                    'unit' => $unit,
                    'normative_sections' => $normativeSections,
                ]);

                continue;
            }
            $poolCandidatesCount += $query->count();
            $selectedForIntent = $this->ranker->select($query->all(), [$intent]);
            if ($selectedForIntent !== null) {
                $norms = $norms->concat($selectedForIntent);
            } else {
                $this->telemetry('intent_candidates_rejected', [
                    'search_text' => $search,
                    'action' => $intent['action'] ?? null,
                    'unit' => $unit,
                    'normative_sections' => $normativeSections,
                    'candidates' => $query->take(8)->map(static fn (object $candidate): array => [
                        'code' => (string) $candidate->code,
                        'name' => (string) $candidate->name,
                        'unit' => (string) ($candidate->canonical_unit ?: $candidate->unit),
                        'section' => (string) $candidate->section_code,
                    ])->all(),
                ]);
            }
        }
        $norms = $norms->unique('id')->values();
        if ($norms->isEmpty()) {
            $this->telemetry('norms_rejected', [
                'intents_count' => count($intents),
                'norms_count' => $poolCandidatesCount,
                ...$this->coverageDiagnostics($requested, $basePriceDatasetId, $intents),
            ]);

            return null;
        }
        $ids = $norms->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $expectedResourceCounts = $this->database->table('estimate_norm_resources')
            ->whereIn('estimate_norm_id', $ids)
            ->where('quantity', '>', 0)
            ->where('resource_type', '<>', 'summary')
            ->groupBy('estimate_norm_id')
            ->get(['estimate_norm_id', $this->database->raw('COUNT(*) AS resource_count')])
            ->mapWithKeys(static fn (object $row): array => [(int) $row->estimate_norm_id => (int) $row->resource_count])
            ->all();
        $resourceRows = $this->database->table('estimate_norm_resources as resources')
            ->join('estimate_resource_prices as prices', function ($join) use ($requested, $basePriceDatasetId): void {
                $join->on('prices.resource_code', '=', 'resources.resource_code')
                    ->where(function ($priceContext) use ($requested, $basePriceDatasetId): void {
                        $priceContext->where(function ($regional) use ($requested): void {
                            $regional->where('prices.regional_price_version_id', $requested->regionalPriceVersionId)
                                ->where('prices.region_id', $requested->regionId)
                                ->where('prices.price_zone_id', $requested->priceZoneId)
                                ->where('prices.period_id', $requested->periodId);
                        })->orWhere(function ($base) use ($basePriceDatasetId): void {
                            $base->where('prices.dataset_version_id', $basePriceDatasetId)
                                ->whereNull('prices.regional_price_version_id');
                        });
                    });
            })
            ->leftJoin('estimate_dataset_versions as price_datasets', 'price_datasets.id', '=', 'prices.dataset_version_id')
            ->leftJoin('estimate_regional_price_versions as price_regional_versions', 'price_regional_versions.id', '=', 'prices.regional_price_version_id')
            ->whereIn('resources.estimate_norm_id', $ids)
            ->where('resources.quantity', '>', 0)
            ->where('resources.resource_type', '<>', 'summary')
            ->where('prices.base_price', '>', 0)
            ->whereRaw(
                'prices.id = (SELECT candidate_prices.id FROM estimate_resource_prices AS candidate_prices
                    WHERE candidate_prices.resource_code = resources.resource_code
                      AND ((candidate_prices.regional_price_version_id = ?
                        AND candidate_prices.region_id = ? AND candidate_prices.price_zone_id = ?
                        AND candidate_prices.period_id = ?)
                        OR (candidate_prices.dataset_version_id = ? AND candidate_prices.regional_price_version_id IS NULL))
                      AND candidate_prices.base_price > 0
                      AND (candidate_prices.unit IS NOT DISTINCT FROM resources.unit OR EXISTS (
                          SELECT 1 FROM estimate_generation_unit_conversions AS candidate_conversions
                          WHERE candidate_conversions.from_unit = resources.unit
                            AND candidate_conversions.to_unit = candidate_prices.unit
                            AND candidate_conversions.version = 1
                            AND candidate_conversions.is_active = TRUE
                            AND candidate_conversions.factor > 0
                      ))
                    ORDER BY CASE WHEN candidate_prices.regional_price_version_id = ? THEN 0 ELSE 1 END,
                      CASE WHEN candidate_prices.unit IS NOT DISTINCT FROM resources.unit THEN 0 ELSE 1 END, candidate_prices.id
                    LIMIT 1)',
                [$requested->regionalPriceVersionId, $requested->regionId, $requested->priceZoneId, $requested->periodId, $basePriceDatasetId, $requested->regionalPriceVersionId],
            )
            ->orderBy('resources.estimate_norm_id')->orderBy('resources.id')
            ->limit(10_001)
            ->get([
                'resources.id as norm_resource_id', 'resources.estimate_norm_id', 'resources.construction_resource_id', 'resources.resource_code',
                'resources.resource_name', 'resources.unit', 'resources.quantity', 'resources.resource_type',
                'prices.id as price_id', 'prices.construction_resource_id as price_construction_resource_id',
                'prices.resource_code as price_resource_code', 'prices.price_type', 'prices.unit as price_unit',
                'prices.base_price as unit_price', 'prices.regional_price_version_id',
                'price_regional_versions.version_key as regional_price_version_key',
                'price_datasets.source_type as price_dataset_source_type',
                'price_datasets.version_key as price_dataset_version',
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

    private function coverageDiagnostics(NormativeContextPinData $requested, int $basePriceDatasetId, array $intents): array
    {
        $eligible = $this->database->table('estimate_norm_resources as diagnostic_resources')
            ->join('estimate_norms as diagnostic_norms', 'diagnostic_norms.id', '=', 'diagnostic_resources.estimate_norm_id')
            ->join('estimate_norm_collections as diagnostic_collections', 'diagnostic_collections.id', '=', 'diagnostic_norms.collection_id')
            ->where('diagnostic_collections.dataset_version_id', $requested->datasetId)
            ->where('diagnostic_resources.quantity', '>', 0)
            ->where('diagnostic_resources.resource_type', '<>', 'summary');
        $codeMatched = (clone $eligible)->whereExists(function ($prices) use ($requested, $basePriceDatasetId): void {
            $prices->selectRaw('1')
                ->from('estimate_resource_prices as diagnostic_prices')
                ->whereColumn('diagnostic_prices.resource_code', 'diagnostic_resources.resource_code')
                ->where('diagnostic_prices.base_price', '>', 0)
                ->where(function ($context) use ($requested, $basePriceDatasetId): void {
                    $context->where(function ($regional) use ($requested): void {
                        $regional->where('diagnostic_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                            ->where('diagnostic_prices.region_id', $requested->regionId)
                            ->where('diagnostic_prices.price_zone_id', $requested->priceZoneId)
                            ->where('diagnostic_prices.period_id', $requested->periodId);
                    })->orWhere(function ($base) use ($basePriceDatasetId): void {
                        $base->where('diagnostic_prices.dataset_version_id', $basePriceDatasetId)
                            ->whereNull('diagnostic_prices.regional_price_version_id');
                    });
                });
        });
        $unitMatched = (clone $codeMatched)->whereExists(function ($prices) use ($requested, $basePriceDatasetId): void {
            $prices->selectRaw('1')
                ->from('estimate_resource_prices as diagnostic_unit_prices')
                ->whereColumn('diagnostic_unit_prices.resource_code', 'diagnostic_resources.resource_code')
                ->where('diagnostic_unit_prices.base_price', '>', 0)
                ->where(function ($context) use ($requested, $basePriceDatasetId): void {
                    $context->where(function ($regional) use ($requested): void {
                        $regional->where('diagnostic_unit_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                            ->where('diagnostic_unit_prices.region_id', $requested->regionId)
                            ->where('diagnostic_unit_prices.price_zone_id', $requested->priceZoneId)
                            ->where('diagnostic_unit_prices.period_id', $requested->periodId);
                    })->orWhere(function ($base) use ($basePriceDatasetId): void {
                        $base->where('diagnostic_unit_prices.dataset_version_id', $basePriceDatasetId)
                            ->whereNull('diagnostic_unit_prices.regional_price_version_id');
                    });
                })
                ->whereRaw('diagnostic_unit_prices.unit IS NOT DISTINCT FROM diagnostic_resources.unit');
        });
        $normalizedUnitMatched = (clone $codeMatched)->whereExists(function ($prices) use ($requested, $basePriceDatasetId): void {
            $prices->selectRaw('1')
                ->from('estimate_resource_prices as diagnostic_normalized_prices')
                ->whereColumn('diagnostic_normalized_prices.resource_code', 'diagnostic_resources.resource_code')
                ->where('diagnostic_normalized_prices.base_price', '>', 0)
                ->where(function ($context) use ($requested, $basePriceDatasetId): void {
                    $context->where(function ($regional) use ($requested): void {
                        $regional->where('diagnostic_normalized_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                            ->where('diagnostic_normalized_prices.region_id', $requested->regionId)
                            ->where('diagnostic_normalized_prices.price_zone_id', $requested->priceZoneId)
                            ->where('diagnostic_normalized_prices.period_id', $requested->periodId);
                    })->orWhere(function ($base) use ($basePriceDatasetId): void {
                        $base->where('diagnostic_normalized_prices.dataset_version_id', $basePriceDatasetId)
                            ->whereNull('diagnostic_normalized_prices.regional_price_version_id');
                    });
                })
                ->whereRaw(
                    "LOWER(REGEXP_REPLACE(COALESCE(diagnostic_normalized_prices.unit, ''), '[[:space:].,-]+', '', 'g')) = LOWER(REGEXP_REPLACE(COALESCE(diagnostic_resources.unit, ''), '[[:space:].,-]+', '', 'g'))"
                );
        });
        $diagnosticIntent = $intents[0] ?? [];
        foreach ($intents as $intent) {
            if (str_contains(mb_strtolower((string) ($intent['search_text'] ?? '')), 'разработка грунта')) {
                $diagnosticIntent = $intent;

                break;
            }
        }
        $diagnosticSearchText = mb_strtolower(trim((string) ($diagnosticIntent['search_text'] ?? '')));
        $diagnosticLexicalCandidatesCount = 0;
        if ($diagnosticSearchText !== '') {
            $diagnosticLexicalQuery = $this->queryBuilder->build($diagnosticSearchText);
            $diagnosticLexicalCandidatesCount = $this->database->table('estimate_norms as diagnostic_candidate_norms')
                ->join('estimate_norm_collections as diagnostic_candidate_collections', 'diagnostic_candidate_collections.id', '=', 'diagnostic_candidate_norms.collection_id')
                ->where('diagnostic_candidate_collections.dataset_version_id', $requested->datasetId)
                ->whereRaw("diagnostic_candidate_norms.search_vector @@ websearch_to_tsquery('russian', ?)", [$diagnosticLexicalQuery])
                ->count();
        }
        $unmatchedUnitPairs = $this->database->table('estimate_norm_resources as diagnostic_pair_resources')
            ->join('estimate_norms as diagnostic_pair_norms', 'diagnostic_pair_norms.id', '=', 'diagnostic_pair_resources.estimate_norm_id')
            ->join('estimate_norm_collections as diagnostic_pair_collections', 'diagnostic_pair_collections.id', '=', 'diagnostic_pair_norms.collection_id')
            ->join('estimate_resource_prices as diagnostic_pair_prices', 'diagnostic_pair_prices.resource_code', '=', 'diagnostic_pair_resources.resource_code')
            ->where('diagnostic_pair_collections.dataset_version_id', $requested->datasetId)
            ->where('diagnostic_pair_resources.quantity', '>', 0)
            ->where('diagnostic_pair_resources.resource_type', '<>', 'summary')
            ->where('diagnostic_pair_prices.base_price', '>', 0)
            ->where(function ($context) use ($requested, $basePriceDatasetId): void {
                $context->where(function ($regional) use ($requested): void {
                    $regional->where('diagnostic_pair_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                        ->where('diagnostic_pair_prices.region_id', $requested->regionId)
                        ->where('diagnostic_pair_prices.price_zone_id', $requested->priceZoneId)
                        ->where('diagnostic_pair_prices.period_id', $requested->periodId);
                })->orWhere(function ($base) use ($basePriceDatasetId): void {
                    $base->where('diagnostic_pair_prices.dataset_version_id', $basePriceDatasetId)
                        ->whereNull('diagnostic_pair_prices.regional_price_version_id');
                });
            })
            ->whereRaw('diagnostic_pair_prices.unit IS DISTINCT FROM diagnostic_pair_resources.unit')
            ->selectRaw("COALESCE(diagnostic_pair_resources.unit, '<null>') as norm_unit, COALESCE(diagnostic_pair_prices.unit, '<null>') as price_unit, diagnostic_pair_resources.resource_type, diagnostic_pair_prices.price_type, COUNT(*) as rows_count")
            ->groupBy('diagnostic_pair_resources.unit', 'diagnostic_pair_prices.unit', 'diagnostic_pair_resources.resource_type', 'diagnostic_pair_prices.price_type')
            ->orderByDesc('rows_count')
            ->limit(16)
            ->get()
            ->map(static fn (object $row): array => [
                'norm_unit' => (string) $row->norm_unit,
                'price_unit' => (string) $row->price_unit,
                'resource_type' => (string) $row->resource_type,
                'price_type' => (string) $row->price_type,
                'rows_count' => (int) $row->rows_count,
            ])
            ->all();

        return [
            'base_price_dataset_id' => $basePriceDatasetId,
            'eligible_resource_rows_count' => (clone $eligible)->count(),
            'code_matched_resource_rows_count' => $codeMatched->count(),
            'exact_unit_matched_resource_rows_count' => $unitMatched->count(),
            'normalized_unit_matched_resource_rows_count' => $normalizedUnitMatched->count(),
            'diagnostic_intent_search_text' => $diagnosticSearchText,
            'diagnostic_lexical_candidates_count' => $diagnosticLexicalCandidatesCount,
            'unmatched_unit_pairs' => $unmatchedUnitPairs,
        ];
    }
}
