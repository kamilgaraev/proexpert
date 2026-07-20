<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\FinalizedPackageDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationPackageItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FinalizedPackageDraftProjectorTest extends TestCase
{
    private const INPUT_VERSION = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function it_projects_prices_and_resources_from_finalized_snapshot_instead_of_stored_draft_values(): void
    {
        $draft = (new FinalizedPackageDraftProjector)->projectFromItems(
            $this->draft(),
            [$this->finalizedItem()],
        );

        $workItem = $draft['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('400.00', $workItem['total_cost']);
        self::assertSame('100.00', $workItem['materials_cost']);
        self::assertSame('300.00', $workItem['labor_cost']);
        self::assertSame('0.00', $workItem['machinery_cost']);
        self::assertSame('2.000000', $workItem['materials'][0]['quantity']);
        self::assertSame('50.000000', $workItem['materials'][0]['unit_price']);
        self::assertSame('100.00', $workItem['materials'][0]['total_price']);
        self::assertSame('finalized_project_material', $workItem['materials'][0]['quantity_basis']);
        self::assertSame('regional_catalog', $workItem['materials'][0]['price_source']);
        self::assertSame('2026-Q2', $workItem['materials'][0]['price_source_version']);
        self::assertSame(902, data_get($workItem, 'materials.0.project_material_selection.resource_price_id'));
        self::assertSame('3.000000', $workItem['labor'][0]['quantity']);
        self::assertSame('100.000000', $workItem['labor'][0]['unit_price']);
        self::assertSame('300.00', $workItem['labor'][0]['total_price']);
        self::assertSame('finalized_normative_resource', $workItem['labor'][0]['quantity_basis']);
        self::assertSame('regional_catalog', $workItem['labor'][0]['price_source']);
        self::assertSame('2026-Q2', $workItem['labor'][0]['price_source_version']);
        self::assertSame('12-01-013-07', $workItem['normative_match']['code']);
        self::assertSame('supplementary_project_material:v4', data_get(
            $workItem,
            'price_snapshot.coefficients.pricing_formula_version',
        ));
    }

    #[Test]
    public function ordinary_estimate_resource_metadata_keeps_finalized_price_provenance(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3)
            .'/app/BusinessModules/Addons/EstimateGeneration/Application/Apply/LaravelGeneratedEstimateWriter.php');

        self::assertIsString($source);
        foreach (['price_source', 'price_source_version', 'rounding_adjustment', 'project_material_selection'] as $field) {
            self::assertStringContainsString("'{$field}' => \$resource['{$field}'] ?? null", $source);
        }
    }

    #[Test]
    public function it_rejects_apply_when_a_draft_work_item_has_no_finalized_package_price(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('incomplete');

        (new FinalizedPackageDraftProjector)->projectFromItems($this->draft(), []);
    }

    #[Test]
    public function it_projects_a_base_only_v3_snapshot_without_project_material_rows(): void
    {
        $item = $this->finalizedItem();
        $snapshot = $item->price_snapshot;
        $snapshot['final_amount'] = '300.00';
        $snapshot['coefficients']['pricing_formula_version'] = 'project_resource:v3';
        $snapshot['coefficients']['project_material_evidence'] = [];
        $resources = $item->resources;
        $resources['materials'] = [];
        $this->replaceRawAttributes($item, [
            'unit_price' => '150.000000',
            'direct_cost' => '300.00',
            'total_cost' => '300.00',
            'price_snapshot' => $snapshot,
            'resources' => $resources,
        ]);

        $projected = (new FinalizedPackageDraftProjector)->projectFromItems($this->draft(), [$item]);
        $workItem = $projected['local_estimates'][0]['sections'][0]['work_items'][0];

        self::assertSame('300.00', $workItem['total_cost']);
        self::assertSame([], $workItem['materials']);
        self::assertSame('300.00', $workItem['labor_cost']);
    }

    #[Test]
    public function it_rejects_a_finalized_item_from_a_stale_input_version(): void
    {
        $item = $this->finalizedItem();
        $item->setAttribute('package_input_version', 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('stale');

        (new FinalizedPackageDraftProjector)->projectFromItems($this->draft(), [$item]);
    }

    #[Test]
    public function it_rejects_duplicate_finalized_mapping(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ambiguous');

        (new FinalizedPackageDraftProjector)->projectFromItems(
            $this->draft(),
            [$this->finalizedItem(), $this->finalizedItem()],
        );
    }

    #[Test]
    public function it_rejects_an_unsupported_finalized_pricing_formula(): void
    {
        $item = $this->finalizedItem();
        $snapshot = $item->price_snapshot;
        $snapshot['coefficients']['pricing_formula_version'] = 'unknown:v99';
        $this->replaceRawAttributes($item, ['price_snapshot' => $snapshot]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('unsupported');

        (new FinalizedPackageDraftProjector)->projectFromItems($this->draft(), [$item]);
    }

    #[Test]
    public function it_rejects_when_finalized_resource_evidence_does_not_equal_item_total(): void
    {
        $item = $this->finalizedItem();
        $this->replaceRawAttributes($item, [
            'direct_cost' => '401.00',
            'total_cost' => '401.00',
            'price_snapshot' => [
                ...$item->price_snapshot,
                'final_amount' => '401.00',
            ],
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('resource evidence');

        (new FinalizedPackageDraftProjector)->projectFromItems($this->draft(), [$item]);
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return [
            'source_input_version' => self::INPUT_VERSION,
            'local_estimates' => [[
                'key' => 'roof',
                'sections' => [[
                    'work_items' => [[
                        'key' => 'roof.insulation',
                        'item_type' => 'priced_work',
                        'name' => 'Утепление кровли',
                        'unit' => 'м2',
                        'quantity' => '2',
                        'materials' => [],
                        'labor' => [],
                        'machinery' => [],
                        'other_resources' => [],
                        'materials_cost' => '0.00',
                        'labor_cost' => '0.00',
                        'machinery_cost' => '0.00',
                        'total_cost' => '1.00',
                        'pricing_status' => 'calculated',
                    ]],
                ]],
            ]],
        ];
    }

    private function finalizedItem(): EstimateGenerationPackageItem
    {
        $item = new EstimateGenerationPackageItem;
        $attributes = [
            'package_key' => 'roof',
            'package_input_version' => self::INPUT_VERSION,
            'package_status' => 'ready_for_review',
            'key' => 'roof.insulation#r1',
            'logical_key' => 'roof.insulation',
            'item_type' => 'priced_work',
            'unit' => 'м2',
            'quantity' => '2.000000000000000000',
            'unit_price' => '200.000000',
            'direct_cost' => '400.00',
            'overhead_cost' => '0.00',
            'profit_cost' => '0.00',
            'total_cost' => '400.00',
            'price_source' => 'regional_catalog',
            'price_snapshot' => [
                'source_reference' => 'sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
                'final_amount' => '400.00',
                'coefficients' => [
                    'pricing_formula_version' => 'supplementary_project_material:v4',
                    'resource_evidence' => [[
                        'norm_resource_id' => 701,
                        'norm_id' => 101,
                        'resource_code' => '1-100-01',
                        'resource_type' => 'labor',
                        'norm_quantity' => '1.5',
                        'work_to_norm_factor' => '1',
                        'resource_price_id' => 901,
                        'price_unit' => 'чел.-ч',
                        'base_price' => '100',
                        'conversion_factor' => '1',
                    ]],
                    'provenance' => [
                        'schema_version' => 'pricing_provenance:v1',
                        'resources' => [[
                            'norm_resource_id' => 701,
                            'resource_code' => '1-100-01',
                            'resource_name' => 'Затраты труда рабочих',
                            'resource_type' => 'labor',
                            'price_id' => 901,
                            'regional_version' => ['version_key' => '2026-Q2'],
                        ]],
                    ],
                    'project_material_evidence' => [[
                        'project_material_rule_id' => 501,
                        'catalog_version' => 'residential_project_material:v3',
                        'work_item_key' => 'roof.insulation',
                        'scenario_assumption_code' => 'pitched_roof_mineral_wool',
                        'assumption_code' => 'mineral_wool_100mm',
                        'quantity_per_work_unit' => '1',
                        'price_factor' => '1',
                        'resource_price_id' => 902,
                        'resource_code' => '12.2.05.04-1001',
                        'resource_name' => 'Плиты минераловатные',
                        'price_unit' => 'м2',
                        'base_price' => '50',
                        'selection_policy' => 'exact_code',
                        'price_source' => 'regional_catalog',
                        'price_source_version' => '2026-Q2',
                    ]],
                ],
            ],
            'pricing_finalized_at' => '2026-07-20 10:00:00',
            'resources' => [
                'materials' => [[
                    'name' => 'Устаревшее название материала',
                    'unit' => 'м2',
                    'quantity' => '2',
                    'unit_price' => '1',
                    'total_price' => '2',
                    'project_material_selection' => [
                        'work_item_key' => 'roof.insulation',
                        'assumption_code' => 'mineral_wool_100mm',
                        'resource_code' => '12.2.05.04-1001',
                    ],
                    'normative_ref' => [
                        'resource_code' => '12.2.05.04-1001',
                        'price_id' => 1,
                    ],
                ]],
                'labor' => [[
                    'name' => 'Устаревшее название труда',
                    'unit' => 'чел.-ч',
                    'quantity' => '3',
                    'unit_price' => '1',
                    'total_price' => '3',
                    'normative_ref' => [
                        'norm_resource_id' => 701,
                        'resource_code' => '1-100-01',
                        'price_id' => 1,
                    ],
                ]],
                'machinery' => [],
                'other' => [],
            ],
            'metadata' => [
                'normative_match' => ['code' => '12-01-013-07', 'status' => 'matched'],
            ],
            'flags' => [],
        ];
        foreach (['price_snapshot', 'resources', 'metadata', 'flags'] as $jsonAttribute) {
            $attributes[$jsonAttribute] = json_encode($attributes[$jsonAttribute], JSON_THROW_ON_ERROR);
        }
        $item->setRawAttributes($attributes);

        return $item;
    }

    /** @param array<string, mixed> $replacement */
    private function replaceRawAttributes(EstimateGenerationPackageItem $item, array $replacement): void
    {
        foreach (['price_snapshot', 'resources', 'metadata', 'flags'] as $jsonAttribute) {
            if (array_key_exists($jsonAttribute, $replacement) && is_array($replacement[$jsonAttribute])) {
                $replacement[$jsonAttribute] = json_encode($replacement[$jsonAttribute], JSON_THROW_ON_ERROR);
            }
        }
        $item->setRawAttributes(array_replace($item->getAttributes(), $replacement));
    }
}
