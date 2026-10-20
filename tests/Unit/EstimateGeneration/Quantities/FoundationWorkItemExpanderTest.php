<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\FoundationWorkItemExpander;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FoundationWorkItemExpanderTest extends TestCase
{
    public function test_each_beam_and_slab_keeps_a_scoped_concrete_candidate_even_without_its_height_or_quantity(): void
    {
        $builder = new FoundationWorkItemExpander;
        $draft = $builder->expand($this->draft(), $this->snapshot(), 100);
        $items = $draft['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(12, $items);
        $concrete = array_values(array_filter($items, static fn (array $item): bool => $item['metadata']['composition_work_key'] === 'foundation.concrete'));
        self::assertCount(10, $concrete);
        self::assertCount(10, array_unique(array_column($concrete, 'key')));
        foreach ($concrete as $item) {
            self::assertSame('m3', $item['unit']);
            self::assertNull($item['quantity']);
            self::assertNotEmpty($item['metadata']['scope_entity_id']);
            self::assertNotSame('Бетонирование фундаментов', $item['name']);
        }
        self::assertSame($draft, $builder->expand($draft, $this->snapshot(), 100));
        self::assertSame($this->draft(), $builder->expand($this->draft(), new ProjectModelSnapshot([], [], [], []), 100));
        $other = array_values(array_filter($items, static fn (array $item): bool => $item['metadata']['composition_work_key'] !== 'foundation.concrete'));
        self::assertSame(['kg', 'm2'], array_column($other, 'unit'));
        foreach ($other as $item) {
            self::assertArrayNotHasKey('scope_entity_id', $item['metadata']);
        }
    }

    public function test_existing_scope_is_not_duplicated_and_a_limit_never_silently_truncates_entities(): void
    {
        $draft = $this->draft();
        $existing = $draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $existing['key'] = 'existing-beam';
        $existing['metadata']['scope_entity_id'] = 'beam:0';
        array_unshift($draft['local_estimates'][0]['sections'][0]['work_items'], $existing);
        $built = (new FoundationWorkItemExpander)->expand($draft, $this->snapshot(), 12);
        self::assertCount(12, $built['local_estimates'][0]['sections'][0]['work_items']);
        self::assertSame('existing-beam', $built['local_estimates'][0]['sections'][0]['work_items'][0]['key']);
        $this->expectException(InvalidArgumentException::class);
        (new FoundationWorkItemExpander)->expand($this->draft(), $this->snapshot(), 11);
    }

    private function draft(): array
    {
        $items = [];
        foreach (['foundation.concrete' => null, 'foundation.rebar' => 'kg', 'foundation.formwork' => 'm2'] as $key => $unit) {
            $items[] = ['key' => $key, 'name' => 'Бетонирование фундаментов', 'item_type' => 'priced_work', 'quantity' => null,
                'unit' => $unit, 'metadata' => ['generation_source' => 'qualitative_work_catalog', 'quantity_source' => 'unknown',
                    'composition_work_key' => $key, 'quantity_key' => $key]];
        }

        return ['local_estimates' => [['sections' => [['work_items' => $items]]]]];
    }

    private function snapshot(): ProjectModelSnapshot
    {
        $entities = $facts = [];
        for ($index = 0; $index < 10; $index++) {
            $id = $index === 9 ? 'slab:0' : 'beam:'.$index;
            $name = $index === 9 ? 'Чистые поля плиты' : 'Балка '.$index;
            $version = 'sha256:'.str_repeat('a', 64);
            $entities[] = new Entity($id, 1, 2, 3, $version, 'quantity', $id,
                ['semantic_type' => $index === 9 ? 'foundation_slab' : 'foundation_beam', 'name' => $name, 'identity' => ['manual_key' => $id]]);
            $facts[] = new Fact('name:'.$index, 1, 2, 3, $version, $id, 'entity_identity', ['name' => $name], null, 0, 'user_assumption', 'confirmed', []);
        }

        return new ProjectModelSnapshot($entities, $facts, [], []);
    }
}
