<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use Illuminate\Database\Connection;

final readonly class EloquentNormativeContextPinSource implements NormativeContextPinSource
{
    public function __construct(
        private Connection $database,
        private NormativeIntentCandidateRanker $ranker = new NormativeIntentCandidateRanker,
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
            return null;
        }
        if ($intents === [] || count($intents) > 64) {
            return null;
        }
        $norms = collect();
        foreach ($intents as $intent) {
            $search = mb_strtolower(trim((string) ($intent['search_text'] ?? '')));
            $unit = trim((string) ($intent['unit'] ?? ''));
            $code = mb_strtolower(trim((string) ($intent['code'] ?? '')));
            if ($search === '' || $unit === '') {
                return null;
            }
            $tokens = array_values(array_filter(
                preg_split('/[^\pL\pN.-]+/u', $search) ?: [],
                static fn (string $token): bool => mb_strlen($token) >= 3,
            ));
            $query = $this->database->table('estimate_norms as norms')
                ->join('estimate_norm_collections as collections', 'collections.id', '=', 'norms.collection_id')
                ->where('collections.dataset_version_id', $requested->datasetId)
                ->where(function ($query) use ($search, $code, $tokens): void {
                    if ($code !== '') {
                        $query->orWhereRaw('LOWER(norms.code) = ?', [$code]);
                    }
                    $query->orWhereRaw('LOWER(norms.name) = ?', [$search]);
                    foreach (array_slice($tokens, 0, 8) as $token) {
                        $query->orWhereRaw('LOWER(norms.name) LIKE ?', ['%'.$token.'%']);
                    }
                })
                ->orderByRaw('CASE WHEN LOWER(norms.code) = ? THEN 0 WHEN LOWER(norms.name) = ? THEN 1 ELSE 2 END', [$code, $search])
                ->orderBy('norms.id')
                ->limit(128)
                ->get([
                    'norms.id', 'norms.code', 'norms.name', 'norms.canonical_unit', 'norms.unit',
                    'norms.unit_dimension', 'norms.material', 'norms.technology', 'norms.structure',
                    'norms.object_type', 'norms.region_code', 'norms.valid_from', 'norms.valid_to',
                    'norms.section_code', 'norms.section_name', 'norms.work_composition',
                    'collections.code as collection_code', 'collections.name as collection_name', 'collections.norm_type',
                ]);
            if ($query->isEmpty()) {
                continue;
            }
            $norms = $norms->concat($query);
        }
        $selected = $this->ranker->select($norms->unique('id')->values()->all(), $intents);
        if ($selected === null || $selected === []) {
            return null;
        }
        $norms = collect($selected);
        $ids = $norms->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $resourceRows = $this->database->table('estimate_norm_resources as resources')
            ->join('estimate_resource_prices as prices', function ($join) use ($requested): void {
                $join->on('prices.construction_resource_id', '=', 'resources.construction_resource_id')
                    ->where('prices.regional_price_version_id', $requested->regionalPriceVersionId)
                    ->where('prices.region_id', $requested->regionId)
                    ->where('prices.price_zone_id', $requested->priceZoneId)
                    ->where('prices.period_id', $requested->periodId);
            })
            ->whereIn('resources.estimate_norm_id', $ids)
            ->orderBy('resources.estimate_norm_id')->orderBy('resources.id')
            ->limit(10_001)
            ->get([
                'resources.id as norm_resource_id', 'resources.estimate_norm_id', 'resources.construction_resource_id', 'resources.resource_code',
                'resources.resource_name', 'resources.unit', 'resources.quantity', 'resources.resource_type',
                'prices.id as price_id', 'prices.construction_resource_id as price_construction_resource_id', 'prices.price_type',
            ]);
        if ($resourceRows->count() > 10_000) {
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
            if (array_sum(array_map('count', $groups)) === 0) {
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
            return null;
        }
        $canonical = json_encode($candidates, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return new NormativeContextPinData(
            $requested->datasetId, $requested->datasetVersion, $requested->applicabilityDate,
            $requested->regionId, $requested->priceZoneId, $requested->periodId,
            $requested->regionalPriceVersionId, $requested->priceVersion,
            $candidates, hash('sha256', $canonical),
        );
    }
}
