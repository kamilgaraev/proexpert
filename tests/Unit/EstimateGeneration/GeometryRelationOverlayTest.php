<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryRelationOverlay;
use PHPUnit\Framework\TestCase;

final class GeometryRelationOverlayTest extends TestCase
{
    public function test_confirmed_cross_source_parent_choice_changes_only_the_view_and_inventory_pin(): void
    {
        foreach (GeometryInventory::RELATIONS as $relation => $definition) {
            [$snapshot, $decision, $anchors] = $this->fixture($relation, $definition);
            $old = GeometryInventory::capture($snapshot, 'entity:parent', $relation, $anchors);
            $view = GeometryRelationOverlay::apply($snapshot, [$decision], $anchors);
            self::assertArrayNotHasKey($definition[2], $snapshot->entities[1]->attributes);
            self::assertSame('entity:parent', $view->entities[1]->attributes[$definition[2]]);
            self::assertSame('confirmed', $view->entities[1]->attributes['geometry_relation_reviews'][$relation]['status']);
            $current = GeometryInventory::capture($view, 'entity:parent', $relation, $anchors);
            self::assertSame(['entity:child'], $current['member_ids']);
            self::assertSame([], $current['unassigned_ids']);
            self::assertNotSame($old['fingerprint'], $current['fingerprint']);
            self::assertSame($snapshot->facts, $view->facts);
        }
    }

    public function test_missing_decision_changed_parent_or_unavailable_document_cannot_authorize_a_link(): void
    {
        [$snapshot, $decision, $anchors] = $this->fixture('wall_openings', GeometryInventory::RELATIONS['wall_openings']);
        foreach ([[], [$decision, $decision]] as $decisions) {
            $view = GeometryRelationOverlay::apply($snapshot, $decisions, $anchors);
            self::assertArrayNotHasKey('wall_id', $view->entities[1]->attributes);
            self::assertSame('unresolved', $view->entities[1]->attributes['geometry_relation_reviews']['wall_openings']['status']);
        }
        $view = GeometryRelationOverlay::apply($snapshot, [$decision], []);
        self::assertArrayNotHasKey('wall_id', $view->entities[1]->attributes);
        $otherVersion = new Entity('entity:parent', 1, 2, 3, 'sha256:'.str_repeat('c', 64), 'wall', 'entity:parent');
        $changed = new ProjectModelSnapshot([$otherVersion, $snapshot->entities[1]], $snapshot->facts, $snapshot->evidence, []);
        $anchors['entity:parent']['source_version'] = $otherVersion->sourceVersion;
        self::assertArrayNotHasKey('wall_id', GeometryRelationOverlay::apply($changed, [$decision], $anchors)->entities[1]->attributes);
    }

    private function fixture(string $relation, array $definition): array
    {
        $parent = new Entity('entity:parent', 1, 2, 3, 'sha256:'.str_repeat('a', 64),
            in_array($definition[0], Entity::TYPES, true) ? $definition[0] : 'quantity', 'entity:parent', ['semantic_type' => $definition[0]]);
        $child = new Entity('entity:child', 1, 2, 3, 'sha256:'.str_repeat('b', 64),
            in_array($definition[1], Entity::TYPES, true) ? $definition[1] : 'quantity', 'entity:child', ['semantic_type' => $definition[1]]);
        $value = ['relation' => $relation, 'parent_entity_id' => $parent->id, 'parent_source_version' => $parent->sourceVersion,
            'decision_id' => 'decision:link', 'reason' => 'Проверена принадлежность по документам'];
        $hash = hash('sha256', CanonicalPipelineJson::encode($value));
        $proof = new Evidence('evidence:link', 1, 2, 3, $child->sourceVersion, 'input:3', 'user_input', null, null,
            'input_payload.manual_facts', EvidenceProducer::UserInputNormalizer->value, 'input:3', $hash);
        $fact = new Fact('fact:link', 1, 2, 3, $child->sourceVersion, $child->id, 'geometry_parent_'.$relation,
            $value, null, 0.0, 'user_input', 'confirmed', [$proof->id]);
        $decision = new Decision('decision:link', 1, 2, 3, $child->sourceVersion, 'fact', $fact->id, $fact->id, 'user', '1', $value['reason'], 1, [$proof->id]);
        $anchors = [$parent->id => ['source_version' => $parent->sourceVersion], $child->id => ['source_version' => $child->sourceVersion]];

        return [new ProjectModelSnapshot([$parent, $child], [$fact], [$proof], []), $decision, $anchors];
    }
}
