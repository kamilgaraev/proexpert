<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemDuplicateSignature;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkItemDuplicateSignatureTest extends TestCase
{
    #[Test]
    public function quantity_formula_and_metadata_quantity_key_describe_the_same_technology_identity(): void
    {
        $fromFormula = WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'quantity_formula' => 'roof.vapor_barrier',
            'metadata' => ['quantity_key' => 'roof.vapor_barrier'],
        ]);
        $fromMetadata = WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'metadata' => ['quantity_key' => 'roof.vapor_barrier'],
        ]);

        self::assertNotNull($fromFormula);
        self::assertNotNull($fromMetadata);
        self::assertSame($fromFormula->value, $fromMetadata->value);
    }

    #[Test]
    public function optional_planner_semantic_metadata_does_not_hide_a_cross_source_duplicate(): void
    {
        $documentItem = WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'quantity_formula' => 'roof.vapor_barrier',
            'metadata' => ['quantity_key' => 'roof.vapor_barrier'],
        ]);
        $plannerItem = WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'quantity_formula' => 'roof.vapor_barrier',
            'metadata' => [
                'quantity_key' => 'roof.vapor_barrier',
                'composition_work_key' => 'roof.vapor_barrier',
                'material_scenario_work_key' => 'roof.vapor_barrier',
            ],
        ]);

        self::assertNotNull($documentItem);
        self::assertNotNull($plannerItem);
        self::assertSame($documentItem->value, $plannerItem->value);
    }

    #[Test]
    public function distinct_composition_and_material_scenario_keys_never_collapse_technological_layers(): void
    {
        $vaporBarrier = WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'quantity_formula' => 'roof.area',
            'metadata' => [
                'quantity_key' => 'roof.area',
                'composition_work_key' => 'roof.vapor_barrier',
                'material_scenario_work_key' => 'roof.vapor_barrier',
            ],
        ]);
        $membrane = WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'quantity_formula' => 'roof.area',
            'metadata' => [
                'quantity_key' => 'roof.area',
                'composition_work_key' => 'roof.membrane',
                'material_scenario_work_key' => 'roof.membrane',
            ],
        ]);

        self::assertNotNull($vaporBarrier);
        self::assertNotNull($membrane);
        self::assertNotSame($vaporBarrier->value, $membrane->value);
    }

    #[Test]
    public function incomplete_or_zero_quantity_items_have_no_duplicate_signature(): void
    {
        self::assertNull(WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'quantity' => 0,
        ]));
        self::assertNull(WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'unit' => '',
        ]));
    }

    /** @return array<string, mixed> */
    private function baseItem(): array
    {
        return [
            'name' => 'Изоляционный слой кровли',
            'normative_search_text' => 'устройство прокладочной изоляции в один слой',
            'normative_rate_code' => '12-01-015-03',
            'unit' => 'm2',
            'quantity' => 152.955,
        ];
    }
}
