<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class AbstractResourceCoverageDiagnostics
{
    /**
     * @param  list<array{norm_code: string, norm_name: string, group_code: string, group_name: string, required_unit: string, required_quantity: float}>  $requirements
     * @param  list<object>  $catalogRows
     * @param  list<int>  $baseDatasetIds
     * @return list<array<string, mixed>>
     */
    public function build(
        array $requirements,
        array $catalogRows,
        int $regionalPriceVersionId,
        int $regionId,
        int $priceZoneId,
        int $periodId,
        array $baseDatasetIds,
    ): array {
        $result = [];
        foreach ($requirements as $requirement) {
            $groupCode = $requirement['group_code'];
            $rows = array_values(array_filter(
                $catalogRows,
                static fn (object $row): bool => trim((string) ($row->group_code ?? '')) === $groupCode,
            ));
            $activeRegional = array_values(array_filter(
                $rows,
                static fn (object $row): bool => (int) ($row->regional_price_version_id ?? 0) === $regionalPriceVersionId
                    && (int) ($row->region_id ?? 0) === $regionId
                    && (int) ($row->price_zone_id ?? 0) === $priceZoneId
                    && (int) ($row->period_id ?? 0) === $periodId,
            ));
            $approvedBase = array_values(array_filter(
                $rows,
                static fn (object $row): bool => ($row->regional_price_version_id ?? null) === null
                    && in_array((int) ($row->dataset_version_id ?? 0), $baseDatasetIds, true)
                    && in_array((string) ($row->source_type ?? ''), ['fsbc', 'fsnb_2022'], true),
            ));
            $eligible = [...$activeRegional, ...$approvedBase];
            $requiredUnit = $this->normalizeUnit($requirement['required_unit']);
            $exactUnitEligible = array_filter(
                $eligible,
                fn (object $row): bool => $this->normalizeUnit((string) ($row->unit ?? '')) === $requiredUnit,
            );
            $availableUnits = array_values(array_unique(array_filter(array_map(
                static fn (object $row): string => trim((string) ($row->unit ?? '')),
                $rows,
            ))));
            sort($availableUnits);
            $sourceTypes = array_values(array_unique(array_filter(array_map(
                static fn (object $row): string => trim((string) ($row->source_type ?? '')),
                $rows,
            ))));
            sort($sourceTypes);

            $result[] = [
                ...$requirement,
                'available_units' => $availableUnits,
                'source_types' => $sourceTypes,
                'catalog_options_count' => count($rows),
                'active_regional_options_count' => count($activeRegional),
                'approved_base_options_count' => count($approvedBase),
                'compatible_active_options_count' => count($exactUnitEligible),
            ];
        }

        return $result;
    }

    private function normalizeUnit(string $unit): string
    {
        return mb_strtolower((string) preg_replace('/[\s.,-]+/u', '', trim($unit)));
    }
}
