<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use Illuminate\Database\Connection;

final readonly class EloquentNormativeContextPinSource implements NormativeContextPinSource
{
    public function __construct(private Connection $database) {}

    public function resolve(NormativeContextPinData $requested): ?NormativeContextPinData
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
        $norms = $this->database->table('estimate_norms as norms')
            ->join('estimate_norm_collections as collections', 'collections.id', '=', 'norms.collection_id')
            ->where('collections.dataset_version_id', $requested->datasetId)
            ->orderBy('norms.id')->limit(129)
            ->get([
                'norms.id', 'norms.code', 'norms.name', 'norms.canonical_unit', 'norms.unit',
                'norms.section_code', 'norms.section_name', 'norms.work_composition',
                'collections.code as collection_code', 'collections.name as collection_name', 'collections.norm_type',
            ]);
        if ($norms->isEmpty() || $norms->count() > 128) {
            return null;
        }
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
                'resources.estimate_norm_id', 'resources.construction_resource_id', 'resources.resource_code',
                'resources.resource_name', 'resources.unit', 'resources.quantity', 'resources.resource_type',
                'prices.id as price_id', 'prices.price_type',
            ]);
        if ($resourceRows->count() > 10_000) {
            return null;
        }
        $resources = [];
        foreach ($resourceRows as $row) {
            $group = match ((string) $row->resource_type) {
                'material' => 'materials', 'labor' => 'labor', 'machine', 'machinery' => 'machinery', default => 'other',
            };
            $resources[(int) $row->estimate_norm_id][$group][] = [
                'code' => (string) $row->resource_code,
                'name' => (string) $row->resource_name,
                'unit' => (string) $row->unit,
                'quantity' => (float) $row->quantity,
                'price_id' => (int) $row->price_id,
                'price_source' => 'regional_catalog',
                'linked_resource_id' => (int) $row->construction_resource_id,
            ];
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
