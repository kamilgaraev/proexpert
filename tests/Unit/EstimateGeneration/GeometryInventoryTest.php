<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use PHPUnit\Framework\TestCase;

final class GeometryInventoryTest extends TestCase
{
    public function test_a_current_document_anchor_retains_corrected_wall_and_opening_membership_and_exclusion_removes_them(): void
    {
        $wall = $this->entity('wall:one', 'wall');
        $opening = $this->entity('opening:one', 'opening', ['wall_id' => $wall->id]);
        $facts = array_map(static fn (Entity $entity): Fact => new Fact('fact:'.$entity->id, 1, 2, 3,
            $entity->sourceVersion, $entity->id, 'height', '2', 'm', 0.0, 'user_input', 'confirmed', ['evidence:'.$entity->id]), [$wall, $opening]);
        $snapshot = new ProjectModelSnapshot([$wall, $opening], $facts, [], []);
        $inventory = GeometryInventory::capture($snapshot, $wall->id, 'wall_openings', [
            $wall->id => ['source_version' => $wall->sourceVersion, 'evidence' => ['evidence:17']],
            $opening->id => ['source_version' => $opening->sourceVersion, 'evidence' => ['evidence:18']],
        ]);
        self::assertSame([$opening->id], $inventory['member_ids']);
        self::assertSame([], $inventory['unassigned_ids']);
        self::assertSame([], GeometryInventory::entities($snapshot, []));
    }

    public function test_current_members_are_stably_pinned_and_same_count_replacements_change_the_pin(): void
    {
        $wall = $this->entity('wall:one', 'wall');
        $opening = $this->entity('opening:one', 'opening', ['wall_id' => $wall->id]);
        $first = GeometryInventory::capture($this->snapshot([$wall, $opening]), $wall->id, 'wall_openings');
        self::assertSame([$opening->id], $first['member_ids']);
        self::assertSame([], $first['unassigned_ids']);
        self::assertFalse($first['completeness_confirmed']);
        self::assertSame($first, GeometryInventory::capture($this->snapshot([$opening, $wall]), $wall->id, 'wall_openings'));
        $other = $this->entity('opening:other', 'opening', ['wall_id' => $wall->id]);
        self::assertNotSame($first['fingerprint'], GeometryInventory::capture($this->snapshot([$wall, $other]), $wall->id, 'wall_openings')['fingerprint']);
        $newVersion = new Entity($opening->id, 1, 2, 3, 'sha256:'.str_repeat('b', 64), 'opening', $opening->stableKey, $opening->attributes);
        self::assertNotSame($first['fingerprint'], GeometryInventory::capture($this->snapshot([$wall, $newVersion]), $wall->id, 'wall_openings')['fingerprint']);
    }

    public function test_unassigned_children_are_visible_and_a_user_coverage_fact_cannot_reactivate_a_stale_document(): void
    {
        $wall = $this->entity('wall:one', 'wall');
        $opening = $this->entity('opening:one', 'opening');
        $inventory = GeometryInventory::capture($this->snapshot([$wall, $opening]), $wall->id, 'wall_openings');
        self::assertSame([], $inventory['member_ids']);
        self::assertSame([$opening->id], $inventory['unassigned_ids']);
        $coverage = new Fact('fact:coverage', 1, 2, 3, $wall->sourceVersion, $wall->id,
            'geometry_coverage_wall_openings', ['relation' => 'wall_openings', 'status' => 'covered_empty'], null, 0.0, 'user_input', 'confirmed', ['evidence:coverage']);
        self::assertSame([], GeometryInventory::entities(new ProjectModelSnapshot([$wall], [$coverage], [], [])));
        $this->expectExceptionMessage('geometry_inventory_target_unavailable');
        GeometryInventory::capture($this->snapshot([$opening]), $opening->id, 'wall_openings');
    }

    private function entity(string $id, string $type, array $attributes = []): Entity
    {
        return new Entity($id, 1, 2, 3, 'sha256:'.str_repeat('a', 64), $type, $id, ['semantic_type' => $type, ...$attributes]);
    }

    private function snapshot(array $entities): ProjectModelSnapshot
    {
        return new ProjectModelSnapshot($entities, array_map(static fn (Entity $entity): Fact => new Fact('fact:'.$entity->id,
            1, 2, 3, $entity->sourceVersion, $entity->id, 'length', '2', 'm', 0.0, 'document', 'confirmed', ['evidence:'.$entity->id]), $entities), [], []);
    }
}
