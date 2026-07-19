<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class ResidentialMaterialScenarioCatalog
{
    private const CATALOG_VERSION = 'residential_material_scenario:v3';

    private const SCENARIO_ID = 'residential_preliminary_common:v3';

    private const SIGNING_NAMESPACE = 'most:estimate-generation:residential-material-scenario:v3';

    /**
     * @var array<string, array{
     *     material_markers: list<string>,
     *     assumption_code: string,
     *     normative_search_text?: string,
     *     normative_rate_code?: string
     * }>
     */
    private const WORK_ITEMS = [
        'foundation.waterproofing' => [
            'material_markers' => ['обмазочн', 'мастич', 'мастик'],
            'assumption_code' => 'foundation_coating_waterproofing',
            'normative_search_text' => 'боковая обмазочная битумная гидроизоляция',
            'normative_rate_code' => '08-01-003-07',
        ],
        'walls.external_volume' => [
            'material_markers' => ['газобетон', 'ячеист'],
            'assumption_code' => 'external_walls_aerated_concrete',
            'normative_search_text' => 'кладка стен из газобетонных блоков на клее',
            'normative_rate_code' => '08-03-004-01',
        ],
        'walls.internal' => [
            'material_markers' => ['газобетон', 'ячеист'],
            'assumption_code' => 'internal_partitions_aerated_concrete',
            'normative_search_text' => 'кладка перегородок из газобетонных блоков на клее',
            'normative_rate_code' => '08-04-003-01',
        ],
        'roof.insulation' => [
            'material_markers' => ['минераловат', 'минеральн ват'],
            'assumption_code' => 'pitched_roof_mineral_wool',
            'normative_search_text' => 'утепление покрытий плитами из минеральной ваты насухо',
            'normative_rate_code' => '12-01-013-07',
        ],
        'finish.floor' => [
            'material_markers' => ['ламинат', 'ламинированн'],
            'assumption_code' => 'floor_laminate',
            'normative_search_text' => 'устройство покрытий из досок ламинированных замковым способом',
            'normative_rate_code' => '11-01-034-04',
        ],
        'finish.baseboard' => [
            'material_markers' => ['поливинилхлорид', 'пвх', 'пластик'],
            'assumption_code' => 'baseboard_pvc',
            'normative_search_text' => 'устройство плинтусов поливинилхлоридных на винтах самонарезающих',
            'normative_rate_code' => '11-01-040-03',
        ],
        'ventilation.air_exchange' => [
            'material_markers' => [
                'воздуховод',
                'листовой оцинкованной стали',
                'класс н',
                'диаметром до 200 мм',
            ],
            'assumption_code' => 'residential_small_galvanized_ducts',
            'normative_search_text' => 'монтаж воздуховодов',
            'normative_rate_code' => '20-01-001-01',
        ],
        'stairs.flights' => [
            'material_markers' => ['внутриквартирн', 'лестниц', 'без подшив'],
            'assumption_code' => 'residential_stair_without_soffit',
            'normative_search_text' => 'устройство внутриквартирных лестниц без подшивки',
            'normative_rate_code' => '10-01-052-02',
        ],
        'openings.windows' => [
            'material_markers' => ['оконн', 'пвх', 'поворотно-откид', 'двухстворчат', 'до 2 м2'],
            'assumption_code' => 'residential_pvc_windows',
            'normative_search_text' => 'установка оконных блоков из ПВХ профилей двухстворчатых площадью до 2 м2',
            'normative_rate_code' => '10-01-034-05',
        ],
        'electrical.grounding' => [
            'material_markers' => ['заземлител', 'горизонтальн', 'кругл', '12 мм'],
            'assumption_code' => 'residential_round_steel_grounding',
            'normative_search_text' => 'заземлитель горизонтальный из круглой стали диаметром 12 мм',
            'normative_rate_code' => '08-02-472-01',
        ],
        'heating.radiators' => [
            'material_markers' => ['радиатор', 'стальн'],
            'assumption_code' => 'residential_steel_radiators',
            'normative_search_text' => 'установка стальных радиаторов',
            'normative_rate_code' => '18-03-001-02',
        ],
        'sanitary.waterproofing' => [
            'material_markers' => ['гидроизоляц', 'обмазочн', 'мастик'],
            'assumption_code' => 'wet_zone_coating_waterproofing',
            'normative_search_text' => 'устройство обмазочной гидроизоляции битумной мастикой в один слой толщиной 2 мм',
            'normative_rate_code' => '11-01-004-05',
        ],
        'sanitary.tile' => [
            'material_markers' => ['облицовк', 'стен', 'керамическ', 'кле'],
            'assumption_code' => 'wet_zone_ceramic_wall_tile',
            'normative_search_text' => 'гладкая облицовка стен керамическими плитками на клее по кирпичу и бетону',
            'normative_rate_code' => '15-01-019-05',
        ],
    ];

    /** @return array<string, mixed>|null */
    public function issue(string $workItemKey, string $objectType): ?array
    {
        if ($objectType !== 'residential' || ! isset(self::WORK_ITEMS[$workItemKey])) {
            return null;
        }

        $definition = self::WORK_ITEMS[$workItemKey];
        $payload = [
            'version' => self::CATALOG_VERSION,
            'scenario_id' => self::SCENARIO_ID,
            'work_item_key' => $workItemKey,
            'object_type' => 'residential',
            'material_markers' => $definition['material_markers'],
            'assumption_code' => $definition['assumption_code'],
            ...isset($definition['normative_search_text'])
                ? ['normative_search_text' => $definition['normative_search_text']]
                : [],
            ...isset($definition['normative_rate_code'])
                ? ['normative_rate_code' => $definition['normative_rate_code']]
                : [],
        ];

        return [
            ...$payload,
            'signature' => $this->signature($payload),
        ];
    }

    /** @return array<string, mixed>|null */
    public function resolve(mixed $scenario, string $workItemKey, string $objectType): ?array
    {
        if (! is_array($scenario)) {
            return null;
        }

        $canonical = $this->issue($workItemKey, $objectType);
        if ($canonical === null || ! isset($scenario['signature']) || ! is_string($scenario['signature'])) {
            return null;
        }

        if (! hash_equals((string) $canonical['signature'], $scenario['signature'])) {
            return null;
        }

        return $this->canonicalize($scenario) === $this->canonicalize($canonical) ? $canonical : null;
    }

    /** @param array<string, mixed> $payload */
    private function signature(array $payload): string
    {
        return hash('sha256', self::SIGNING_NAMESPACE.'|'.json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
