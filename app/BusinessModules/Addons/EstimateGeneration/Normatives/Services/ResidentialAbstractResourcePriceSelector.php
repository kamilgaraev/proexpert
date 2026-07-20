<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class ResidentialAbstractResourcePriceSelector
{
    private const CONVERSIONS = [
        '07-01-021-01|05.1.03.09' => [
            'from_unit' => 'м3',
            'to_unit' => 'шт',
            'factor' => 0.04,
            'assumption' => 'precast_lintel_volume_per_piece_m3:0.04',
            'name_markers' => ['перемыч'],
        ],
        '12-01-013-07|12.2.05.02' => [
            'from_unit' => 'м3',
            'to_unit' => 'м2',
            'factor' => 0.20,
            'assumption' => 'mineral_wool_thickness_m:0.20',
            'name_markers' => ['минерал', 'ват'],
        ],
        '15-01-019-05|06.2.05.04' => [
            'from_unit' => 'т',
            'to_unit' => 'м2',
            'factor' => 0.02,
            'assumption' => 'ceramic_tile_mass_t_per_m2:0.02',
            'name_markers' => ['плит', 'керамич'],
        ],
    ];

    /**
     * @param  list<object>  $candidates
     * @param  list<int>  $baseDatasetIds
     * @return array{row: object, candidates_count: int, policy: string, assumption: string}|null
     */
    public function select(string $normCode, string $groupCode, array $candidates, array $baseDatasetIds): ?array
    {
        $conversion = self::CONVERSIONS[$normCode.'|'.$groupCode] ?? null;
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
        $convertedPrice = round($sourceUnitPrice * $conversion['factor'], 6);
        $selected->project_resource_source_unit_price = $sourceUnitPrice;
        $selected->project_resource_source_price_unit = $sourcePriceUnit;
        $selected->project_resource_conversion_factor = $conversion['factor'];
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
        return array_values(array_unique(array_map(
            static fn (string $key): string => explode('|', $key, 2)[1],
            array_keys(self::CONVERSIONS),
        )));
    }
}
