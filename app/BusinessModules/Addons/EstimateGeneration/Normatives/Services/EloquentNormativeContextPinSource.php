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
        private NormativeCandidatePriceCoverageAnalyzer $priceCoverageAnalyzer = new NormativeCandidatePriceCoverageAnalyzer,
        private AbstractResourceProjectPriceSelector $abstractResourceProjectPriceSelector = new AbstractResourceProjectPriceSelector,
        private AbstractResourceCoverageDiagnostics $abstractResourceCoverageDiagnostics = new AbstractResourceCoverageDiagnostics,
        private AbstractResourceSemanticPriceSelector $abstractResourceSemanticPriceSelector = new AbstractResourceSemanticPriceSelector,
        private ResidentialAbstractResourcePriceSelector $residentialAbstractResourcePriceSelector = new ResidentialAbstractResourcePriceSelector,
        private ResidentialProjectMaterialCatalog $projectMaterials = new ResidentialProjectMaterialCatalog,
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
        $fsbcBasePriceDatasetId = $this->latestPriceDatasetId('fsbc', true);
        $fgisLaborPriceDatasetId = $this->latestPriceDatasetId('fgis_labor_prices', false);
        $basePriceDatasetIds = array_values(array_unique(array_filter([
            $fgisLaborPriceDatasetId,
            $fsbcBasePriceDatasetId,
            $requested->datasetId,
        ], static fn (int $id): bool => $id > 0)));
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
                ->whereExists(function ($priced) use ($requested, $basePriceDatasetIds): void {
                    $priced->selectRaw('1')
                        ->from('estimate_norm_resources as pin_resources')
                        ->join('estimate_resource_prices as pin_prices', function ($join) use ($requested, $basePriceDatasetIds): void {
                            $join->where(function ($resourceRelation): void {
                                $resourceRelation->whereColumn('pin_prices.resource_code', 'pin_resources.resource_code')
                                    ->orWhere(function ($projectResource): void {
                                        $projectResource->whereRaw("LOWER(COALESCE(pin_resources.raw_payload->>'source_tag', '')) = 'abstractresource'")
                                            ->whereRaw("pin_resources.resource_code ~ '^[0-9]{2}\\.[0-9]\\.[0-9]{2}\\.[0-9]{2}$'")
                                            ->where(function ($resourceGroup): void {
                                                $resourceGroup->whereRaw("pin_prices.resource_code LIKE (pin_resources.resource_code || '-____')");
                                                foreach ($this->residentialAbstractResourcePriceSelector->supportedCandidateGroups() as $group) {
                                                    if ($group['candidate_group_code'] === $group['group_code']) {
                                                        continue;
                                                    }
                                                    $resourceGroup->orWhere(function ($mappedGroup) use ($group): void {
                                                        $mappedGroup->where('pin_resources.resource_code', $group['group_code'])
                                                            ->where('pin_prices.resource_code', 'like', $group['candidate_group_code'].'-____');
                                                    });
                                                }
                                            })
                                            ->whereRaw("RIGHT(pin_prices.resource_code, 4) ~ '^[0-9]{4}$'");
                                    });
                            })->where(function ($priceContext) use ($requested, $basePriceDatasetIds): void {
                                $priceContext->where(function ($regional) use ($requested): void {
                                    $regional->where('pin_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                                        ->where('pin_prices.region_id', $requested->regionId)
                                        ->where('pin_prices.price_zone_id', $requested->priceZoneId)
                                        ->where('pin_prices.period_id', $requested->periodId);
                                })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                                    $base->whereIn('pin_prices.dataset_version_id', $basePriceDatasetIds)
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
                                ->orWhereRaw(
                                    "LOWER(REGEXP_REPLACE(COALESCE(pin_prices.unit, ''), '[[:space:].,-]+', '', 'g')) = LOWER(REGEXP_REPLACE(COALESCE(pin_resources.unit, ''), '[[:space:].,-]+', '', 'g'))"
                                )
                                ->orWhereExists(function ($conversion): void {
                                    $conversion->selectRaw('1')
                                        ->from('estimate_generation_unit_conversions as pin_conversions')
                                        ->whereColumn('pin_conversions.from_unit', 'pin_resources.unit')
                                        ->whereColumn('pin_conversions.to_unit', 'pin_prices.unit')
                                        ->where('pin_conversions.version', 1)
                                        ->where('pin_conversions.is_active', true)
                                        ->where('pin_conversions.factor', '>', 0);
                                })
                                ->orWhere(function ($residentialConversion): void {
                                    foreach ($this->residentialAbstractResourcePriceSelector->supportedUnitPairs() as $index => $pair) {
                                        $method = $index === 0 ? 'where' : 'orWhere';
                                        $residentialConversion->{$method}(function ($supported) use ($pair): void {
                                            $supported->where('pin_resources.resource_code', $pair['group_code'])
                                                ->where('pin_prices.unit', $pair['from_unit']);
                                        });
                                    }
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
                ->whereNotExists(function ($unpriced) use ($requested, $basePriceDatasetIds): void {
                    $unpriced->selectRaw('1')
                        ->from('estimate_norm_resources as required_resources')
                        ->whereColumn('required_resources.estimate_norm_id', 'norms.id')
                        ->where('required_resources.quantity', '>', 0)
                        ->where('required_resources.resource_type', '<>', 'summary')
                        ->whereRaw("LOWER(COALESCE(required_resources.raw_payload->>'source_tag', '')) <> 'abstractresource'")
                        ->whereNotExists(function ($validPrice) use ($requested, $basePriceDatasetIds): void {
                            $validPrice->selectRaw('1')
                                ->from('estimate_resource_prices as valid_prices')
                                ->whereColumn('valid_prices.resource_code', 'required_resources.resource_code')
                                ->where(function ($priceContext) use ($requested, $basePriceDatasetIds): void {
                                    $priceContext->where(function ($regional) use ($requested): void {
                                        $regional->where('valid_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                                            ->where('valid_prices.region_id', $requested->regionId)
                                            ->where('valid_prices.price_zone_id', $requested->priceZoneId)
                                            ->where('valid_prices.period_id', $requested->periodId);
                                    })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                                        $base->whereIn('valid_prices.dataset_version_id', $basePriceDatasetIds)
                                            ->whereNull('valid_prices.regional_price_version_id');
                                    });
                                })
                                ->where('valid_prices.base_price', '>', 0)
                                ->where(function ($compatibleUnit): void {
                                    $compatibleUnit->whereRaw('valid_prices.unit IS NOT DISTINCT FROM required_resources.unit')
                                        ->orWhereRaw(
                                            "LOWER(REGEXP_REPLACE(COALESCE(valid_prices.unit, ''), '[[:space:].,-]+', '', 'g')) = LOWER(REGEXP_REPLACE(COALESCE(required_resources.unit, ''), '[[:space:].,-]+', '', 'g'))"
                                        )
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
                $this->telemetryPrePriceCandidates(
                    $requested,
                    $basePriceDatasetIds,
                    $intent,
                    $lexicalQuery,
                    $code,
                    $normativeSections,
                    $semanticPrioritySql,
                    $semanticPriorityBindings,
                );
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
                $this->telemetryPrePriceCandidates(
                    $requested,
                    $basePriceDatasetIds,
                    $intent,
                    $lexicalQuery,
                    $code,
                    $normativeSections,
                    $semanticPrioritySql,
                    $semanticPriorityBindings,
                );
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
                ...$this->coverageDiagnostics($requested, $basePriceDatasetIds, $intents),
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
        $basePricePlaceholders = implode(', ', array_fill(0, count($basePriceDatasetIds), '?'));
        $normalizedCandidateUnitSql = "LOWER(REGEXP_REPLACE(COALESCE(candidate_prices.unit, ''), '[[:space:].,-]+', '', 'g')) = LOWER(REGEXP_REPLACE(COALESCE(resources.unit, ''), '[[:space:].,-]+', '', 'g'))";
        $resourceRows = $this->database->table('estimate_norm_resources as resources')
            ->join('estimate_resource_prices as prices', function ($join) use ($requested, $basePriceDatasetIds): void {
                $join->on('prices.resource_code', '=', 'resources.resource_code')
                    ->where(function ($priceContext) use ($requested, $basePriceDatasetIds): void {
                        $priceContext->where(function ($regional) use ($requested): void {
                            $regional->where('prices.regional_price_version_id', $requested->regionalPriceVersionId)
                                ->where('prices.region_id', $requested->regionId)
                                ->where('prices.price_zone_id', $requested->priceZoneId)
                                ->where('prices.period_id', $requested->periodId);
                        })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                            $base->whereIn('prices.dataset_version_id', $basePriceDatasetIds)
                                ->whereNull('prices.regional_price_version_id');
                        });
                    });
            })
            ->leftJoin('estimate_dataset_versions as price_datasets', 'price_datasets.id', '=', 'prices.dataset_version_id')
            ->leftJoin('estimate_regional_price_versions as price_regional_versions', 'price_regional_versions.id', '=', 'prices.regional_price_version_id')
            ->whereIn('resources.estimate_norm_id', $ids)
            ->where('resources.quantity', '>', 0)
            ->where('resources.resource_type', '<>', 'summary')
            ->whereRaw("LOWER(COALESCE(resources.raw_payload->>'source_tag', '')) <> 'abstractresource'")
            ->where('prices.base_price', '>', 0)
            ->whereRaw(
                'prices.id = (SELECT candidate_prices.id FROM estimate_resource_prices AS candidate_prices
                    WHERE candidate_prices.resource_code = resources.resource_code
                      AND ((candidate_prices.regional_price_version_id = ?
                        AND candidate_prices.region_id = ? AND candidate_prices.price_zone_id = ?
                        AND candidate_prices.period_id = ?)
                        OR (candidate_prices.dataset_version_id IN ('.$basePricePlaceholders.') AND candidate_prices.regional_price_version_id IS NULL))
                      AND candidate_prices.base_price > 0
                      AND (candidate_prices.unit IS NOT DISTINCT FROM resources.unit
                        OR '.$normalizedCandidateUnitSql.'
                        OR EXISTS (
                          SELECT 1 FROM estimate_generation_unit_conversions AS candidate_conversions
                          WHERE candidate_conversions.from_unit = resources.unit
                            AND candidate_conversions.to_unit = candidate_prices.unit
                            AND candidate_conversions.version = 1
                            AND candidate_conversions.is_active = TRUE
                            AND candidate_conversions.factor > 0
                      ))
                    ORDER BY CASE WHEN candidate_prices.regional_price_version_id = ? THEN 0 ELSE 1 END,
                      CASE WHEN candidate_prices.dataset_version_id = ? THEN 0 ELSE 1 END,
                      CASE WHEN candidate_prices.dataset_version_id = ? THEN 0 ELSE 1 END,
                      CASE WHEN candidate_prices.unit IS NOT DISTINCT FROM resources.unit THEN 0 ELSE 1 END, candidate_prices.id
                    LIMIT 1)',
                [
                    $requested->regionalPriceVersionId,
                    $requested->regionId,
                    $requested->priceZoneId,
                    $requested->periodId,
                    ...$basePriceDatasetIds,
                    $requested->regionalPriceVersionId,
                    $fgisLaborPriceDatasetId,
                    $fsbcBasePriceDatasetId,
                ],
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
        $abstractResourceRows = $this->database->table('estimate_norm_resources as resources')
            ->join('estimate_resource_prices as prices', function ($join) use ($requested, $basePriceDatasetIds): void {
                $join->where(function ($resourceGroup): void {
                    $resourceGroup->whereRaw("prices.resource_code LIKE (resources.resource_code || '-____')");
                    foreach ($this->residentialAbstractResourcePriceSelector->supportedCandidateGroups() as $group) {
                        if ($group['candidate_group_code'] === $group['group_code']) {
                            continue;
                        }
                        $resourceGroup->orWhere(function ($mappedGroup) use ($group): void {
                            $mappedGroup->where('resources.resource_code', $group['group_code'])
                                ->where('prices.resource_code', 'like', $group['candidate_group_code'].'-____');
                        });
                    }
                })
                    ->whereRaw("RIGHT(prices.resource_code, 4) ~ '^[0-9]{4}$'")
                    ->where(function ($priceContext) use ($requested, $basePriceDatasetIds): void {
                        $priceContext->where(function ($regional) use ($requested): void {
                            $regional->where('prices.regional_price_version_id', $requested->regionalPriceVersionId)
                                ->where('prices.region_id', $requested->regionId)
                                ->where('prices.price_zone_id', $requested->priceZoneId)
                                ->where('prices.period_id', $requested->periodId);
                        })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                            $base->whereIn('prices.dataset_version_id', $basePriceDatasetIds)
                                ->whereNull('prices.regional_price_version_id');
                        });
                    });
            })
            ->leftJoin('estimate_dataset_versions as price_datasets', 'price_datasets.id', '=', 'prices.dataset_version_id')
            ->leftJoin('estimate_regional_price_versions as price_regional_versions', 'price_regional_versions.id', '=', 'prices.regional_price_version_id')
            ->whereIn('resources.estimate_norm_id', $ids)
            ->where('resources.quantity', '>', 0)
            ->where('resources.resource_type', '<>', 'summary')
            ->whereRaw("LOWER(COALESCE(resources.raw_payload->>'source_tag', '')) = 'abstractresource'")
            ->whereRaw("resources.resource_code ~ '^[0-9]{2}\\.[0-9]\\.[0-9]{2}\\.[0-9]{2}$'")
            ->where('prices.base_price', '>', 0)
            ->where(function ($compatibleUnit): void {
                $compatibleUnit->whereRaw('prices.unit IS NOT DISTINCT FROM resources.unit')
                    ->orWhereRaw(
                        "LOWER(REGEXP_REPLACE(COALESCE(prices.unit, ''), '[[:space:].,-]+', '', 'g')) = LOWER(REGEXP_REPLACE(COALESCE(resources.unit, ''), '[[:space:].,-]+', '', 'g'))"
                    )
                    ->orWhereExists(function ($conversion): void {
                        $conversion->selectRaw('1')
                            ->from('estimate_generation_unit_conversions as abstract_conversions')
                            ->whereColumn('abstract_conversions.from_unit', 'resources.unit')
                            ->whereColumn('abstract_conversions.to_unit', 'prices.unit')
                            ->where('abstract_conversions.version', 1)
                            ->where('abstract_conversions.is_active', true)
                            ->where('abstract_conversions.factor', '>', 0);
                    })
                    ->orWhere(function ($residentialConversion): void {
                        foreach ($this->residentialAbstractResourcePriceSelector->supportedUnitPairs() as $index => $pair) {
                            $method = $index === 0 ? 'where' : 'orWhere';
                            $residentialConversion->{$method}(function ($supported) use ($pair): void {
                                $supported->where('resources.resource_code', $pair['group_code'])
                                    ->where('prices.unit', $pair['from_unit']);
                            });
                        }
                    });
            })
            ->orderBy('resources.estimate_norm_id')
            ->orderBy('resources.id')
            ->orderBy('prices.base_price')
            ->orderBy('prices.resource_code')
            ->orderBy('prices.id')
            ->limit(10_001)
            ->get([
                'resources.id as norm_resource_id', 'resources.estimate_norm_id', 'resources.construction_resource_id', 'resources.resource_code',
                'resources.resource_name', 'resources.unit', 'resources.quantity', 'resources.resource_type',
                'prices.id as price_id', 'prices.dataset_version_id', 'prices.construction_resource_id as price_construction_resource_id',
                'prices.resource_code as price_resource_code', 'prices.resource_name as price_resource_name',
                'prices.price_type', 'prices.unit as price_unit', 'prices.base_price as unit_price',
                'prices.base_price', 'prices.regional_price_version_id',
                'price_regional_versions.version_key as regional_price_version_key',
                'price_datasets.source_type as price_dataset_source_type',
                'price_datasets.version_key as price_dataset_version',
                $this->database->raw("'AbstractResource' AS raw_source_tag"),
            ]);
        if ($resourceRows->count() + $abstractResourceRows->count() > 10_000) {
            $this->telemetry('resources_limit_exceeded', [
                'selected_count' => $norms->count(),
                'resource_rows_count' => $resourceRows->count() + $abstractResourceRows->count(),
            ]);

            return null;
        }
        $selectedAbstractRows = collect();
        $normsById = $norms->keyBy('id');
        foreach ($abstractResourceRows->groupBy('norm_resource_id') as $candidateRows) {
            $candidateRowList = $candidateRows->values()->all();
            $representative = $candidateRowList[0] ?? null;
            if (! is_object($representative)) {
                continue;
            }
            $norm = $normsById->get((int) $representative->estimate_norm_id);
            $selection = $this->abstractResourceProjectPriceSelector->select(
                $intents,
                is_object($norm) ? trim((string) $norm->code) : '',
                is_object($norm) ? (string) $norm->name : '',
                trim((string) $representative->resource_code),
                trim((string) ($representative->resource_name ?? '')),
                $requested->regionalPriceVersionId,
                $candidateRowList,
                $basePriceDatasetIds,
            );
            if ($selection === null) {
                $this->telemetry('abstract_resource_candidates_rejected', [
                    'norm_code' => is_object($norm) ? trim((string) $norm->code) : '',
                    'norm_name' => is_object($norm) ? trim((string) $norm->name) : '',
                    'group_code' => trim((string) $representative->resource_code),
                    'group_name' => trim((string) ($representative->resource_name ?? '')),
                    'candidates' => array_map(static fn (object $candidate): array => [
                        'resource_code' => trim((string) ($candidate->price_resource_code ?? '')),
                        'resource_name' => trim((string) ($candidate->price_resource_name ?? '')),
                        'unit' => trim((string) ($candidate->price_unit ?? '')),
                        'base_price' => is_numeric($candidate->base_price ?? null)
                            ? (float) $candidate->base_price
                            : null,
                        'source_type' => trim((string) ($candidate->price_dataset_source_type ?? '')),
                        'regional_price_version_id' => isset($candidate->regional_price_version_id)
                            ? (int) $candidate->regional_price_version_id
                            : null,
                    ], array_slice($candidateRowList, 0, 20)),
                ]);

                continue;
            }
            $selection['row']->project_resource_candidates_count = $selection['candidates_count'];
            $selection['row']->project_resource_price_policy = $selection['policy'];
            if (isset($selection['assumption'])) {
                $selection['row']->project_resource_conversion_assumption = $selection['assumption'];
            }
            $selectedAbstractRows->push($selection['row']);
        }
        $abstractDefinitions = $this->database->table('estimate_norm_resources')
            ->whereIn('estimate_norm_id', $ids)
            ->where('quantity', '>', 0)
            ->where('resource_type', '<>', 'summary')
            ->whereRaw("LOWER(COALESCE(raw_payload->>'source_tag', '')) = 'abstractresource'")
            ->orderBy('estimate_norm_id')
            ->orderBy('id')
            ->get(['id', 'estimate_norm_id', 'construction_resource_id', 'resource_code', 'resource_name', 'unit', 'quantity', 'resource_type']);
        $unresolvedAbstractDefinitions = $abstractDefinitions->reject(static fn (object $row): bool => $selectedAbstractRows->contains(
            static fn (object $selected): bool => (int) $selected->norm_resource_id === (int) $row->id,
        ));
        $semanticRequiredUnits = $unresolvedAbstractDefinitions
            ->pluck('unit')
            ->filter(static fn (mixed $unit): bool => is_string($unit) && $unit !== '')
            ->unique()
            ->values()
            ->all();
        $semanticSearchHints = $unresolvedAbstractDefinitions
            ->map(function (object $definition) use ($normsById): ?array {
                $norm = $normsById->get((int) $definition->estimate_norm_id);

                return is_object($norm) ? $this->abstractResourceSemanticPriceSelector->queryHints(
                    (string) $norm->name,
                    (string) ($definition->resource_name ?: $definition->resource_code),
                ) : null;
            })
            ->filter()
            ->unique(static fn (array $hint): string => implode(':', [
                $hint['family'] ?? 'pipe',
                $hint['material'],
                $hint['polarity'] ?? '-',
                (string) ($hint['diameter'] ?? '-'),
            ]))
            ->values()
            ->all();
        $semanticProjectPrices = $semanticRequiredUnits === [] || $semanticSearchHints === [] ? collect() : $this->database
            ->table('estimate_resource_prices as semantic_project_prices')
            ->leftJoin(
                'estimate_regional_price_versions as semantic_project_versions',
                'semantic_project_versions.id',
                '=',
                'semantic_project_prices.regional_price_version_id',
            )
            ->leftJoin(
                'estimate_dataset_versions as semantic_project_datasets',
                'semantic_project_datasets.id',
                '=',
                'semantic_project_prices.dataset_version_id',
            )
            ->whereIn('semantic_project_prices.unit', $semanticRequiredUnits)
            ->where(function ($scope) use ($requested, $basePriceDatasetIds): void {
                $scope->where(function ($regional) use ($requested): void {
                    $regional->where('semantic_project_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                        ->where('semantic_project_prices.region_id', $requested->regionId)
                        ->where('semantic_project_prices.price_zone_id', $requested->priceZoneId)
                        ->where('semantic_project_prices.period_id', $requested->periodId);
                })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                    $base->whereNull('semantic_project_prices.regional_price_version_id')
                        ->whereIn('semantic_project_prices.dataset_version_id', $basePriceDatasetIds)
                        ->whereIn('semantic_project_datasets.source_type', ['fsbc', 'fsnb_2022']);
                });
            })
            ->where('semantic_project_prices.base_price', '>', 0)
            ->where(function ($targets) use ($semanticSearchHints): void {
                foreach ($semanticSearchHints as $index => $hint) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $targets->{$method}(function ($target) use ($hint): void {
                        if (($hint['family'] ?? null) === 'window_block') {
                            $target->where('semantic_project_prices.resource_name', 'ilike', '%окон%')
                                ->where('semantic_project_prices.resource_name', 'ilike', '%блок%')
                                ->where(function ($material): void {
                                    $material->where('semantic_project_prices.resource_name', 'ilike', '%пвх%')
                                        ->orWhere('semantic_project_prices.resource_name', 'ilike', '%поливинилхлорид%')
                                        ->orWhere('semantic_project_prices.resource_name', 'ilike', '%пластиков%');
                                });

                            return;
                        }
                        if (($hint['family'] ?? null) === 'duct') {
                            $target->where('semantic_project_prices.resource_name', 'ilike', '%воздуховод%')
                                ->where('semantic_project_prices.resource_name', 'ilike', '%стал%')
                                ->where('semantic_project_prices.resource_name', 'ilike', '%оцинк%');

                            return;
                        }
                        if (($hint['family'] ?? null) === 'tile') {
                            $target->where('semantic_project_prices.resource_name', 'ilike', '%плит%')
                                ->where('semantic_project_prices.resource_name', 'ilike', '%керамич%');

                            return;
                        }
                        if (in_array(($hint['family'] ?? 'pipe'), ['gutter_pipe', 'gutter_fitting'], true)) {
                            $target->where('semantic_project_prices.resource_name', 'ilike', '%водосточ%')
                                ->where(function ($metal): void {
                                    $metal->where('semantic_project_prices.resource_name', 'ilike', '%металл%')
                                        ->orWhere('semantic_project_prices.resource_name', 'ilike', '%стал%')
                                        ->orWhere('semantic_project_prices.resource_name', 'ilike', '%чугун%')
                                        ->orWhere('semantic_project_prices.resource_name', 'ilike', '%оцинк%');
                                });
                            if ($hint['family'] === 'gutter_pipe') {
                                $target->where('semantic_project_prices.resource_name', 'ilike', 'труб%');
                            } else {
                                $target->where('semantic_project_prices.resource_name', 'not ilike', 'труб%');
                            }

                            return;
                        }
                        $target->where('semantic_project_prices.resource_name', 'ilike', '%'.$hint['diameter'].'%')
                            ->where(function ($material) use ($hint): void {
                                if ($hint['material'] === 'steel') {
                                    $material->where('semantic_project_prices.resource_name', 'ilike', '%стал%')
                                        ->orWhere('semantic_project_prices.resource_name', 'ilike', '%вгп%')
                                        ->orWhere('semantic_project_prices.resource_name', 'ilike', '%водогазопровод%');

                                    return;
                                }
                                $material->where('semantic_project_prices.resource_name', 'ilike', '%полиэтилен%')
                                    ->orWhere('semantic_project_prices.resource_name', 'ilike', '%пнд%')
                                    ->orWhere('semantic_project_prices.resource_name', 'ilike', '%hdpe%');
                            });
                        if ($hint['polarity'] === 'galvanized') {
                            $target->where('semantic_project_prices.resource_name', 'ilike', '%оцинк%')
                                ->where('semantic_project_prices.resource_name', 'not ilike', '%неоцинк%');
                        } elseif ($hint['polarity'] === 'non_galvanized') {
                            $target->where(function ($polarity): void {
                                $polarity->where('semantic_project_prices.resource_name', 'ilike', '%неоцинк%')
                                    ->orWhere('semantic_project_prices.resource_name', 'ilike', '%черн%');
                            });
                        }
                    });
                }
            })
            ->orderBy('semantic_project_prices.id')
            ->limit(5_001)
            ->get([
                'semantic_project_prices.id as price_id',
                'semantic_project_prices.construction_resource_id as price_construction_resource_id',
                'semantic_project_prices.resource_code as price_resource_code',
                'semantic_project_prices.resource_name as price_resource_name',
                'semantic_project_prices.unit as price_unit',
                'semantic_project_prices.base_price as unit_price',
                'semantic_project_prices.base_price',
                'semantic_project_prices.dataset_version_id',
                'semantic_project_prices.regional_price_version_id',
                'semantic_project_versions.version_key as regional_price_version_key',
                'semantic_project_datasets.source_type as price_dataset_source_type',
                'semantic_project_datasets.version_key as price_dataset_version',
            ]);
        if ($semanticProjectPrices->count() <= 5_000) {
            foreach ($unresolvedAbstractDefinitions as $definition) {
                $norm = $normsById->get((int) $definition->estimate_norm_id);
                if (! is_object($norm)) {
                    continue;
                }
                $selection = $this->abstractResourceSemanticPriceSelector->select(
                    (string) $norm->name,
                    (string) ($definition->resource_name ?: $definition->resource_code),
                    (string) $definition->unit,
                    $requested->regionalPriceVersionId,
                    $semanticProjectPrices->all(),
                    $basePriceDatasetIds,
                );
                if ($selection === null) {
                    continue;
                }
                $selected = clone $selection['row'];
                $selected->norm_resource_id = (int) $definition->id;
                $selected->estimate_norm_id = (int) $definition->estimate_norm_id;
                $selected->construction_resource_id = $definition->construction_resource_id;
                $selected->resource_code = (string) $definition->resource_code;
                $selected->resource_name = (string) $definition->resource_name;
                $selected->unit = (string) $definition->unit;
                $selected->quantity = $definition->quantity;
                $selected->resource_type = (string) $definition->resource_type;
                $selected->raw_source_tag = 'AbstractResource';
                $selected->project_resource_candidates_count = $selection['candidates_count'];
                $selected->project_resource_price_policy = $selection['policy'];
                $selectedAbstractRows->push($selected);
            }
        }
        $selectedAbstractCounts = $selectedAbstractRows
            ->groupBy('estimate_norm_id')
            ->map(static fn ($rows): int => $rows->count());
        $resourceRows = $resourceRows->concat($selectedAbstractRows);
        $resources = [];
        foreach ($resourceRows as $row) {
            try {
                $mapped = NormativeResourceRowData::fromDatabaseRow($row);
            } catch (\InvalidArgumentException) {
                return null;
            }
            $resources[$mapped->estimateNormId][$mapped->group][] = $mapped->resource;
        }
        $unpricedAbstractResources = $abstractDefinitions
            ->reject(static fn (object $row): bool => $selectedAbstractRows->contains(
                static fn (object $selected): bool => (int) $selected->norm_resource_id === (int) $row->id,
            ))
            ->groupBy('estimate_norm_id')
            ->map(static fn ($rows): array => $rows->map(static fn (object $row): array => [
                'resource_code' => trim((string) $row->resource_code),
                'name' => trim((string) ($row->resource_name ?: $row->resource_code)),
                'unit' => trim((string) $row->unit),
                'quantity' => (float) $row->quantity,
                'reason' => 'project_resource_selection_required',
            ])->values()->all())
            ->all();
        if ($unpricedAbstractResources !== []) {
            $unpricedGroupCodes = collect($unpricedAbstractResources)
                ->flatten(1)
                ->pluck('resource_code')
                ->filter(static fn (mixed $code): bool => is_string($code) && $code !== '')
                ->unique()
                ->values();
            $relatedCatalogRows = $this->database->table('estimate_resource_prices as unresolved_prices')
                ->leftJoin('estimate_dataset_versions as unresolved_datasets', 'unresolved_datasets.id', '=', 'unresolved_prices.dataset_version_id')
                ->where(function ($related) use ($unpricedGroupCodes): void {
                    foreach ($unpricedGroupCodes as $groupCode) {
                        $related->orWhere('unresolved_prices.resource_code', 'like', $groupCode.'-%')
                            ->orWhereRaw("unresolved_prices.raw_payload->>'group_code' = ?", [$groupCode]);
                    }
                })
                ->where('unresolved_prices.base_price', '>', 0)
                ->limit(2_001)
                ->get([
                    $this->database->raw("COALESCE(unresolved_prices.raw_payload->>'group_code', REGEXP_REPLACE(unresolved_prices.resource_code, '-[0-9]{4}$', '')) AS group_code"),
                    'unresolved_prices.unit', 'unresolved_prices.dataset_version_id',
                    'unresolved_prices.regional_price_version_id', 'unresolved_prices.region_id',
                    'unresolved_prices.price_zone_id', 'unresolved_prices.period_id',
                    'unresolved_datasets.source_type', 'unresolved_datasets.version_key as dataset_version',
                ]);
            $normsById = $norms->keyBy('id');
            $requirements = collect($unpricedAbstractResources)
                ->flatMap(static function (array $resources, int $normId) use ($normsById): array {
                    $norm = $normsById->get($normId);

                    return array_map(static fn (array $resource): array => [
                        'norm_code' => trim((string) ($norm->code ?? '')),
                        'norm_name' => trim((string) ($norm->name ?? '')),
                        'group_code' => $resource['resource_code'],
                        'group_name' => $resource['name'],
                        'required_unit' => $resource['unit'],
                        'required_quantity' => (float) $resource['quantity'],
                    ], $resources);
                })
                ->values()
                ->all();
            $coverageDetails = $this->abstractResourceCoverageDiagnostics->build(
                $requirements,
                $relatedCatalogRows->all(),
                $requested->regionalPriceVersionId,
                $requested->regionId,
                $requested->priceZoneId,
                $requested->periodId,
                $basePriceDatasetIds,
            );
            $this->telemetry('unpriced_abstract_resources', [
                'groups_count' => $unpricedGroupCodes->count(),
                'group_codes' => $unpricedGroupCodes->take(30)->all(),
                'related_catalog_rows_count' => $relatedCatalogRows->count(),
                'coverage_details' => array_slice($coverageDetails, 0, 30),
            ]);
        }
        $candidates = [];
        foreach ($norms as $norm) {
            $groups = $resources[(int) $norm->id] ?? [];
            $groups = [
                'materials' => $groups['materials'] ?? [], 'labor' => $groups['labor'] ?? [],
                'machinery' => $groups['machinery'] ?? [], 'other' => $groups['other'] ?? [],
            ];
            $selectedAbstractCount = (int) ($selectedAbstractCounts[(int) $norm->id] ?? 0);
            $unpricedAbstractCount = count($unpricedAbstractResources[(int) $norm->id] ?? []);
            $pricedExpectedCount = (int) ($expectedResourceCounts[(int) $norm->id] ?? 0) - $unpricedAbstractCount;
            if ($pricedExpectedCount < $selectedAbstractCount
                || ! $this->resourceCoverage->complete($pricedExpectedCount, $groups)) {
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
                    'unpriced_abstract_resources' => $unpricedAbstractResources[(int) $norm->id] ?? [],
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
        $supplementaryMaterials = $this->supplementaryMaterials($requested, $basePriceDatasetIds, $intents);
        $this->telemetry('approved', ['intents_count' => count($intents), 'selected_count' => $norms->count(), 'resource_rows_count' => $resourceRows->count(), 'candidates_count' => count($candidates), 'supplementary_materials_count' => count($supplementaryMaterials)]);
        $canonical = json_encode(
            ['catalog_candidates' => $candidates, 'supplementary_materials' => $supplementaryMaterials],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        return new NormativeContextPinData(
            $requested->datasetId, $requested->datasetVersion, $requested->applicabilityDate,
            $requested->regionId, $requested->priceZoneId, $requested->periodId,
            $requested->regionalPriceVersionId, $requested->priceVersion,
            $candidates, hash('sha256', $canonical), $supplementaryMaterials,
        );
    }

    /** @return list<array<string, mixed>> */
    private function supplementaryMaterials(NormativeContextPinData $requested, array $basePriceDatasetIds, array $intents): array
    {
        $requirements = [];
        foreach ($intents as $intent) {
            $requirement = $this->projectMaterials->requirementForIntent($intent);
            if ($requirement !== null) {
                $requirements[(string) $requirement['work_item_key']] = $requirement;
            }
        }
        if ($requirements === []) {
            return [];
        }

        $rows = $this->database->table('estimate_resource_prices as project_prices')
            ->leftJoin('estimate_dataset_versions as project_datasets', 'project_datasets.id', '=', 'project_prices.dataset_version_id')
            ->leftJoin('estimate_regional_price_versions as project_regional_versions', 'project_regional_versions.id', '=', 'project_prices.regional_price_version_id')
            ->where(function ($resourceCodes) use ($requirements): void {
                $resourceCodes->whereIn('project_prices.resource_code', array_values(array_unique(array_column($requirements, 'resource_code'))));
                foreach ($this->projectMaterials->fallbackGroupCodes() as $groupCode) {
                    $resourceCodes->orWhere('project_prices.resource_code', 'like', $groupCode.'-____');
                }
                foreach ($this->projectMaterials->semanticFallbackNameMarkerSets() as $markers) {
                    $resourceCodes->orWhere(static function ($semantic) use ($markers): void {
                        foreach ($markers as $marker) {
                            $semantic->where('project_prices.resource_name', 'ilike', '%'.$marker.'%');
                        }
                    });
                }
            })
            ->where('project_prices.base_price', '>', 0)
            ->where(function ($context) use ($requested, $basePriceDatasetIds): void {
                $context->where(function ($regional) use ($requested): void {
                    $regional->where('project_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                        ->where('project_prices.region_id', $requested->regionId)
                        ->where('project_prices.price_zone_id', $requested->priceZoneId)
                        ->where('project_prices.period_id', $requested->periodId);
                })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                    $base->whereIn('project_prices.dataset_version_id', $basePriceDatasetIds)
                        ->whereIn('project_datasets.source_type', ['fsbc', 'fsnb_2022'])
                        ->whereNull('project_prices.regional_price_version_id');
                });
            })
            ->orderByRaw('CASE WHEN project_prices.regional_price_version_id = ? THEN 0 ELSE 1 END', [$requested->regionalPriceVersionId])
            ->orderByRaw("CASE project_datasets.source_type WHEN 'fsbc' THEN 0 WHEN 'fsnb_2022' THEN 1 ELSE 2 END")
            ->orderByDesc('project_prices.id')
            ->get([
                'project_prices.id as price_id', 'project_prices.construction_resource_id',
                'project_prices.resource_code', 'project_prices.resource_name', 'project_prices.unit',
                'project_prices.base_price', 'project_prices.regional_price_version_id',
                'project_datasets.source_type as dataset_source_type',
                'project_datasets.version_key as dataset_version',
                'project_regional_versions.version_key as regional_version',
            ]);

        foreach ($rows as $row) {
            $row->price_source = (int) ($row->regional_price_version_id ?? 0) === $requested->regionalPriceVersionId
                    ? 'regional_catalog'
                    : ((string) ($row->dataset_source_type ?? '') === 'fsbc' ? 'fsbc_base' : 'fsnb_base');
            $row->price_source_version = $row->price_source === 'regional_catalog'
                ? (string) $row->regional_version
                : (string) $row->dataset_version;
        }

        $result = [];
        foreach ($requirements as $requirement) {
            $resource = $this->projectMaterials->resourceFromPriceRows($requirement, $rows->all());
            if ($resource === null) {
                $preferredCode = trim((string) ($requirement['resource_code'] ?? ''));
                $fallbackGroupCode = trim((string) ($requirement['fallback_group_code'] ?? ''));
                $candidates = $rows->filter(static function (object $row) use ($preferredCode, $fallbackGroupCode): bool {
                    $code = trim((string) ($row->resource_code ?? ''));

                    return $code === $preferredCode
                        || ($fallbackGroupCode !== '' && str_starts_with($code, $fallbackGroupCode.'-'));
                })->take(32)->map(static fn (object $row): array => [
                    'resource_code' => trim((string) ($row->resource_code ?? '')),
                    'resource_name' => trim((string) ($row->resource_name ?? '')),
                    'unit' => trim((string) ($row->unit ?? '')),
                    'base_price' => is_numeric($row->base_price ?? null) ? (float) $row->base_price : null,
                    'price_source' => trim((string) ($row->price_source ?? '')),
                    'price_source_version' => trim((string) ($row->price_source_version ?? '')),
                ])->values()->all();
                $this->telemetry('supplementary_material_price_missing', [
                    'work_item_key' => (string) $requirement['work_item_key'],
                    'preferred_resource_code' => $preferredCode,
                    'fallback_group_code' => $fallbackGroupCode,
                    'expected_source_unit' => (string) ($requirement['source_unit'] ?? ''),
                    'candidates_count' => count($candidates),
                    'candidates' => $candidates,
                ]);
            }
            $result[] = [
                'work_item_key' => $requirement['work_item_key'],
                'requirement' => $requirement,
                'status' => $resource === null ? 'price_missing' : 'priced',
                'resource' => $resource,
            ];
        }

        return $result;
    }

    private function telemetryPrePriceCandidates(
        NormativeContextPinData $requested,
        array $basePriceDatasetIds,
        array $intent,
        string $lexicalQuery,
        string $code,
        array $normativeSections,
        string $semanticPrioritySql,
        array $semanticPriorityBindings,
    ): void {
        $candidates = $this->prePriceCandidateDiagnostics(
            $requested,
            $basePriceDatasetIds,
            $intent,
            $lexicalQuery,
            $code,
            $normativeSections,
            $semanticPrioritySql,
            $semanticPriorityBindings,
        );
        if ($candidates === []) {
            return;
        }

        $this->telemetry('intent_preprice_candidates', [
            'search_text' => mb_strtolower(trim((string) ($intent['search_text'] ?? ''))),
            'action' => $intent['action'] ?? null,
            'unit' => trim((string) ($intent['unit'] ?? '')),
            'normative_sections' => $normativeSections,
            'regional_price_version_id' => $requested->regionalPriceVersionId,
            'base_price_dataset_ids' => $basePriceDatasetIds,
            'candidates' => $candidates,
        ]);
    }

    private function prePriceCandidateDiagnostics(
        NormativeContextPinData $requested,
        array $basePriceDatasetIds,
        array $intent,
        string $lexicalQuery,
        string $code,
        array $normativeSections,
        string $semanticPrioritySql,
        array $semanticPriorityBindings,
    ): array {
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
            ->where(function ($query) use ($code, $lexicalQuery): void {
                if ($code !== '') {
                    $query->orWhereRaw('LOWER(norms.code) = ?', [$code]);
                }
                $query->orWhereRaw("norms.search_vector @@ websearch_to_tsquery('russian', ?)", [$lexicalQuery]);
            })
            ->select([
                'norms.id', 'norms.code', 'norms.name', 'norms.canonical_unit', 'norms.unit',
                'norms.section_code', 'norms.section_name', 'norms.work_composition',
            ])
            ->selectRaw("ts_rank_cd(norms.search_vector, websearch_to_tsquery('russian', ?)) AS pin_lexical_score", [$lexicalQuery])
            ->selectRaw($semanticPrioritySql, $semanticPriorityBindings)
            ->orderByRaw('CASE WHEN LOWER(norms.code) = ? THEN 0 ELSE 1 END', [$code])
            ->orderBy('pin_semantic_priority')
            ->orderByDesc('pin_lexical_score')
            ->orderBy('norms.id')
            ->limit(self::CANDIDATE_POOL_LIMIT)
            ->get();
        $selected = $this->ranker->select($query->all(), [$intent]);
        if ($selected === null) {
            return [];
        }
        $selected = collect($selected)->take(2)->values();
        $ids = $selected->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        $resources = $this->database->table('estimate_norm_resources')
            ->whereIn('estimate_norm_id', $ids)
            ->where('quantity', '>', 0)
            ->where('resource_type', '<>', 'summary')
            ->get(['estimate_norm_id', 'resource_code', 'unit', 'resource_type'])
            ->map(static fn (object $row): array => [
                'estimate_norm_id' => (int) $row->estimate_norm_id,
                'resource_code' => is_string($row->resource_code) ? $row->resource_code : null,
                'unit' => is_string($row->unit) ? $row->unit : null,
                'resource_type' => (string) $row->resource_type,
            ])
            ->all();
        $resourceCodes = collect($resources)
            ->pluck('resource_code')
            ->filter(static fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values()
            ->all();
        $prices = $resourceCodes === [] ? collect() : $this->database->table('estimate_resource_prices')
            ->whereIn('resource_code', $resourceCodes)
            ->where('base_price', '>', 0)
            ->where(function ($priceContext) use ($requested, $basePriceDatasetIds): void {
                $priceContext->where(function ($regional) use ($requested): void {
                    $regional->where('regional_price_version_id', $requested->regionalPriceVersionId)
                        ->where('region_id', $requested->regionId)
                        ->where('price_zone_id', $requested->priceZoneId)
                        ->where('period_id', $requested->periodId);
                })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                    $base->whereIn('dataset_version_id', $basePriceDatasetIds)
                        ->whereNull('regional_price_version_id');
                });
            })
            ->get(['resource_code', 'unit']);
        $priceRows = $prices->map(static fn (object $row): array => [
            'resource_code' => (string) $row->resource_code,
            'unit' => is_string($row->unit) ? $row->unit : null,
        ])->all();
        $resourceUnits = collect($resources)->pluck('unit')->filter()->unique()->values()->all();
        $priceUnits = collect($priceRows)->pluck('unit')->filter()->unique()->values()->all();
        $conversions = ($resourceUnits === [] || $priceUnits === []) ? collect() : $this->database
            ->table('estimate_generation_unit_conversions')
            ->whereIn('from_unit', $resourceUnits)
            ->whereIn('to_unit', $priceUnits)
            ->where('version', 1)
            ->where('is_active', true)
            ->where('factor', '>', 0)
            ->get(['from_unit', 'to_unit']);
        $conversionRows = $conversions->map(static fn (object $row): array => [
            'from_unit' => (string) $row->from_unit,
            'to_unit' => (string) $row->to_unit,
        ])->all();
        $coverage = $this->priceCoverageAnalyzer->analyze($resources, $priceRows, $conversionRows);

        return $selected->map(static fn (object $candidate): array => [
            'code' => (string) $candidate->code,
            'name' => (string) $candidate->name,
            'unit' => (string) ($candidate->canonical_unit ?: $candidate->unit),
            'section' => (string) $candidate->section_code,
            ...($coverage[(int) $candidate->id] ?? []),
        ])->all();
    }

    private function telemetry(string $phase, array $context): void
    {
        if (Log::getFacadeRoot() !== null) {
            Log::info('estimate_generation.normative_pin_source', ['phase' => $phase, ...$context]);
        }
    }

    private function latestPriceDatasetId(string $sourceType, bool $baseOnly): int
    {
        $datasetId = $this->database->table('estimate_dataset_versions')
            ->where('source_type', $sourceType)
            ->where('status', 'parsed')
            ->whereExists(function ($resourcePrices) use ($baseOnly): void {
                $resourcePrices->selectRaw('1')
                    ->from('estimate_resource_prices')
                    ->whereColumn('estimate_resource_prices.dataset_version_id', 'estimate_dataset_versions.id')
                    ->when($baseOnly, static fn ($prices) => $prices->whereNull('regional_price_version_id'))
                    ->where('base_price', '>', 0);
            })
            ->orderByDesc('id')
            ->limit(1)
            ->value('id');

        return is_numeric($datasetId) ? (int) $datasetId : 0;
    }

    private function coverageDiagnostics(NormativeContextPinData $requested, array $basePriceDatasetIds, array $intents): array
    {
        $eligible = $this->database->table('estimate_norm_resources as diagnostic_resources')
            ->join('estimate_norms as diagnostic_norms', 'diagnostic_norms.id', '=', 'diagnostic_resources.estimate_norm_id')
            ->join('estimate_norm_collections as diagnostic_collections', 'diagnostic_collections.id', '=', 'diagnostic_norms.collection_id')
            ->where('diagnostic_collections.dataset_version_id', $requested->datasetId)
            ->where('diagnostic_resources.quantity', '>', 0)
            ->where('diagnostic_resources.resource_type', '<>', 'summary');
        $codeMatched = (clone $eligible)->whereExists(function ($prices) use ($requested, $basePriceDatasetIds): void {
            $prices->selectRaw('1')
                ->from('estimate_resource_prices as diagnostic_prices')
                ->whereColumn('diagnostic_prices.resource_code', 'diagnostic_resources.resource_code')
                ->where('diagnostic_prices.base_price', '>', 0)
                ->where(function ($context) use ($requested, $basePriceDatasetIds): void {
                    $context->where(function ($regional) use ($requested): void {
                        $regional->where('diagnostic_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                            ->where('diagnostic_prices.region_id', $requested->regionId)
                            ->where('diagnostic_prices.price_zone_id', $requested->priceZoneId)
                            ->where('diagnostic_prices.period_id', $requested->periodId);
                    })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                        $base->whereIn('diagnostic_prices.dataset_version_id', $basePriceDatasetIds)
                            ->whereNull('diagnostic_prices.regional_price_version_id');
                    });
                });
        });
        $unitMatched = (clone $codeMatched)->whereExists(function ($prices) use ($requested, $basePriceDatasetIds): void {
            $prices->selectRaw('1')
                ->from('estimate_resource_prices as diagnostic_unit_prices')
                ->whereColumn('diagnostic_unit_prices.resource_code', 'diagnostic_resources.resource_code')
                ->where('diagnostic_unit_prices.base_price', '>', 0)
                ->where(function ($context) use ($requested, $basePriceDatasetIds): void {
                    $context->where(function ($regional) use ($requested): void {
                        $regional->where('diagnostic_unit_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                            ->where('diagnostic_unit_prices.region_id', $requested->regionId)
                            ->where('diagnostic_unit_prices.price_zone_id', $requested->priceZoneId)
                            ->where('diagnostic_unit_prices.period_id', $requested->periodId);
                    })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                        $base->whereIn('diagnostic_unit_prices.dataset_version_id', $basePriceDatasetIds)
                            ->whereNull('diagnostic_unit_prices.regional_price_version_id');
                    });
                })
                ->whereRaw('diagnostic_unit_prices.unit IS NOT DISTINCT FROM diagnostic_resources.unit');
        });
        $normalizedUnitMatched = (clone $codeMatched)->whereExists(function ($prices) use ($requested, $basePriceDatasetIds): void {
            $prices->selectRaw('1')
                ->from('estimate_resource_prices as diagnostic_normalized_prices')
                ->whereColumn('diagnostic_normalized_prices.resource_code', 'diagnostic_resources.resource_code')
                ->where('diagnostic_normalized_prices.base_price', '>', 0)
                ->where(function ($context) use ($requested, $basePriceDatasetIds): void {
                    $context->where(function ($regional) use ($requested): void {
                        $regional->where('diagnostic_normalized_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                            ->where('diagnostic_normalized_prices.region_id', $requested->regionId)
                            ->where('diagnostic_normalized_prices.price_zone_id', $requested->priceZoneId)
                            ->where('diagnostic_normalized_prices.period_id', $requested->periodId);
                    })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                        $base->whereIn('diagnostic_normalized_prices.dataset_version_id', $basePriceDatasetIds)
                            ->whereNull('diagnostic_normalized_prices.regional_price_version_id');
                    });
                })
                ->whereRaw(
                    "LOWER(REGEXP_REPLACE(COALESCE(diagnostic_normalized_prices.unit, ''), '[[:space:].,-]+', '', 'g')) = LOWER(REGEXP_REPLACE(COALESCE(diagnostic_resources.unit, ''), '[[:space:].,-]+', '', 'g'))"
                );
        });
        $diagnosticIntent = $intents[0] ?? [];
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
            ->where(function ($context) use ($requested, $basePriceDatasetIds): void {
                $context->where(function ($regional) use ($requested): void {
                    $regional->where('diagnostic_pair_prices.regional_price_version_id', $requested->regionalPriceVersionId)
                        ->where('diagnostic_pair_prices.region_id', $requested->regionId)
                        ->where('diagnostic_pair_prices.price_zone_id', $requested->priceZoneId)
                        ->where('diagnostic_pair_prices.period_id', $requested->periodId);
                })->orWhere(function ($base) use ($basePriceDatasetIds): void {
                    $base->whereIn('diagnostic_pair_prices.dataset_version_id', $basePriceDatasetIds)
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
            'base_price_dataset_ids' => $basePriceDatasetIds,
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
