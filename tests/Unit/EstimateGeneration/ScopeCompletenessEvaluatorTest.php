<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use PHPUnit\Framework\TestCase;

final class ScopeCompletenessEvaluatorTest extends TestCase
{
    public function test_water_test_program_cannot_close_electricity_or_another_water_entity(): void
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $entities = array_map(static fn (string $id): Entity => new Entity($id, 1, 2, 3, $version, 'equipment', $id), ['water:1', 'water:2', 'electricity:1']);
        $evidence = new Evidence('evidence:1', 1, 2, 3, $version, 'document:1', 'document', 1);
        $fact = new Fact('fact:1', 1, 2, 3, $version, 'water:1', 'water_supply_test_program', true, null, 0, 'document', 'confirmed', [$evidence->id]);
        $userEvidence = new Evidence('evidence:user', 1, 2, 3, $version, 'manual:1', 'user_input', nativeReference: 'input:program_review');
        $decision = new Decision('decision:1', 1, 2, 3, $version, 'fact', 'fact:approval', 'fact:approval', 'user', '42', 'Проверена программа испытаний водопровода', 1, [$userEvidence->id]);
        $approval = new Fact('fact:approval', 1, 2, 3, $version, 'water:1', 'evaluation_requirement.water_supply_test_program',
            ['disposition' => 'document_verified', 'scope_version' => ScopeCompletenessEvaluator::VERSION, 'technology_key' => 'unspecified',
                'document_fact_id' => $fact->id, 'document_source_version' => $version, 'document_source_ref' => $evidence->sourceArtifactId,
                'decision_id' => $decision->id, 'reason' => $decision->reason], null, 0, 'user_input', 'confirmed', [$userEvidence->id]);
        $positions = [];
        foreach (['water_supply' => ['water:1', 'water:2'], 'electricity' => ['electricity:1']] as $section => $ids) {
            foreach ($ids as $id) {
                foreach (['installation', $section.'_testing'] as $work) {
                    $positions[] = ['key' => $id.':'.$work, 'section' => $section, 'entity_id' => $id, 'work_key' => $work];
                }
            }
        }
        $snapshot = new ProjectModelSnapshot($entities, [$fact, $approval], [$evidence, $userEvidence], []);
        $scope = ['water_supply' => ['water:1', 'water:2'], 'electricity' => ['electricity:1']];
        $unreviewed = (new ScopeCompletenessEvaluator)->evaluate($snapshot, 'construction', 'new_construction', $scope, $positions);
        self::assertCount(3, $unreviewed['missing_requirements']);
        $result = (new ScopeCompletenessEvaluator)->evaluate($snapshot, 'construction', 'new_construction', $scope, $positions, [$decision]);
        self::assertFalse($result['scope_complete']);
        self::assertSame(['water:2', 'electricity:1'], array_column($result['missing_requirements'], 'entity_id'));
        self::assertSame(['water_supply_test_program', 'electricity_test_program'], array_column($result['missing_requirements'], 'requirement'));
    }

    public function test_roof_covering_does_not_mean_the_whole_roof_scope_is_complete(): void
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $entity = new Entity('roof:1', 1, 2, 3, $version, 'quantity', 'roof:1', ['semantic_type' => 'roof']);
        $result = (new ScopeCompletenessEvaluator)->evaluate(new ProjectModelSnapshot([$entity], [], [], []), 'construction', 'new_construction', ['roofing' => [$entity->id]],
            [['key' => 'roof_cover', 'section' => 'roofing', 'entity_id' => $entity->id, 'work_key' => 'roof_covering']]);
        self::assertFalse($result['scope_complete']);
        self::assertSame(['preparation', 'roof_substrate', 'roof_drainage'], array_column($result['missing_requirements'], 'requirement'));
    }

    public function test_repair_exclusion_requires_a_user_decision_for_the_same_entity_requirement_and_technology(): void
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $entity = new Entity('room:1', 1, 2, 3, $version, 'room', 'room:1');
        $evidence = new Evidence('evidence:1', 1, 2, 3, $version, 'manual:1', 'user_input', nativeReference: 'input:demolition');
        $decision = new Decision('decision:1', 1, 2, 3, $version, 'fact', 'fact:1', 'fact:1', 'user', '42', 'Покрытие сохранено, демонтаж не требуется', 1, [$evidence->id]);
        $fact = new Fact('fact:1', 1, 2, 3, $version, $entity->id, 'evaluation_requirement.demolition',
            ['disposition' => 'not_applicable', 'scope_version' => ScopeCompletenessEvaluator::VERSION, 'technology_key' => 'paint:v1',
                'decision_id' => $decision->id, 'reason' => $decision->reason], null, 0, 'user_input', 'confirmed', [$evidence->id]);
        $snapshot = new ProjectModelSnapshot([$entity], [$fact], [$evidence], []);
        $positions = array_map(static fn (string $work): array => ['key' => $work, 'section' => 'finishing', 'entity_id' => 'room:1', 'work_key' => $work],
            ['preparation', 'finishing', 'waste_removal', 'existing_structures_protection']);
        $evaluate = static fn (array $decisions, string $technology): array => (new ScopeCompletenessEvaluator)->evaluate($snapshot,
            'renovation', 'current_repair', ['finishing' => ['room:1']], $positions, $decisions, ['finishing' => ['room:1' => $technology]]);
        self::assertTrue($evaluate([$decision], 'paint:v1')['scope_complete']);
        self::assertSame('demolition', $evaluate([], 'paint:v1')['missing_requirements'][0]['requirement']);
        self::assertSame('demolition', $evaluate([$decision], 'paint:v2')['missing_requirements'][0]['requirement']);
    }
}
