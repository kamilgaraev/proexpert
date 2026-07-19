<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class ResidentialMaterialScenarioCatalog
{
    private const CATALOG_VERSION = 'residential_material_scenario:v1';

    private const SCENARIO_ID = 'residential_preliminary_common:v1';

    private const SIGNING_NAMESPACE = 'most:estimate-generation:residential-material-scenario:v1';

    /** @var array<string, array{material_markers: list<string>, assumption_code: string}> */
    private const WORK_ITEMS = [
        'foundation.waterproofing' => [
            'material_markers' => ['обмазочн', 'мастич', 'мастик'],
            'assumption_code' => 'foundation_coating_waterproofing',
        ],
        'walls.external_volume' => [
            'material_markers' => ['газобетон', 'ячеист'],
            'assumption_code' => 'external_walls_aerated_concrete',
        ],
        'walls.internal' => [
            'material_markers' => ['газобетон', 'ячеист'],
            'assumption_code' => 'internal_partitions_aerated_concrete',
        ],
        'roof.insulation' => [
            'material_markers' => ['минераловат', 'минеральн ват'],
            'assumption_code' => 'pitched_roof_mineral_wool',
        ],
        'finish.floor' => [
            'material_markers' => ['ламинат'],
            'assumption_code' => 'floor_laminate',
        ],
        'finish.baseboard' => [
            'material_markers' => ['поливинилхлорид', 'пвх', 'пластик'],
            'assumption_code' => 'baseboard_pvc',
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
