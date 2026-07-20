<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class ResidentialAbstractResourceConversionCatalog
{
    private const CONVERSIONS = [
        '06-23-003-05|08.4.01.02' => [
            'candidate_group_code' => '08.4.03.03',
            'from_unit' => 'т',
            'to_unit' => 'т',
            'factor' => '1',
            'assumption' => 'monolithic_floor_reinforcement_steel_group:08.4.03.03',
            'name_markers' => ['арматур', 'a500c', '12 мм'],
        ],
        '07-01-021-01|05.1.03.09' => [
            'candidate_group_code' => '05.1.03.09',
            'from_unit' => 'м3',
            'to_unit' => 'шт',
            'factor' => '0.04',
            'assumption' => 'precast_lintel_volume_per_piece_m3:0.04',
            'name_markers' => ['перемыч'],
        ],
        '12-01-013-07|12.2.05.02' => [
            'candidate_group_code' => '12.2.05.02',
            'from_unit' => 'м3',
            'to_unit' => 'м2',
            'factor' => '0.20',
            'assumption' => 'mineral_wool_thickness_m:0.20',
            'name_markers' => ['минерал', 'ват'],
        ],
        '15-01-019-05|06.2.05.04' => [
            'candidate_group_code' => '06.2.01.02',
            'from_unit' => 'м2',
            'to_unit' => 'м2',
            'factor' => '1',
            'assumption' => 'interior_ceramic_wall_tile_group:06.2.01.02',
            'name_markers' => ['плит', 'керамич', 'внутренн', 'стен'],
        ],
        '17-01-003-01|18.2.06.08' => [
            'candidate_group_code' => '18.2.06.08',
            'from_unit' => '10 шт',
            'to_unit' => 'шт',
            'factor' => '0.1',
            'assumption' => 'toilet_flexible_water_connector_per_piece:0.1',
            'name_markers' => ['подводк', 'гибк', 'армирован', 'резинов'],
        ],
        '17-01-001-14|18.2.02.08' => [
            'candidate_group_code' => '18.2.02.08',
            'from_unit' => 'шт',
            'to_unit' => 'компл',
            'factor' => '1',
            'assumption' => 'washbasin_piece_per_set:1',
            'name_markers' => ['умывальник'],
        ],
    ];

    public function find(string $normCode, string $groupCode): ?array
    {
        return self::CONVERSIONS[$normCode.'|'.$groupCode] ?? null;
    }

    public function supportedGroupCodes(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => explode('|', $key, 2)[1],
            array_keys(self::CONVERSIONS),
        )));
    }

    /** @return list<array{group_code: string, from_unit: string}> */
    public function supportedUnitPairs(): array
    {
        $pairs = [];
        foreach (self::CONVERSIONS as $key => $conversion) {
            $pairs[] = [
                'group_code' => explode('|', $key, 2)[1],
                'from_unit' => $conversion['from_unit'],
            ];
        }

        return $pairs;
    }

    /** @return list<array{group_code: string, candidate_group_code: string, from_unit: string}> */
    public function supportedCandidateGroups(): array
    {
        $groups = [];
        foreach (self::CONVERSIONS as $key => $conversion) {
            $groups[] = [
                'group_code' => explode('|', $key, 2)[1],
                'candidate_group_code' => $conversion['candidate_group_code'],
                'from_unit' => $conversion['from_unit'],
            ];
        }

        return $groups;
    }
}
