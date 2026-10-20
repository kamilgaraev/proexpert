<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CanonicalWorkItemQuantityBinder;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WallWorkItemExpander;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WallWorkItemExpanderTest extends TestCase
{
    public function test_roles_and_explicit_surfaces_use_each_walls_exact_area_or_volume_without_double_masonry(): void
    {
        $built = (new WallWorkItemExpander(fn ($key) => $key))->expand($this->draft(), $this->snapshot(), $this->scopes(), $this->quantities(), 10, []);
        $items = $built['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(10, $items);
        $byWork = [];
        foreach ($items as $item) {
            $byWork[$item['metadata']['composition_work_key'] ?? 'unrelated'][] = $item;
            if (($item['metadata']['generation_source'] ?? null) !== 'canonical_wall_assembly') {
                continue;
            }
            $bound = (new CanonicalWorkItemQuantityBinder)->bind($item, $this->quantities());
            self::assertNotEmpty($bound['quantity_evidence']);
            self::assertNull($bound['unit_price']);
            self::assertSame([], $bound['materials']);
        }
        self::assertCount(1, $byWork['walls.external_volume']);
        self::assertCount(1, $byWork['walls.internal']);
        self::assertCount(3, $byWork['rough.walls']);
        self::assertCount(3, $byWork['finish.paint']);
        self::assertCount(1, $byWork['facade.area']);
        self::assertSame('wall:external', $byWork['facade.area'][0]['entity_id']);
        self::assertSame('outside', $byWork['facade.area'][0]['metadata']['wall_surface']);
        self::assertSame($built, (new WallWorkItemExpander(fn ($key) => $key))->expand($built, $this->snapshot(), $this->scopes(), $this->quantities(), 10, []));
    }

    public function test_partition_finish_is_never_implicitly_doubled_and_unselected_side_is_not_permitted(): void
    {
        $scopes = $this->scopes();
        $scopes['wall:partition']['finished_surfaces'] = ['side_a'];
        $built = (new WallWorkItemExpander(fn ($key) => $key))->expand($this->draft(), $this->snapshot(), $scopes, $this->quantities(), 10, []);
        $items = $built['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(8, $items);
        foreach ($items as $item) {
            self::assertNotSame('side_b', $item['metadata']['wall_surface'] ?? null);
            self::assertTrue(WallWorkItemExpander::permits($item, $scopes));
        }
        $changed = $scopes;
        $changed['wall:external']['fact_id'] = 'fact:replacement';
        self::assertFalse(WallWorkItemExpander::permits($items[0], $changed));
    }

    public function test_partial_scope_keeps_a_placeholder_and_does_not_restore_a_deleted_generated_row(): void
    {
        $expander = new WallWorkItemExpander(fn ($key) => $key);
        $scopes = ['wall:external' => $this->scopes()['wall:external']];
        $built = $expander->expand($this->draft(), $this->snapshot(), $scopes, $this->quantities(), 20, []);
        $items = &$built['local_estimates'][0]['sections'][0]['work_items'];
        $generated = array_values(array_filter($items, static fn ($item): bool => str_starts_with($item['key'], 'wall-work:')));
        self::assertCount(4, $generated);
        $deleted = $generated[0]['key'];
        $items = array_values(array_filter($items, static fn ($item): bool => $item['key'] !== $deleted));
        $again = $expander->expand($built, $this->snapshot(), $scopes, $this->quantities(), 20, []);
        self::assertSame($built['local_estimates'][0]['sections'][0]['work_items'], $again['local_estimates'][0]['sections'][0]['work_items']);
        self::assertSame($again, $expander->expand($again, $this->snapshot(), $scopes, $this->quantities(), 20, []));
        self::assertNotContains($deleted, array_column($again['local_estimates'][0]['sections'][0]['work_items'], 'key'));
    }

    public function test_unbound_wall_templates_cannot_borrow_building_floor_or_gross_wall_quantities(): void
    {
        $quantities = $this->quantities();
        foreach (['floor_area', 'gross_wall_area', 'net_wall_area', 'net_wall_volume'] as $key) {
            $quantities[$key] = new QuantityData($key, $key === 'net_wall_volume' ? 'm3' : 'm2', '40', 'floor_area', '1', [], QuantitySource::Evidenced, [], 'model');
        }
        foreach (array_slice($this->draft()['local_estimates'][0]['sections'][0]['work_items'], 0, 5) as $item) {
            $bound = (new CanonicalWorkItemQuantityBinder)->bind($item, $quantities, generationIdentity: ['evaluation_mode' => 'universal']);
            self::assertArrayNotHasKey('quantity', $bound);
            self::assertArrayNotHasKey('quantity_evidence', $bound);
        }
    }

    public function test_expansion_obeys_the_work_limit_instead_of_truncating_walls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new WallWorkItemExpander(fn ($key) => $key))->expand($this->draft(), $this->snapshot(), $this->scopes(), $this->quantities(), 9, []);
    }

    public function test_completed_templates_rebind_changed_choices_and_include_a_new_wall(): void
    {
        $expander = new WallWorkItemExpander(fn ($key) => $key);
        $scopes = $this->scopes();
        $built = $expander->expand($this->draft(), $this->snapshot(), $scopes, $this->quantities(), 20, []);
        self::assertCount(5, $built['local_estimates'][0]['sections'][0]['wall_work_templates']);
        $built['local_estimates'][0]['sections'][0]['work_items'][0]['normative_match'] = ['norm_id' => 123, 'selected_by_user' => true];
        $built['local_estimates'][0]['sections'][0]['work_items'][0]['materials'] = [['quantity' => '999']];
        $scopes['wall:external']['fact_id'] = 'fact:replacement';
        $rebound = $expander->expand($built, $this->snapshot(), $scopes, $this->quantities(), 20, []);
        $first = $rebound['local_estimates'][0]['sections'][0]['work_items'][0];
        self::assertSame('fact:replacement', $first['metadata']['wall_assembly']['fact_id']);
        self::assertArrayNotHasKey('normative_match', $first);
        self::assertSame([], $first['materials']);
        $scopes['wall:external'] = ['role' => 'partition', 'finished_surfaces' => ['side_a'], 'fact_id' => 'fact:partition-now'];
        $changed = $expander->expand($rebound, $this->snapshot(), $scopes, $this->quantities(), 20, []);
        $items = $changed['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(9, $items);
        foreach ($items as $item) {
            self::assertTrue(WallWorkItemExpander::permits($item, $scopes));
            self::assertNotContains($item['metadata']['composition_work_key'] ?? null, ['walls.external_volume', 'facade.area']);
        }
        $snapshot = $this->snapshot(['external', 'partition', 'new']);
        $partial = $expander->expand($changed, $snapshot, $scopes, $this->quantities(), 20, []);
        self::assertCount(14, $partial['local_estimates'][0]['sections'][0]['work_items']);
        $scopes['wall:new'] = ['role' => 'external', 'finished_surfaces' => ['inside'], 'fact_id' => 'fact:new'];
        $quantities = $this->quantities();
        $quantities['new-area'] = new QuantityData('new-area', 'm2', '7', 'wall_net_area', '1', ['entity_id' => 'wall:new'], QuantitySource::Evidenced, [], 'model');
        $quantities['new-volume'] = new QuantityData('new-volume', 'm3', '0.98', 'wall_net_volume', '1', ['entity_id' => 'wall:new'], QuantitySource::Evidenced, [], 'model');
        $complete = $expander->expand($partial, $snapshot, $scopes, $quantities, 20, []);
        $items = $complete['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(12, $items);
        $new = array_values(array_filter($items, static fn ($item): bool => ($item['entity_id'] ?? null) === 'wall:new'));
        self::assertCount(3, $new);
        foreach ($new as $row) {
            self::assertSame($row['unit'] === 'm3' ? 'new-volume' : 'new-area', $row['metadata']['quantity_key']);
        }
        self::assertSame($complete, $expander->expand($complete, $snapshot, $scopes, $quantities, 20, []));
    }

    public function test_a_deleted_completed_row_stays_deleted_but_retired_surfaces_can_return(): void
    {
        $expander = new WallWorkItemExpander(fn ($key) => $key);
        $scopes = $this->scopes();
        $built = $expander->expand($this->draft(), $this->snapshot(), $scopes, $this->quantities(), 20, []);
        $items = &$built['local_estimates'][0]['sections'][0]['work_items'];
        $deleted = array_values(array_filter($items, static fn ($item): bool => ($item['metadata']['composition_work_key'] ?? null) === 'facade.area'))[0]['key'];
        $body = $items[0]['key'];
        $items = array_values(array_filter($items, static fn ($item): bool => $item['key'] !== $deleted));
        $built = $expander->expand($built, $this->snapshot(), $scopes, $this->quantities(), 20, []);
        $scopes['wall:external'] = ['role' => 'partition', 'finished_surfaces' => ['side_a'], 'fact_id' => 'fact:changed'];
        $changed = $expander->expand($built, $this->snapshot(), $scopes, $this->quantities(), 20, []);
        self::assertNotContains($body, array_column($changed['local_estimates'][0]['sections'][0]['work_items'], 'key'));
        $scopes = $this->scopes();
        $returned = $expander->expand($changed, $this->snapshot(), $scopes, $this->quantities(), 20, []);
        $keys = array_column($returned['local_estimates'][0]['sections'][0]['work_items'], 'key');
        self::assertContains($body, $keys);
        self::assertNotContains($deleted, $keys);
        $unassigned = $expander->expand($returned, $this->snapshot(), [], $this->quantities(), 20, []);
        self::assertCount(6, $unassigned['local_estimates'][0]['sections'][0]['work_items']);
        self::assertSame($unassigned, $expander->expand($unassigned, $this->snapshot(), [], $this->quantities(), 20, []));
    }

    public function test_deleting_an_incomplete_placeholder_stops_new_rows_for_that_template(): void
    {
        $expander = new WallWorkItemExpander(fn ($key) => $key);
        $scopes = ['wall:external' => $this->scopes()['wall:external']];
        $built = $expander->expand($this->draft(), $this->snapshot(), $scopes, $this->quantities(), 20, []);
        $items = &$built['local_estimates'][0]['sections'][0]['work_items'];
        $items = array_values(array_filter($items, static fn ($item): bool => $item['key'] !== 'rough.walls'));
        $partial = $expander->expand($built, $this->snapshot(), $scopes, $this->quantities(), 20, []);
        self::assertNotContains('rough.walls', array_column($partial['local_estimates'][0]['sections'][0]['work_items'], 'key'));
        $complete = $expander->expand($partial, $this->snapshot(), $this->scopes(), $this->quantities(), 20, []);
        $rows = array_values(array_filter($complete['local_estimates'][0]['sections'][0]['work_items'],
            static fn ($item): bool => ($item['metadata']['composition_work_key'] ?? null) === 'rough.walls'));
        self::assertCount(1, $rows);
        self::assertSame('wall:external', $rows[0]['entity_id']);
    }

    private function snapshot(array $names = ['external', 'partition']): ProjectModelSnapshot
    {
        $entities = array_map(static fn ($name): Entity => new Entity('wall:'.$name, 1, 2, 3, 'sha256:'.str_repeat('a', 64),
            'wall', 'wall:'.$name, ['identity' => ['manual_key' => $name]]), $names);

        return new ProjectModelSnapshot($entities, array_map(static fn ($wall): Fact => new Fact('fact:'.$wall->id, 1, 2, 3,
            $wall->sourceVersion, $wall->id, 'entity_identity', ['name' => $wall->id], null, 0, 'user_assumption', 'confirmed', []), $entities), [], []);
    }

    private function scopes(): array
    {
        return ['wall:external' => ['role' => 'external', 'finished_surfaces' => ['inside', 'outside'], 'fact_id' => 'fact:external'],
            'wall:partition' => ['role' => 'partition', 'finished_surfaces' => ['side_a', 'side_b'], 'fact_id' => 'fact:partition']];
    }

    private function quantities(): array
    {
        $result = [];
        foreach (['external' => ['10', '1.4'], 'partition' => ['5', '0.7']] as $name => [$area, $volume]) {
            foreach (['wall_net_area' => [$area, 'm2'], 'wall_net_volume' => [$volume, 'm3']] as $formula => [$amount, $unit]) {
                $key = 'quantity:'.$formula.':wall:'.$name;
                $result[$key] = new QuantityData($key, $unit, $amount, $formula, '1', ['entity_id' => 'wall:'.$name], QuantitySource::Evidenced, [], 'model');
            }
        }

        return $result;
    }

    private function draft(): array
    {
        $items = [];
        foreach (WallWorkItemExpander::WORKS as $work) {
            $items[] = ['key' => $work, 'name' => $work, 'item_type' => 'priced_work', 'quantity' => '120',
                'unit' => 'm2', 'metadata' => ['generation_source' => 'qualitative_work_catalog', 'quantity_source' => 'unknown',
                    'quantity_key' => $work, 'composition_work_key' => $work]];
        }
        $items[] = ['key' => 'unrelated', 'name' => 'Keep the selected norm and note', 'unit_price' => '77', 'normative_rate_code' => 'selected'];

        return ['local_estimates' => [['sections' => [['work_items' => $items]]]]];
    }
}
