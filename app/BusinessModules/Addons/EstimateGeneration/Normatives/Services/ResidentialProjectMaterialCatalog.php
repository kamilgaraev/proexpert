<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class ResidentialProjectMaterialCatalog
{
    public const VERSION = 'residential_project_material:v3';

    private const REQUIREMENTS = [
        'electrical.main_cable' => [
            'resource_code' => '21.1.06.09-0154',
            'unit' => '1000 м',
            'source_unit' => '1000 м',
            'price_factor' => 1.0,
            'quantity_per_work_unit' => 0.00105,
            'assumption_code' => 'residential_main_cable_vvgng_ls_3x6_with_waste_5_percent',
        ],
        'electrical.power_lines' => [
            'resource_code' => '21.1.06.09-0152',
            'unit' => '1000 м',
            'source_unit' => '1000 м',
            'price_factor' => 1.0,
            'quantity_per_work_unit' => 0.00105,
            'assumption_code' => 'residential_power_cable_vvgng_ls_3x2_5_with_waste_5_percent',
        ],
        'lighting.lines' => [
            'resource_code' => '21.1.06.09-0151',
            'unit' => '1000 м',
            'source_unit' => '1000 м',
            'price_factor' => 1.0,
            'quantity_per_work_unit' => 0.00105,
            'assumption_code' => 'residential_lighting_cable_vvgng_ls_3x1_5_with_waste_5_percent',
        ],
        'electrical.panel' => [
            'resource_code' => '20.4.04.02-0003',
            'fallback_group_code' => '20.4.04.02',
            'fallback_name_markers' => ['щит'],
            'unit' => 'pcs',
            'source_unit' => 'шт',
            'price_factor' => 1.0,
            'quantity_per_work_unit' => 1.0,
            'assumption_code' => 'residential_recessed_distribution_panel_24_modules',
        ],
        'electrical.outlets' => [
            'resource_code' => '20.4.03.06-1036',
            'unit' => 'pcs',
            'source_unit' => 'шт',
            'price_factor' => 1.0,
            'quantity_per_work_unit' => 1.0,
            'assumption_code' => 'residential_recessed_grounded_socket_with_shutter',
        ],
        'electrical.switches' => [
            'resource_code' => '20.4.01.02-1023',
            'unit' => 'pcs',
            'source_unit' => 'шт',
            'price_factor' => 1.0,
            'quantity_per_work_unit' => 1.0,
            'assumption_code' => 'residential_recessed_single_switch',
        ],
        'lighting.fixtures' => [
            'resource_code' => '59.1.20.03-0798',
            'fallback_group_code' => '59.1.20.03',
            'fallback_name_markers' => ['светиль'],
            'semantic_fallback_name_markers' => ['светиль', 'светодиод', 'потолоч'],
            'unit' => 'pcs',
            'source_unit' => 'шт',
            'price_factor' => 1.0,
            'quantity_per_work_unit' => 1.0,
            'assumption_code' => 'residential_led_ceiling_luminaire_18w',
        ],
    ];

    public function __construct(
        private ResidentialMaterialScenarioCatalog $scenarios = new ResidentialMaterialScenarioCatalog,
    ) {}

    /** @return array<string, mixed>|null */
    public function requirementForIntent(array $intent): ?array
    {
        $scenario = $intent['specialization_scenario'] ?? null;
        $workItemKey = is_array($scenario) ? trim((string) ($scenario['work_item_key'] ?? '')) : '';
        if ($workItemKey === '' || ! isset(self::REQUIREMENTS[$workItemKey])) {
            return null;
        }

        if ($this->scenarios->resolve($scenario, $workItemKey, 'residential') === null) {
            return null;
        }

        return [
            'version' => self::VERSION,
            'work_item_key' => $workItemKey,
            ...self::REQUIREMENTS[$workItemKey],
        ];
    }

    /** @return list<string> */
    public function resourceCodes(): array
    {
        return array_values(array_unique(array_column(self::REQUIREMENTS, 'resource_code')));
    }

    /** @return list<string> */
    public function fallbackGroupCodes(): array
    {
        return array_values(array_unique(array_filter(array_column(self::REQUIREMENTS, 'fallback_group_code'))));
    }

    /** @return list<list<string>> */
    public function semanticFallbackNameMarkerSets(): array
    {
        return array_values(array_filter(array_map(
            static fn (array $requirement): array => array_values(array_filter(array_map(
                'strval',
                is_array($requirement['semantic_fallback_name_markers'] ?? null)
                    ? $requirement['semantic_fallback_name_markers']
                    : [],
            ))),
            self::REQUIREMENTS,
        )));
    }

    /** @param array<string, mixed> $requirement @param list<object> $rows */
    public function resourceFromPriceRows(array $requirement, array $rows): ?array
    {
        foreach ($rows as $row) {
            $resource = $this->resourceFromPriceRow($requirement, $row);
            if ($resource !== null) {
                return $resource;
            }
        }

        $groupCode = trim((string) ($requirement['fallback_group_code'] ?? ''));
        $markers = is_array($requirement['fallback_name_markers'] ?? null)
            ? array_values(array_filter(array_map('strval', $requirement['fallback_name_markers'])))
            : [];
        if ($groupCode === '' || $markers === []) {
            return null;
        }

        $eligible = array_values(array_filter($rows, function (object $row) use ($requirement, $groupCode, $markers): bool {
            $name = mb_strtolower(trim((string) ($row->resource_name ?? '')));

            return preg_match('/^'.preg_quote($groupCode, '/').'-\d{4}$/D', trim((string) ($row->resource_code ?? ''))) === 1
                && trim((string) ($row->unit ?? '')) === ($requirement['source_unit'] ?? null)
                && array_filter($markers, static fn (string $marker): bool => ! str_contains($name, mb_strtolower($marker))) === []
                && $this->validPriceRow($row);
        }));
        if ($eligible === []) {
            $semanticMarkers = is_array($requirement['semantic_fallback_name_markers'] ?? null)
                ? array_values(array_filter(array_map('strval', $requirement['semantic_fallback_name_markers'])))
                : [];
            $eligible = array_values(array_filter($rows, function (object $row) use ($requirement, $semanticMarkers): bool {
                $name = mb_strtolower(trim((string) ($row->resource_name ?? '')));

                return $semanticMarkers !== []
                    && preg_match('/^\d{2}\.\d\.\d{2}\.\d{2}-\d{4}$/D', trim((string) ($row->resource_code ?? ''))) === 1
                    && trim((string) ($row->unit ?? '')) === ($requirement['source_unit'] ?? null)
                    && array_filter($semanticMarkers, static fn (string $marker): bool => ! str_contains($name, mb_strtolower($marker))) === []
                    && $this->validPriceRow($row);
            }));
            if ($eligible === []) {
                return null;
            }
            $selectionPolicy = 'semantic_catalog_attributes_median';
        } else {
            $selectionPolicy = 'semantic_group_median';
        }

        $regional = array_values(array_filter(
            $eligible,
            static fn (object $row): bool => ($row->price_source ?? null) === 'regional_catalog',
        ));
        if ($regional !== []) {
            $eligible = $regional;
        }
        usort($eligible, static function (object $left, object $right): int {
            $byPrice = (float) $left->base_price <=> (float) $right->base_price;

            return $byPrice !== 0 ? $byPrice : strcmp((string) $left->resource_code, (string) $right->resource_code);
        });
        $selected = $eligible[intdiv(count($eligible) - 1, 2)];

        return $this->mapPriceRow($requirement, $selected, $selectionPolicy);
    }

    /** @param array<string, mixed> $requirement */
    public function resourceFromPriceRow(array $requirement, object $row): ?array
    {
        $resourceCode = trim((string) ($row->resource_code ?? ''));
        if ($resourceCode !== ($requirement['resource_code'] ?? null) || ! $this->validPriceRow($row)
            || trim((string) ($row->unit ?? '')) !== ($requirement['source_unit'] ?? null)) {
            return null;
        }

        return $this->mapPriceRow($requirement, $row, 'exact_code');
    }

    private function validPriceRow(object $row): bool
    {
        return is_numeric($row->base_price ?? null) && (float) $row->base_price > 0
            && is_int($row->price_id ?? null) && $row->price_id > 0
            && in_array(trim((string) ($row->price_source ?? '')), ['regional_catalog', 'fsbc_base', 'fsnb_base'], true)
            && trim((string) ($row->price_source_version ?? '')) !== '';
    }

    /** @param array<string, mixed> $requirement */
    private function mapPriceRow(array $requirement, object $row, string $selectionPolicy): array
    {
        $resourceCode = trim((string) $row->resource_code);
        $sourceUnit = trim((string) $row->unit);
        $sourcePrice = $row->base_price;
        $priceId = $row->price_id;
        $priceSource = trim((string) $row->price_source);
        $priceSourceVersion = trim((string) $row->price_source_version);

        $priceFactor = (float) $requirement['price_factor'];

        return [
            'code' => $resourceCode,
            'name' => trim((string) ($row->resource_name ?? $resourceCode)),
            'unit' => (string) $requirement['unit'],
            'price_unit' => (string) $requirement['unit'],
            'quantity' => (float) $requirement['quantity_per_work_unit'],
            'price_id' => $priceId,
            'unit_price' => (string) round((float) $sourcePrice * $priceFactor, 6),
            'price_source' => $priceSource,
            'price_source_version' => $priceSourceVersion,
            'linked_resource_id' => is_int($row->construction_resource_id ?? null)
                ? $row->construction_resource_id
                : null,
            'norm_resource_id' => null,
            'project_material_requirement' => [
                'version' => self::VERSION,
                'work_item_key' => (string) $requirement['work_item_key'],
                'assumption_code' => (string) $requirement['assumption_code'],
                'source_unit_price' => (string) $sourcePrice,
                'source_price_unit' => $sourceUnit,
                'price_conversion_factor' => (string) $priceFactor,
                'preferred_resource_code' => (string) $requirement['resource_code'],
                'selection_policy' => $selectionPolicy,
            ],
        ];
    }
}
