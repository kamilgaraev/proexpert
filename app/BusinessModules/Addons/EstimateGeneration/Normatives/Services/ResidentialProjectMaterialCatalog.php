<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class ResidentialProjectMaterialCatalog
{
    public const VERSION = 'residential_project_material:v5';

    public const CANDIDATE_POOL_VERSION = 'project_material_candidate_pool:v2';

    public const MAX_CANDIDATE_PRICE_IDS = 2048;

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
            'semantic_fallback_name_markers' => ['светиль', 'светодиод'],
            'semantic_eligibility_policy' => [
                'version' => 'residential_ceiling_luminaire_attributes:v2',
                'allowed_power_watts' => [18],
                'allowed_ip_ratings' => ['ip20'],
                'allow_missing_ip_rating' => true,
                'required_any_name_markers' => ['потолоч', 'накладн', 'встраив'],
                'forbidden_name_markers' => [
                    'общественн', 'офис', 'промышленн', 'склад',
                    '595х595', '595x595', '600х600', '600x600',
                ],
            ],
            'unit' => 'pcs',
            'source_unit' => 'шт',
            'price_factor' => 1.0,
            'quantity_per_work_unit' => 1.0,
            'assumption_code' => 'residential_led_ceiling_luminaire_18w',
        ],
        'heating.unit' => [
            'resource_code' => '89.1.63.01-0079',
            'unit' => 'pcs',
            'source_unit' => 'шт',
            'price_factor' => 1.0,
            'quantity_per_work_unit' => 1.0,
            'assumption_code' => 'residential_wall_mounted_single_circuit_electric_boiler_18kw',
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
        $exact = [];
        foreach ($rows as $row) {
            $resource = $this->resourceFromPriceRow($requirement, $row);
            if ($resource !== null) {
                $exact[] = ['resource' => $resource, 'row' => $row];
            }
        }
        if ($exact !== []) {
            $regional = array_values(array_filter(
                $exact,
                static fn (array $candidate): bool => ($candidate['row']->price_source ?? null) === 'regional_catalog',
            ));
            $pool = $regional !== [] ? $regional : $exact;

            return $this->withCandidatePriceIds(
                $pool[0]['resource'],
                array_column($pool, 'row'),
            );
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
                && $this->semanticCandidateEligible($requirement, $row)
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
                    && $this->semanticCandidateEligible($requirement, $row)
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

        return $this->withCandidatePriceIds(
            $this->mapPriceRow($requirement, $selected, $selectionPolicy),
            $eligible,
        );
    }

    /** @param array<string, mixed> $requirement */
    public function resourceFromPriceRow(array $requirement, object $row): ?array
    {
        $resourceCode = trim((string) ($row->resource_code ?? ''));
        if ($resourceCode !== ($requirement['resource_code'] ?? null) || ! $this->validPriceRow($row)
            || trim((string) ($row->unit ?? '')) !== ($requirement['source_unit'] ?? null)) {
            return null;
        }

        return $this->withCandidatePriceIds($this->mapPriceRow($requirement, $row, 'exact_code'), [$row]);
    }

    private function validPriceRow(object $row): bool
    {
        return is_numeric($row->base_price ?? null) && (float) $row->base_price > 0
            && is_int($row->price_id ?? null) && $row->price_id > 0
            && in_array(trim((string) ($row->price_source ?? '')), ['regional_catalog', 'fsbc_base', 'fsnb_base'], true)
            && trim((string) ($row->price_source_version ?? '')) !== '';
    }

    /** @param array<string, mixed> $requirement */
    private function semanticCandidateEligible(array $requirement, object $row): bool
    {
        $policy = $requirement['semantic_eligibility_policy'] ?? null;
        if (! is_array($policy)) {
            return true;
        }

        $name = mb_strtolower(str_replace('ё', 'е', trim((string) ($row->resource_name ?? ''))));
        foreach ((array) ($policy['forbidden_name_markers'] ?? []) as $marker) {
            if (is_string($marker) && $marker !== '' && str_contains($name, mb_strtolower($marker))) {
                return false;
            }
        }

        $requiredAnyMarkers = array_values(array_filter(
            array_map('strval', (array) ($policy['required_any_name_markers'] ?? [])),
            static fn (string $marker): bool => $marker !== '',
        ));
        if ($requiredAnyMarkers !== [] && array_filter(
            $requiredAnyMarkers,
            static fn (string $marker): bool => str_contains($name, mb_strtolower($marker)),
        ) === []) {
            return false;
        }

        $allowedPowers = array_values(array_filter(
            array_map(static fn (mixed $power): float => is_numeric($power) ? (float) $power : 0.0, (array) ($policy['allowed_power_watts'] ?? [])),
            static fn (float $power): bool => $power > 0,
        ));
        if ($allowedPowers !== []) {
            preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:вт|w)(?![a-zа-я0-9])/ui', $name, $matches);
            $powers = array_map(
                static fn (string $power): float => (float) str_replace(',', '.', $power),
                $matches[1] ?? [],
            );
            if ($powers === [] || array_filter(
                $powers,
                static fn (float $power): bool => ! in_array($power, $allowedPowers, true),
            ) !== []) {
                return false;
            }
        }

        preg_match_all('/\bip\s*([0-9]{2})\b/ui', $name, $matches);
        $ratings = array_map(static fn (string $rating): string => 'ip'.$rating, $matches[1] ?? []);
        if ($ratings === []) {
            return ($policy['allow_missing_ip_rating'] ?? false) === true;
        }

        $allowedRatings = array_map('strval', (array) ($policy['allowed_ip_ratings'] ?? []));

        return $allowedRatings !== [] && array_filter(
            $ratings,
            static fn (string $rating): bool => ! in_array($rating, $allowedRatings, true),
        ) === [];
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
                ...isset($requirement['semantic_eligibility_policy']['version'])
                    ? ['semantic_eligibility_policy' => (string) $requirement['semantic_eligibility_policy']['version']]
                    : [],
            ],
        ];
    }

    /** @param array<string, mixed> $resource @param list<object> $rows */
    private function withCandidatePriceIds(array $resource, array $rows): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (object $row): int => is_int($row->price_id ?? null) ? $row->price_id : 0,
            $rows,
        ), static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        if ($ids === [] || count($ids) > self::MAX_CANDIDATE_PRICE_IDS) {
            return null;
        }
        $resource['project_material_requirement'] = [
            ...$resource['project_material_requirement'],
            'candidate_pool_version' => self::CANDIDATE_POOL_VERSION,
            'candidate_resource_price_ids' => $ids,
        ];

        return $resource;
    }
}
