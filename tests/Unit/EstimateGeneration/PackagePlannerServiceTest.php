<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\ObjectProfileData;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use PHPUnit\Framework\TestCase;

class PackagePlannerServiceTest extends TestCase
{
    public function test_house_plan_contains_required_local_estimates_and_has_product_density(): void
    {
        $profile = new ObjectProfileData(
            objectType: 'house',
            area: 150.0,
            floors: 2,
            rooms: 8,
            regionCode: 'RU-MOS',
            regionalPriceVersionId: 1,
            quarterKey: '2026-q1',
            dimensions: ['length' => 10, 'width' => 15],
            finishLevels: ['rough', 'finish'],
            engineeringSystems: ['electrical', 'plumbing', 'heating', 'ventilation'],
            assumptions: [],
            missingInputs: [],
            confidence: 0.86,
        );

        $plan = $this->planner()->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertContains('foundation', $keys);
        $this->assertContains('walls', $keys);
        $this->assertContains('roof', $keys);
        $this->assertContains('electrical', $keys);
        $this->assertContains('heating', $keys);
        $this->assertGreaterThanOrEqual(250, $plan->targetItemsMinTotal());
        $this->assertGreaterThanOrEqual(15, count($plan->packages));
        $this->assertSame(['preconstruction'], array_values(array_map(
            static fn (array $package): string => (string) $package['key'],
            array_filter(
                $plan->packages,
                static fn (array $package): bool => ($package['coverage_required'] ?? null) !== true,
            ),
        )));
        $preconstruction = array_values(array_filter(
            $plan->packages,
            static fn (array $package): bool => ($package['key'] ?? null) === 'preconstruction',
        ))[0];
        $this->assertSame('document_scope_required', $preconstruction['coverage_warning'] ?? null);
    }

    public function test_house_plan_does_not_include_optional_site_packages_without_explicit_scope(): void
    {
        $profile = new ObjectProfileData(
            objectType: 'house',
            area: 150.0,
            floors: 2,
            rooms: 8,
            regionCode: 'RU-MOS',
            regionalPriceVersionId: 1,
            quarterKey: '2026-q1',
            dimensions: [],
            finishLevels: ['rough', 'finish'],
            engineeringSystems: ['electrical', 'plumbing', 'heating'],
            assumptions: [],
            missingInputs: [],
            confidence: 0.86,
        );

        $plan = $this->planner()->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertNotContains('external_networks', $keys);
        $this->assertNotContains('siteworks', $keys);
        $this->assertNotContains('roads', $keys);
    }

    public function test_profile_from_analysis_adds_optional_site_packages_when_scope_is_explicit(): void
    {
        $analysis = [
            'object' => [
                'object_type' => 'house',
                'building_type' => 'house',
                'description' => 'External networks, landscaping and driveway roads are included in the project.',
                'area' => 150,
            ],
            'document_context' => [
                'context_text' => '',
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);
        $plan = $this->planner()->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertContains('external_networks', $keys);
        $this->assertContains('siteworks', $keys);
        $this->assertContains('roads', $keys);
    }

    public function test_warehouse_plan_uses_industrial_packages_and_density(): void
    {
        $profile = new ObjectProfileData(
            objectType: 'warehouse',
            area: 2000.0,
            floors: 1,
            rooms: null,
            regionCode: 'RU-TA',
            regionalPriceVersionId: 1,
            quarterKey: '2026-q1',
            dimensions: [],
            finishLevels: [],
            engineeringSystems: ['electrical', 'ventilation', 'fire_safety'],
            assumptions: [],
            missingInputs: [],
            confidence: 0.82,
        );

        $plan = $this->planner()->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertContains('metal_frame', $keys);
        $this->assertContains('industrial_floor', $keys);
        $this->assertContains('fire_safety', $keys);
        $this->assertGreaterThanOrEqual(600, $plan->targetItemsMinTotal());
    }

    public function test_profile_from_analysis_uses_ocr_document_context_for_warehouse_plan(): void
    {
        $analysis = [
            'object' => [
                'building_type' => 'custom',
                'description' => '',
                'area' => 1280,
                'zones' => [
                    ['scope_key' => 'warehouse_area', 'label' => 'Склад', 'area_m2' => 900.0],
                    ['scope_key' => 'office_area', 'label' => 'Офис', 'area_m2' => 280.0],
                ],
            ],
            'document_context' => [
                'context_text' => 'Складской корпус с офисной зоной, пожарной сигнализацией и освещением.',
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);
        $plan = $this->planner()->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertSame('mixed_warehouse_office', $profile->objectType);
        $this->assertSame(1280.0, $profile->area);
        $this->assertContains('industrial_floor', $keys);
        $this->assertContains('office_partitions', $keys);
    }

    public function test_profile_from_analysis_keeps_explicit_residential_type_over_storage_wording(): void
    {
        $analysis = [
            'object' => [
                'object_type' => 'warehouse',
                'building_type' => 'Жилой',
                'description' => 'Индивидуальный жилой дом. Временное складирование материалов на площадке.',
                'area' => 151.76,
            ],
            'document_context' => [
                'context_text' => 'Фундамент, стены, кровля и временное складирование материалов.',
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);
        $plan = $this->planner()->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertSame('house', $profile->objectType);
        $this->assertContains('foundation', $keys);
        $this->assertContains('lighting', $keys);
        $this->assertNotContains('industrial_floor', $keys);
    }

    public function test_profile_from_analysis_keeps_explicit_house_type_over_office_and_warehouse_context(): void
    {
        $analysis = [
            'object' => [
                'object_type' => 'house',
                'building_type' => 'custom',
                'description' => 'Индивидуальный жилой дом площадью 180 м2.',
                'area' => 180,
            ],
            'document_context' => [
                'context_text' => 'В документах встречаются служебные шаблоны офиса и склада.',
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);
        $plan = $this->planner()->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertSame('house', $profile->objectType);
        $this->assertContains('foundation', $keys);
        $this->assertNotContains('industrial_floor', $keys);
        $this->assertNotContains('office_partitions', $keys);
    }

    public function test_cottage_description_is_not_reclassified_by_office_and_warehouse_template_words(): void
    {
        $analysis = [
            'object' => [
                'object_type' => 'custom',
                'building_type' => 'custom',
                'description' => 'Двухэтажный коттедж площадью 180 м2.',
            ],
            'document_context' => [
                'context_text' => 'В служебном шаблоне встречаются слова офис и склад.',
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);
        $keys = array_column($this->planner()->plan($profile)->packages, 'key');

        self::assertSame('house', $profile->objectType);
        self::assertContains('foundation', $keys);
        self::assertContains('lighting', $keys);
        self::assertNotContains('industrial_floor', $keys);
        self::assertNotContains('office_partitions', $keys);
    }

    public function test_plan_only_geometry_uses_only_evidence_backed_fitout_packages(): void
    {
        $analysis = [
            'object' => [
                'object_type' => 'custom',
                'building_type' => 'custom',
                'description' => '',
            ],
            'document_context' => [
                'context_text' => "Планировка квартиры\nГостиная 46,52 м2\nКухня 9,99 м2",
                'quantity_takeoffs' => [
                    $this->takeoff('floor_finish_area', 'finish.floor', 87.14, 'м2'),
                    $this->takeoff('rough_floor_area', 'rough.floor', 87.14, 'м2'),
                    $this->takeoff('wall_finish_area', 'rough.walls', 235.28, 'м2'),
                    $this->takeoff('ceiling_finish_area', 'office.ceiling', 87.14, 'м2'),
                    $this->takeoff('opening_count', 'openings.doors', 8, 'шт'),
                ],
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);
        $plan = $this->planner()->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertSame('floor_plan_geometry', $profile->objectType);
        $this->assertTrue($profile->planningSignals['plan_only_geometry']);
        $this->assertContains('rough_finishing', $keys);
        $this->assertContains('finish_finishing', $keys);
        $this->assertContains('openings', $keys);
        $this->assertNotContains('foundation', $keys);
        $this->assertNotContains('earthworks', $keys);
        $this->assertNotContains('roof', $keys);
        $this->assertNotContains('facade', $keys);
        $this->assertNotContains('electrical', $keys);
        $this->assertLessThan(80, $plan->targetItemsMinTotal());
    }

    public function test_strict_residential_floor_plan_keeps_only_evidence_backed_packages(): void
    {
        $analysis = [
            'generation_mode' => 'strict_documents',
            'object' => [
                'object_type' => 'custom',
                'building_type' => 'Жилой',
                'description' => 'Нужна смета на одноэтажный дом по прикрепленной планировке.',
            ],
            'document_context' => [
                'context_text' => "Планировка дома\nГостиная 46,52 м2\nСпальня 17,65 м2",
                'quantity_takeoffs' => [
                    $this->takeoff('floor_finish_area', 'finish.floor', 169.39, 'м2'),
                    $this->takeoff('rough_floor_area', 'rough.floor', 169.39, 'м2'),
                    $this->takeoff('wall_finish_area', 'rough.walls', 535.76, 'м2'),
                    $this->takeoff('skirting_length', 'finish.baseboard', 198.43, 'м'),
                ],
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);
        $plan = $this->planner()->plan($profile);
        $requirements = $this->planner()->documentRequirements($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertSame('house', $profile->objectType);
        $this->assertSame('strict_documents', $profile->planningSignals['generation_mode']);
        $this->assertTrue($profile->planningSignals['plan_only_geometry']);
        $this->assertContains('rough_finishing', $keys);
        $this->assertContains('finish_finishing', $keys);
        $this->assertNotContains('foundation', $keys);
        $this->assertNotContains('roof', $keys);
        $this->assertNotContains('electrical', $keys);
        $this->assertSame('document_limited', $requirements['coverage']);
        $this->assertTrue($requirements['missing_for_full_estimate']);
        $this->assertCount(7, $requirements['items']);
    }

    public function test_ai_assisted_residential_floor_plan_expands_to_house_package_frame(): void
    {
        $analysis = [
            'generation_mode' => 'ai_assisted',
            'object' => [
                'object_type' => 'custom',
                'building_type' => 'Жилой',
                'description' => 'Хочу дом по прикрепленной планировке.',
            ],
            'document_context' => [
                'context_text' => "Планировка дома\nГостиная 46,52 м2\nСпальня 17,65 м2",
                'quantity_takeoffs' => [
                    $this->takeoff('floor_finish_area', 'finish.floor', 169.39, 'м2'),
                    $this->takeoff('rough_floor_area', 'rough.floor', 169.39, 'м2'),
                    $this->takeoff('wall_finish_area', 'rough.walls', 535.76, 'м2'),
                    $this->takeoff('skirting_length', 'finish.baseboard', 198.43, 'м'),
                ],
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);
        $plan = $this->planner()->plan($profile);
        $requirements = $this->planner()->documentRequirements($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertSame('house', $profile->objectType);
        $this->assertSame('ai_assisted', $profile->planningSignals['generation_mode']);
        $this->assertTrue($profile->planningSignals['plan_only_geometry']);
        $this->assertContains('foundation', $keys);
        $this->assertContains('walls', $keys);
        $this->assertContains('roof', $keys);
        $this->assertContains('electrical', $keys);
        $this->assertContains('rough_finishing', $keys);
        $this->assertContains('finish_finishing', $keys);
        $this->assertGreaterThanOrEqual(16, count($plan->packages));
        $this->assertSame('assumption_expanded', $requirements['coverage']);
        $this->assertTrue($requirements['missing_for_full_estimate']);
    }

    public function test_plan_only_geometry_adds_engineering_packages_only_from_specification_takeoffs(): void
    {
        $analysis = [
            'object' => [
                'object_type' => 'custom',
                'building_type' => 'custom',
                'description' => '',
            ],
            'document_context' => [
                'context_text' => 'Планировка и спецификация оборудования',
                'quantity_takeoffs' => [
                    $this->takeoff('floor_finish_area', 'finish.floor', 87.14, 'м2'),
                    $this->takeoff('specification_quantity', 'warehouse.lighting', 42, 'шт'),
                    $this->takeoff('specification_quantity', 'heating.pipe', 36, 'м'),
                    $this->takeoff('specification_quantity', 'sanitary.points', 5, 'шт'),
                    $this->takeoff('specification_quantity', 'sewerage.pipe', 18, 'м'),
                    $this->takeoff('specification_quantity', 'ventilation.air_exchange', 87.14, 'м2'),
                ],
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);
        $plan = $this->planner()->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertSame('floor_plan_geometry', $profile->objectType);
        $this->assertContains('rough_finishing', $keys);
        $this->assertContains('finish_finishing', $keys);
        $this->assertContains('electrical', $keys);
        $this->assertContains('plumbing', $keys);
        $this->assertContains('sewerage', $keys);
        $this->assertContains('heating', $keys);
        $this->assertContains('ventilation', $keys);
        $this->assertNotContains('foundation', $keys);
        $this->assertNotContains('walls', $keys);
        $this->assertNotContains('roof', $keys);
    }

    public function test_baseboard_work_volume_statement_does_not_switch_to_plan_only_geometry_without_plan_signal(): void
    {
        $analysis = [
            'object' => [
                'object_type' => 'custom',
                'building_type' => 'custom',
                'description' => '',
            ],
            'document_context' => [
                'context_text' => 'Ведомость объемов работ',
                'quantity_takeoffs' => [[
                    'scope_key' => 'specification_quantity',
                    'name' => 'Монтаж плинтуса ПВХ',
                    'quantity' => 77,
                    'unit' => 'м',
                    'source_refs' => [[
                        'type' => 'document',
                        'filename' => 'ВОР.pdf',
                        'page_number' => 1,
                    ]],
                    'normalized_payload' => [
                        'quantity_key' => 'finish.baseboard',
                    ],
                ]],
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);

        $this->assertSame('document_evidence', $profile->objectType);
        $this->assertFalse($profile->planningSignals['plan_only_geometry']);
        $this->assertTrue($profile->planningSignals['floor_plan_finishing']);
    }

    public function test_work_volume_statement_uses_only_evidence_backed_packages_without_house_template(): void
    {
        $analysis = [
            'object' => [
                'object_type' => 'custom',
                'building_type' => 'custom',
                'description' => '',
            ],
            'document_context' => [
                'context_text' => 'Ведомость объемов работ',
                'scope_inferences' => [[
                    'normalized_payload' => [
                        'quantity_key' => 'earth.backfill',
                    ],
                ], [
                    'normalized_payload' => [
                        'quantity_key' => 'unmapped.abc123',
                    ],
                ]],
            ],
        ];

        $profile = $this->planner()->profileFromAnalysis($analysis);
        $plan = $this->planner()->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertSame('document_evidence', $profile->objectType);
        $this->assertContains('custom-earthworks', $keys);
        $this->assertContains('unmapped_quantity_rows', $keys);
        $this->assertNotContains('foundation', $keys);
        $this->assertNotContains('walls', $keys);
        $this->assertNotContains('roof', $keys);
    }

    private function planner(): PackagePlannerService
    {
        return new PackagePlannerService;
    }

    /**
     * @return array<string, mixed>
     */
    private function takeoff(string $scopeKey, string $quantityKey, float $quantity, string $unit): array
    {
        return [
            'scope_key' => $scopeKey,
            'name' => $quantityKey,
            'quantity' => $quantity,
            'unit' => $unit,
            'source_refs' => [[
                'type' => 'drawing',
                'filename' => 'flat-plan.png',
                'page_number' => 1,
            ]],
            'normalized_payload' => [
                'quantity_key' => $quantityKey,
            ],
        ];
    }
}
