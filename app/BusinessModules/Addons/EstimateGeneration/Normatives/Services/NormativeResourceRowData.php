<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use InvalidArgumentException;

final readonly class NormativeResourceRowData
{
    private function __construct(
        public int $estimateNormId,
        public string $group,
        public array $resource,
    ) {}

    public static function fromDatabaseRow(object $row): self
    {
        $normId = self::positiveInt($row->estimate_norm_id ?? null);
        $normResourceId = self::positiveInt($row->norm_resource_id ?? null);
        $linkedResourceId = self::positiveInt($row->construction_resource_id ?? null);
        $priceResourceId = self::positiveInt($row->price_construction_resource_id ?? null);
        $priceId = self::positiveInt($row->price_id ?? null);
        $resourceCode = trim((string) ($row->resource_code ?? ''));
        $priceResourceCode = trim((string) ($row->price_resource_code ?? ''));
        $unitPrice = trim((string) ($row->unit_price ?? ''));
        $regionalPriceVersionId = self::positiveInt($row->regional_price_version_id ?? null);
        $datasetSourceType = trim((string) ($row->price_dataset_source_type ?? ''));
        $priceSource = match (true) {
            $regionalPriceVersionId !== null => 'regional_catalog',
            $datasetSourceType === 'fsbc' => 'fsbc_base',
            $datasetSourceType === 'fsnb_2022' => 'fsnb_base',
            $datasetSourceType === 'fgis_labor_prices' => 'fgis_labor_base',
            default => null,
        };
        $priceSourceVersion = trim((string) (
            $regionalPriceVersionId !== null
                ? ($row->regional_price_version_key ?? '')
                : ($row->price_dataset_version ?? '')
        ));
        $projectResourceCandidatesCount = self::positiveInt($row->project_resource_candidates_count ?? null);
        $isAbstractResource = strcasecmp(trim((string) ($row->raw_source_tag ?? '')), 'AbstractResource') === 0;
        $projectResourcePricePolicy = trim((string) ($row->project_resource_price_policy
            ?? ($regionalPriceVersionId !== null ? 'regional_child_median:v1' : '')));
        $isProjectResourceSelection = $isAbstractResource
            && $projectResourceCandidatesCount !== null
            && in_array($projectResourcePricePolicy, ['regional_child_median:v1', 'fsbc_base_child_median:v1'], true)
            && preg_match('/^\d{2}\.\d\.\d{2}\.\d{2}$/D', $resourceCode) === 1
            && preg_match('/^'.preg_quote($resourceCode, '/').'-\d{4}$/D', $priceResourceCode) === 1;
        $identityMatches = $resourceCode !== '' && (
            hash_equals($resourceCode, $priceResourceCode) || $isProjectResourceSelection
        );
        if (
            $normId === null || $normResourceId === null || $priceId === null || ! $identityMatches
            || $priceSource === null || $priceSourceVersion === '' || ! is_numeric($unitPrice) || (float) $unitPrice <= 0
        ) {
            throw new InvalidArgumentException('normative_resource_price_relation_invalid');
        }
        $group = match ((string) ($row->resource_type ?? '')) {
            'material', 'equipment' => 'materials',
            'labor', 'machine_labor' => 'labor',
            'machine', 'machinery' => 'machinery',
            default => 'other',
        };

        $resource = [
            'code' => $resourceCode,
            'name' => (string) ($row->resource_name ?? ''),
            'unit' => (string) ($row->unit ?? ''),
            'price_unit' => (string) ($row->price_unit ?? $row->unit ?? ''),
            'quantity' => (float) ($row->quantity ?? 0),
            'price_id' => $priceId,
            'unit_price' => $unitPrice,
            'price_source' => $priceSource,
            'price_source_version' => $priceSourceVersion,
            'linked_resource_id' => $isProjectResourceSelection
                ? $priceResourceId
                : ($linkedResourceId ?? $priceResourceId),
            'norm_resource_id' => $normResourceId,
        ];
        if ($isProjectResourceSelection) {
            $resource['project_resource_selection'] = [
                'group_code' => $resourceCode,
                'selected_resource_code' => $priceResourceCode,
                'selected_resource_name' => (string) ($row->price_resource_name ?? ''),
                'price_source' => $priceSource,
                'price_source_version' => $priceSourceVersion,
                'policy' => $projectResourcePricePolicy,
                'candidates_count' => $projectResourceCandidatesCount,
            ];
        }

        return new self($normId, $group, $resource);
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : null;
    }
}
