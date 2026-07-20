<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class ResidentialProjectMaterialCatalog
{
    public const VERSION = 'residential_project_material:v1';

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

    /** @param array<string, mixed> $requirement */
    public function resourceFromPriceRow(array $requirement, object $row): ?array
    {
        $resourceCode = trim((string) ($row->resource_code ?? ''));
        $sourceUnit = trim((string) ($row->unit ?? ''));
        $sourcePrice = $row->base_price ?? null;
        $priceId = $row->price_id ?? null;
        $priceSource = trim((string) ($row->price_source ?? ''));
        $priceSourceVersion = trim((string) ($row->price_source_version ?? ''));
        if ($resourceCode !== ($requirement['resource_code'] ?? null)
            || $sourceUnit !== ($requirement['source_unit'] ?? null)
            || ! is_numeric($sourcePrice) || (float) $sourcePrice <= 0
            || ! is_int($priceId) || $priceId <= 0
            || ! in_array($priceSource, ['regional_catalog', 'fsbc_base', 'fsnb_base'], true)
            || $priceSourceVersion === '') {
            return null;
        }

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
            ],
        ];
    }
}
