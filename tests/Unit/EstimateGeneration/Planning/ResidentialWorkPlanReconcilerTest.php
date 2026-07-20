<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Planning\AiWorkCompositionAdviceData;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ResidentialWorkCompositionCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ResidentialWorkPlanReconciler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResidentialWorkPlanReconcilerTest extends TestCase
{
    #[Test]
    public function ai_cannot_remove_a_required_work_item(): void
    {
        $plan = [
            'generation_mode' => 'ai_assisted',
            'object_profile' => ['object_type' => 'house', 'floors' => 2],
            'package_plan' => ['packages' => []],
            'local_estimates' => [[
                'key' => 'heating',
                'sections' => [['work_items' => [[
                    'name' => 'Монтаж радиаторов',
                    'metadata' => ['quantity_key' => 'heating.radiators'],
                ]]]],
            ]],
        ];

        $result = (new ResidentialWorkPlanReconciler)->reconcile($plan, new AiWorkCompositionAdviceData(
            'completed',
            ['heating.radiators' => [
                'status' => 'not_applicable',
                'reason_codes' => ['model_claimed_not_applicable'],
                'confidence' => 0.7,
            ]],
            'test-model',
        ));

        $item = $result['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('Монтаж радиаторов', $item['name']);
        self::assertTrue($item['metadata']['composition_coverage']['required']);
        self::assertSame('not_applicable', $item['metadata']['composition_coverage']['ai_status']);
        self::assertSame('ai_bounded_catalog', $item['metadata']['composition_coverage']['source']);
    }

    #[Test]
    public function ai_recompile_cannot_shrink_a_complete_residential_baseline_or_erase_quantity_types(): void
    {
        $baseline = $this->residentialPlan('house');
        $truncated = $baseline;
        $remaining = 25;
        foreach ($truncated['local_estimates'] as $estimateIndex => $localEstimate) {
            foreach ($localEstimate['sections'] as $sectionIndex => $section) {
                $items = array_slice($section['work_items'], 0, max(0, $remaining));
                $remaining -= count($items);
                if (in_array($localEstimate['key'], ['electrical', 'lighting'], true)) {
                    $items = [];
                    foreach ($section['work_items'] as $item) {
                        if (! in_array($item['metadata']['quantity_key'], [
                            'electrical.panel',
                            'electrical.outlets',
                            'electrical.switches',
                            'lighting.fixtures',
                        ], true)) {
                            continue;
                        }
                        $items[] = [
                            'name' => $item['name'],
                            'unit' => 'компл',
                            'metadata' => ['composition_work_key' => $item['metadata']['quantity_key']],
                        ];
                    }
                }
                $truncated['local_estimates'][$estimateIndex]['sections'][$sectionIndex]['work_items'] = $items;
            }
        }

        $result = (new ResidentialWorkPlanReconciler)->reconcile(
            $truncated,
            new AiWorkCompositionAdviceData('completed', [], 'test-model'),
            $baseline,
        );
        $items = $this->itemsByWorkKey($result);

        self::assertGreaterThanOrEqual(50, count($items));
        self::assertSame($this->packageKeys($result), $this->localEstimateKeys($result));
        foreach ([
            'electrical.panel',
            'electrical.outlets',
            'electrical.switches',
            'lighting.fixtures',
        ] as $workKey) {
            self::assertArrayHasKey($workKey, $items);
            self::assertSame('шт', $items[$workKey]['unit']);
            self::assertSame($workKey, $items[$workKey]['quantity_formula']);
            self::assertSame($workKey, $items[$workKey]['metadata']['quantity_key']);
        }
    }

    #[Test]
    public function izhs_alias_enables_the_residential_completeness_guard(): void
    {
        $baseline = $this->residentialPlan('ИЖС');
        $truncated = $baseline;
        $truncated['local_estimates'] = array_slice($truncated['local_estimates'], 0, 1);

        $result = (new ResidentialWorkPlanReconciler)->reconcile(
            $truncated,
            new AiWorkCompositionAdviceData('completed', [], 'test-model'),
            $baseline,
        );

        self::assertGreaterThanOrEqual(50, count($this->itemsByWorkKey($result)));
    }

    #[Test]
    public function missing_package_restores_only_required_keys_and_keeps_existing_ai_additions(): void
    {
        $baseline = $this->residentialPlan('house');
        foreach ($baseline['local_estimates'] as $estimateIndex => $localEstimate) {
            if ($localEstimate['key'] !== 'electrical') {
                continue;
            }
            $baseline['local_estimates'][$estimateIndex]['sections'][0]['work_items'][] = [
                'name' => 'Офисная система контроля доступа',
                'unit' => 'компл',
                'quantity_formula' => 'electrical.office_access_control',
                'metadata' => ['quantity_key' => 'electrical.office_access_control'],
            ];
        }
        $current = $baseline;
        $current['local_estimates'] = array_values(array_filter(
            $current['local_estimates'],
            static fn (array $estimate): bool => $estimate['key'] !== 'electrical',
        ));
        $current['package_plan']['packages'] = array_values(array_filter(
            $current['package_plan']['packages'],
            static fn (array $package): bool => $package['key'] !== 'electrical',
        ));
        $current['local_estimates'][0]['sections'][0]['work_items'][] = [
            'name' => 'Дополнительная работа по решению модели',
            'unit' => 'шт',
            'quantity_formula' => 'ai.optional.confirmed',
            'metadata' => ['quantity_key' => 'ai.optional.confirmed'],
        ];

        $result = (new ResidentialWorkPlanReconciler)->reconcile(
            $current,
            new AiWorkCompositionAdviceData('completed', [], 'test-model'),
            $baseline,
        );
        $items = $this->itemsByWorkKey($result);

        self::assertArrayHasKey('electrical.panel', $items);
        self::assertArrayHasKey('ai.optional.confirmed', $items);
        self::assertArrayNotHasKey('electrical.office_access_control', $items);
        self::assertSame($this->packageKeys($result), $this->localEstimateKeys($result));
        self::assertContains('electrical', $this->packageKeys($result));
        self::assertSame(
            array_sum(array_column($result['package_plan']['packages'], 'target_items_min')),
            $result['package_plan']['target_items_min_total'],
        );
        self::assertSame(
            array_sum(array_column($result['package_plan']['packages'], 'target_items_max')),
            $result['package_plan']['target_items_max_total'],
        );
    }

    #[DataProvider('nonResidentialObjectTypes')]
    public function test_non_residential_or_unconfirmed_object_type_is_not_polluted_by_residential_scope(string $objectType): void
    {
        $plan = [
            'generation_mode' => 'ai_assisted',
            'object_profile' => ['object_type' => $objectType, 'floors' => 2],
            'package_plan' => ['packages' => [['key' => 'industrial_floor']]],
            'local_estimates' => [[
                'key' => 'industrial_floor',
                'sections' => [[
                    'key' => 'slab',
                    'work_items' => [[
                        'name' => 'Промышленный пол',
                        'unit' => 'м3',
                        'metadata' => ['quantity_key' => 'warehouse.floor_concrete'],
                    ]],
                ]],
            ]],
        ];
        $baseline = $this->residentialPlan('house');

        $result = (new ResidentialWorkPlanReconciler)->reconcile(
            $plan,
            new AiWorkCompositionAdviceData('completed', [], 'test-model'),
            $baseline,
        );

        self::assertSame($plan, $result);
    }

    public static function nonResidentialObjectTypes(): iterable
    {
        yield 'warehouse' => ['warehouse'];
        yield 'industrial' => ['industrial'];
        yield 'production' => ['production'];
        yield 'mixed warehouse and office' => ['mixed_warehouse_office'];
        yield 'unconfirmed building' => ['building'];
        yield 'empty' => [''];
    }

    private function residentialPlan(string $objectType): array
    {
        $plan = [
            'generation_mode' => 'ai_assisted',
            'object_profile' => [
                'object_type' => $objectType,
                'floors' => 2,
                'planning_signals' => ['roof_type' => 'pitched'],
            ],
            'package_plan' => ['packages' => []],
            'local_estimates' => [],
        ];
        foreach ((new ResidentialWorkCompositionCatalog)->requirements($plan) as $packageKey => $workKeys) {
            $plan['package_plan']['packages'][] = [
                'key' => $packageKey,
                'title' => $packageKey,
                'scope_type' => $packageKey,
                'target_items_min' => count($workKeys),
                'target_items_max' => count($workKeys) + 5,
            ];
            $plan['local_estimates'][] = [
                'key' => $packageKey,
                'sections' => [[
                    'key' => $packageKey.'.main',
                    'work_items' => array_map(
                        static fn (string $workKey): array => [
                            'name' => $workKey,
                            'unit' => 'шт',
                            'quantity_formula' => $workKey,
                            'metadata' => ['quantity_key' => $workKey],
                        ],
                        $workKeys,
                    ),
                ]],
            ];
        }

        return $plan;
    }

    /** @return array<string, array<string, mixed>> */
    private function itemsByWorkKey(array $plan): array
    {
        $items = [];
        foreach ($plan['local_estimates'] as $localEstimate) {
            foreach ($localEstimate['sections'] as $section) {
                foreach ($section['work_items'] as $workItem) {
                    $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
                    $workKey = (string) (
                        $metadata['composition_work_key']
                        ?? $metadata['quantity_key']
                        ?? $workItem['quantity_formula']
                        ?? ''
                    );
                    if ($workKey !== '') {
                        $items[$workKey] = $workItem;
                    }
                }
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function packageKeys(array $plan): array
    {
        $keys = array_map(
            static fn (array $package): string => (string) $package['key'],
            $plan['package_plan']['packages'],
        );
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return list<string> */
    private function localEstimateKeys(array $plan): array
    {
        $keys = array_map(
            static fn (array $estimate): string => (string) $estimate['key'],
            $plan['local_estimates'],
        );
        sort($keys, SORT_STRING);

        return $keys;
    }
}
