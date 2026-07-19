<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class ResidentialMaterialScenarioCatalog
{
    private const CATALOG_VERSION = 'residential_material_scenario:v2';

    private const SCENARIO_ID = 'residential_preliminary_common:v2';

    private const SIGNING_NAMESPACE = 'most:estimate-generation:residential-material-scenario:v2';

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
