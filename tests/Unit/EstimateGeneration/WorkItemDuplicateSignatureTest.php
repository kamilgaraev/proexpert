<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemDuplicateSignature;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkItemDuplicateSignatureTest extends TestCase
{
    #[Test]
    public function quantity_sources_do_not_change_the_same_explicit_physical_identity(): void
    {
        $fromFormula = WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'quantity_formula' => 'roof.vapor_barrier',
            'metadata' => [...$this->baseItem()['metadata'], 'quantity_key' => 'roof.vapor_barrier'],
        ]);
        $fromMetadata = WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'metadata' => [...$this->baseItem()['metadata'], 'quantity_key' => 'roof.vapor_barrier'],
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
            'metadata' => [...$this->baseItem()['metadata'], 'quantity_key' => 'roof.vapor_barrier'],
        ]);
        $plannerItem = WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'quantity_formula' => 'roof.vapor_barrier',
            'metadata' => [
                ...$this->baseItem()['metadata'],
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
                ...$this->baseItem()['metadata'],
                'quantity_key' => 'roof.area',
                'composition_work_key' => 'roof.vapor_barrier',
                'material_scenario_work_key' => 'roof.vapor_barrier',
            ],
        ]);
        $membrane = WorkItemDuplicateSignature::fromWorkItem([
            ...$this->baseItem(),
            'quantity_formula' => 'roof.area',
            'metadata' => [
                ...$this->baseItem()['metadata'],
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

    #[Test]
    public function different_or_unproven_physical_identities_have_no_shared_signature(): void
    {
        $base = $this->baseItem();
        $signature = WorkItemDuplicateSignature::fromWorkItem($base);
        self::assertNotNull($signature);
        foreach ([
            'other entity' => ['scope_entity_id' => 'entity:other'],
            'entity case' => ['scope_entity_id' => 'ENTITY:roof'],
            'other work' => ['composition_work_key' => 'roof.membrane'],
            'work case' => ['composition_work_key' => 'ROOF.vapor_barrier'],
            'other package' => ['technology_package_id' => 17],
            'other formula' => ['technology_formula_id' => 'formula:other'],
            'missing entity' => ['scope_entity_id' => null],
            'invalid entity' => ['scope_entity_id' => ['id' => 'entity:roof']],
            'quantity basis only' => ['composition_work_key' => null, 'quantity_key' => 'roof.vapor_barrier'],
            'conflicting work keys' => ['material_scenario_work_key' => 'roof.membrane'],
            'invalid package' => ['technology_package_id' => ['id' => 17]],
            'invalid formula' => ['technology_formula_id' => 1.5],
        ] as $case => $overrides) {
            $other = WorkItemDuplicateSignature::fromWorkItem([...$base, 'metadata' => [...$base['metadata'], ...$overrides]]);
            self::assertNotSame($signature->value, $other?->value, $case);
        }
        foreach (['technology_package_id', 'technology_formula_id'] as $field) {
            $integer = WorkItemDuplicateSignature::fromWorkItem([...$base, 'metadata' => [...$base['metadata'], $field => 17]]);
            $string = WorkItemDuplicateSignature::fromWorkItem([...$base, 'metadata' => [...$base['metadata'], $field => '17']]);
            self::assertNotNull($integer);
            self::assertNotNull($string);
            self::assertNotSame($integer->value, $string->value);
        }
    }

    #[Test]
    public function exact_decimal_quantities_are_compared_without_rounding(): void
    {
        $signature = WorkItemDuplicateSignature::fromWorkItem([...$this->baseItem(), 'quantity' => '152.955000000000000001']);
        $other = WorkItemDuplicateSignature::fromWorkItem([...$this->baseItem(), 'quantity' => '152.955000000000000002']);
        $same = WorkItemDuplicateSignature::fromWorkItem([...$this->baseItem(), 'quantity' => '152.9550000000000000010']);
        self::assertNotNull($signature);
        self::assertNotNull($other);
        self::assertNotNull($same);
        self::assertNotSame($signature->value, $other->value);
        self::assertSame($signature->value, $same->value);
    }

    #[Test]
    public function distinct_finite_float_quantities_keep_their_serialized_precision(): void
    {
        $first = WorkItemDuplicateSignature::fromWorkItem([...$this->baseItem(), 'quantity' => 152.955]);
        $second = WorkItemDuplicateSignature::fromWorkItem([...$this->baseItem(), 'quantity' => 152.95500000000004]);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->value, $second->value);
    }

    #[Test]
    public function delimiter_characters_cannot_make_distinct_tuple_fields_collide(): void
    {
        $base = $this->baseItem();
        $first = WorkItemDuplicateSignature::fromWorkItem([...$base, 'normative_rate_code' => 'norm|roof',
            'metadata' => [...$base['metadata'], 'composition_work_key' => 'vapor']]);
        $second = WorkItemDuplicateSignature::fromWorkItem([...$base, 'normative_rate_code' => 'norm',
            'metadata' => [...$base['metadata'], 'composition_work_key' => 'roof|vapor']]);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->value, $second->value);
    }

    #[Test]
    public function invalid_or_unbounded_quantities_have_no_signature(): void
    {
        foreach ([null, false, [], NAN, INF, -1, '0', '-0.01', '1e1000000000', str_repeat('9', 129)] as $quantity) {
            self::assertNull(WorkItemDuplicateSignature::fromWorkItem([...$this->baseItem(), 'quantity' => $quantity]));
        }
    }

    #[Test]
    public function explicit_identity_is_trimmed_and_group_requires_two_distinct_keys(): void
    {
        $base = $this->baseItem();
        $other = [...$base, 'metadata' => ['scope_entity_id' => ' entity:roof ', 'material_scenario_work_key' => ' roof.vapor_barrier ']];
        self::assertSame(WorkItemDuplicateSignature::fromWorkItem($base)?->value, WorkItemDuplicateSignature::fromWorkItem($other)?->value);
        $items = [['key' => 'one', ...$base], ['key' => 'two', ...$other]];
        self::assertTrue(\App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemPhysicalIdentity::canResolveGroup($items));
        self::assertFalse(\App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemPhysicalIdentity::canResolveGroup([$items[0]]));
        self::assertFalse(\App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemPhysicalIdentity::canResolveGroup([$items[0], $items[0]]));
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
            'metadata' => ['scope_entity_id' => 'entity:roof', 'composition_work_key' => 'roof.vapor_barrier'],
        ];
    }
}
