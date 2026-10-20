<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OpeningWorkItemExpanderTest extends TestCase
{
    public function test_previously_accepted_manual_aggregate_is_preserved_and_keeps_its_direct_quantity(): void
    {
        $draft = $this->draft();
        $row = &$draft['local_estimates'][0]['sections'][0]['work_items'][0];
        $row['quantity'] = '5';
        $row['unit'] = 'm2';
        $row['metadata']['quantity_key'] = 'accepted:aggregate';
        $row['normative_match'] = ['norm_id' => 123, 'selected_by_user' => true];
        $row['materials'] = [['construction_resource_id' => 456, 'quantity' => '5']];
        $expected = $row;
        $quantity = new QuantityData('accepted:aggregate', 'm2', '5', 'direct_quantity', '1', ['entity_id' => 'manual:aggregate'],
            QuantitySource::Evidenced, [], 'model');
        $expanded = (new OpeningWorkItemExpander)->expand($draft, $this->snapshot(), $this->scopes(), $this->quantities(), 10, []);
        self::assertSame($expected, $expanded['local_estimates'][0]['sections'][0]['work_items'][0]);
        self::assertTrue(OpeningWorkItemExpander::permits($expected, []));
        self::assertSame($quantity, (new WorkItemQuantityResolver)->resolve($expected, [$quantity->key => $quantity], ['evaluation_mode' => 'universal']));
    }

    public function test_exact_areas_remain_per_entity_and_cannot_cross_door_window_or_floor_aliases(): void
    {
        $mapper = new WorkItemQuantityMapper;
        self::assertSame('1.652016', $mapper->mapForEntity('openings.doors', $this->quantities(), 'opening:door')?->amount);
        self::assertSame('0.969878', $mapper->mapForEntity('openings.windows', $this->quantities(), 'opening:window')?->amount);
        self::assertNull($mapper->mapForEntity('openings.doors', $this->quantities(), 'opening:window'));
        self::assertNull($mapper->mapForEntity('openings.windows', $this->quantities(), 'opening:door'));
        self::assertNull($mapper->map('openings.windows', ['floor_area' => new QuantityData('floor_area', 'm2', '40', 'floor_area', '1', [], QuantitySource::Evidenced, [], 'model')]));
        $built = (new OpeningWorkItemExpander)->expand($this->draft(), $this->snapshot(), $this->scopes(), $this->quantities(), 10, []);
        $items = $built['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(3, $items);
        foreach (array_slice($items, 0, 2) as $item) {
            self::assertTrue(OpeningWorkItemExpander::permits($item, $this->scopes()));
            self::assertSame('m2', $item['unit']);
            self::assertNotContains('unit_requires_confirmation', $item['validation_flags']);
            self::assertNull($item['quantity']);
            self::assertNotNull((new WorkItemQuantityResolver)->resolve($item, $this->quantities(), ['evaluation_mode' => 'universal']));
            $forged = $item;
            $forged['metadata']['opening_scope']['fact_id'] = 'fact:wrong';
            self::assertNull((new WorkItemQuantityResolver)->resolve($forged, $this->quantities(), ['evaluation_mode' => 'universal']));
        }
    }

    public function test_existing_norms_materials_manual_quantities_and_deleted_rows_are_preserved_even_when_scope_changes(): void
    {
        $expander = new OpeningWorkItemExpander;
        $built = $expander->expand($this->draft(), $this->snapshot(), $this->scopes(), $this->quantities(), 10, []);
        $items = &$built['local_estimates'][0]['sections'][0]['work_items'];
        $items[0]['name'] = 'My selected product';
        $items[0]['quantity'] = '12';
        $items[0]['normative_match'] = ['norm_id' => 123, 'selected_by_user' => true];
        $items[0]['materials'] = [['construction_resource_id' => 456, 'quantity' => '12']];
        $items[0]['unit_price'] = '99';
        $previous = $items[0];
        $scopes = $this->scopes();
        $scopes[$items[0]['entity_id']]['fact_id'] = 'fact:replacement';
        $changed = $expander->expand($built, $this->snapshot(), $scopes, $this->quantities(), 10, []);
        self::assertSame($previous, $changed['local_estimates'][0]['sections'][0]['work_items'][0]);
        self::assertFalse(OpeningWorkItemExpander::permits($previous, $scopes));
        $deleted = $items[1]['key'];
        array_splice($items, 1, 1);
        $again = $expander->expand($built, $this->snapshot(), $this->scopes(), $this->quantities(), 10, []);
        self::assertNotContains($deleted, array_column($again['local_estimates'][0]['sections'][0]['work_items'], 'key'));
        self::assertSame($again, $expander->expand($again, $this->snapshot(), $this->scopes(), $this->quantities(), 10, []));
        $manual = $this->draft();
        $manual['local_estimates'][0]['sections'][0]['work_items'][0]['normative_match'] = ['norm_id' => 789, 'selected_by_user' => true];
        $expanded = $expander->expand($manual, $this->snapshot(), $this->scopes(), $this->quantities(), 10, []);
        self::assertSame($manual['local_estimates'][0]['sections'][0]['work_items'][0], $expanded['local_estimates'][0]['sections'][0]['work_items'][0]);
    }

    public function test_unknown_kind_retains_a_placeholder_and_expansion_never_truncates_to_fit_limit(): void
    {
        $partial = ['opening:door' => $this->scopes()['opening:door']];
        $built = (new OpeningWorkItemExpander)->expand($this->draft(), $this->snapshot(), $partial, $this->quantities(), 10, []);
        self::assertCount(4, $built['local_estimates'][0]['sections'][0]['work_items']);
        $this->expectException(InvalidArgumentException::class);
        (new OpeningWorkItemExpander)->expand($this->draft(), $this->snapshot(), $partial, $this->quantities(), 3, []);
    }

    private function snapshot(): ProjectModelSnapshot
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $wall = new Entity('wall', 1, 2, 3, $version, 'wall', 'wall', ['identity' => ['manual_key' => 'wall']]);
        $entities = [$wall];
        foreach (['door', 'window'] as $kind) {
            $entities[] = new Entity('opening:'.$kind, 1, 2, 3, $version, 'opening', 'opening:'.$kind,
                ['wall_id' => $wall->id, 'identity' => ['manual_key' => $kind]]);
        }

        return new ProjectModelSnapshot($entities, array_map(static fn ($entity): Fact => new Fact('fact:'.$entity->id,
            1, 2, 3, $version, $entity->id, 'entity_identity', ['name' => $entity->id], null, 0.0, 'user_assumption', 'confirmed', []), $entities), [], []);
    }

    private function scopes(): array
    {
        return ['opening:door' => ['opening_kind' => 'door', 'fact_id' => 'fact:door'],
            'opening:window' => ['opening_kind' => 'window', 'fact_id' => 'fact:window']];
    }

    private function quantities(): array
    {
        $result = [];
        foreach (['door' => '1.652016', 'window' => '0.969878'] as $kind => $amount) {
            $key = 'quantity:opening:'.$kind;
            $result[$key] = new QuantityData($key, 'm2', $amount, 'opening_area', '1', ['entity_id' => 'opening:'.$kind,
                'opening_scope' => $this->scopes()['opening:'.$kind]], QuantitySource::Evidenced, [], 'model');
        }

        return $result;
    }

    private function draft(): array
    {
        $items = array_map(static fn ($work): array => ['key' => $work, 'name' => $work, 'item_type' => 'priced_work',
            'quantity' => null, 'unit' => null, 'validation_flags' => ['unit_requires_confirmation'],
            'metadata' => ['generation_source' => 'qualitative_work_catalog', 'quantity_source' => 'unknown',
                'quantity_key' => $work, 'composition_work_key' => $work]], OpeningWorkItemExpander::WORKS);
        $items[] = ['key' => 'note', 'name' => 'Preserve me', 'item_type' => 'review_note'];

        return ['local_estimates' => [['sections' => [['work_items' => $items]]]]];
    }
}
