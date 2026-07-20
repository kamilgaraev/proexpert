<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class ResidentialAbstractResourcePriceSelector
{
    public function __construct(private ResidentialAbstractResourceConversionCatalog $conversions = new ResidentialAbstractResourceConversionCatalog) {}

    /**
     * @param  list<object>  $candidates
     * @param  list<int>  $baseDatasetIds
     * @return array{row: object, candidates_count: int, policy: string, assumption: string}|null
     */
    public function select(string $normCode, string $groupCode, array $candidates, array $baseDatasetIds): ?array
    {
        $conversion = $this->conversions->find($normCode, $groupCode);
        if ($conversion === null) {
            return null;
        }

        $eligible = array_values(array_filter($candidates, static function (object $candidate) use ($groupCode, $conversion, $baseDatasetIds): bool {
            $name = mb_strtolower(trim((string) ($candidate->price_resource_name ?? '')));

            return preg_match('/^'.preg_quote($groupCode, '/').'-\d{4}$/D', trim((string) ($candidate->price_resource_code ?? ''))) === 1
                && array_filter(
                    $conversion['name_markers'],
                    static fn (string $marker): bool => ! str_contains($name, $marker),
                ) === []
                && trim((string) ($candidate->price_unit ?? '')) === $conversion['from_unit']
                && in_array((int) ($candidate->dataset_version_id ?? 0), $baseDatasetIds, true)
                && ($candidate->regional_price_version_id ?? null) === null
                && in_array((string) ($candidate->price_dataset_source_type ?? ''), ['fsbc', 'fsnb_2022'], true)
                && is_numeric($candidate->base_price ?? null)
                && (float) $candidate->base_price > 0
                && (int) ($candidate->price_id ?? 0) > 0;
        }));
        if ($eligible === []) {
            return null;
        }

        usort($eligible, static function (object $left, object $right): int {
            $byPrice = (float) $left->base_price <=> (float) $right->base_price;

            return $byPrice !== 0
                ? $byPrice
                : strcmp((string) $left->price_resource_code, (string) $right->price_resource_code);
        });
        $selected = clone $eligible[intdiv(count($eligible) - 1, 2)];
        $sourceUnitPrice = (float) $selected->base_price;
        $sourcePriceUnit = (string) $selected->price_unit;
        $convertedPrice = round($sourceUnitPrice * (float) $conversion['factor'], 6);
        $selected->project_resource_source_unit_price = $sourceUnitPrice;
        $selected->project_resource_source_price_unit = $sourcePriceUnit;
        $selected->project_resource_conversion_factor = (float) $conversion['factor'];
        $selected->base_price = $convertedPrice;
        $selected->unit_price = $convertedPrice;
        $selected->price_unit = $conversion['to_unit'];
        $selected->project_resource_conversion_assumption = $conversion['assumption'];

        return [
            'row' => $selected,
            'candidates_count' => count($eligible),
            'policy' => (string) $selected->price_dataset_source_type.'_residential_converted_child_median:v1',
            'assumption' => $conversion['assumption'],
        ];
    }

    /** @return list<string> */
    public function supportedGroupCodes(): array
    {
        return $this->conversions->supportedGroupCodes();
    }

    /** @return list<array{group_code: string, from_unit: string}> */
    public function supportedUnitPairs(): array
    {
        return $this->conversions->supportedUnitPairs();
    }
}
