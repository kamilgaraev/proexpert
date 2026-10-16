<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use PHPUnit\Framework\TestCase;

final class UniversalEvaluationSourceConflictTest extends TestCase
{
    public function test_conflicting_sources_remain_a_visible_correctness_blocker_in_the_selected_work(): void
    {
        $entities = $facts = $proofs = [];
        foreach (['plan' => '20', 'schedule' => '30'] as $source => $area) {
            $version = 'sha256:'.hash('sha256', $source);
            $entity = new Entity('room:'.$source, 1, 2, 3, $version, 'room', 'room:'.$source);
            $proof = new Evidence('evidence:'.$source, 1, 2, 3, $version, 'artifact:'.$source,
                'document', 1, null, 'xlsx:sheet:Rooms!C2', 'native_numeric_parser', 'document:'.($source === 'plan' ? '41' : '42'));
            $facts[] = new Fact('fact:'.$source, 1, 2, 3, $version, $entity->id, 'area', $area, 'm2', 0.0, 'document', 'confirmed', [$proof->id]);
            $entities[] = $entity;
            $proofs[] = $proof;
        }
        $conflict = Conflict::betweenSources('conflict:area', $facts, 'cross_document_incompatible_values', 'sha256:'.str_repeat('c', 64));
        $snapshot = new ProjectModelSnapshot($entities, $facts, $proofs, [$conflict]);
        $draft = ['evaluation_policy' => ['selected_sections' => ['finishing']],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['work_items' => [[
                'key' => 'finish.floor', 'name' => 'Отделка пола', 'quantity' => null, 'unit' => 'm2',
                'metadata' => ['scope_entity_id' => $entities[0]->id],
                'source_refs' => ['fact:plan'],
            ]]]]]]];

        $builder = new UniversalEvaluationBuilder(sourceConflicts: new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationSourceConflictProjector(
            static fn (string $key, array $replace): string => $key,
        ));
        $result = $builder->build($draft, $snapshot);

        self::assertContains('source_conflict', array_column($result['missing_requirements'], 'requirement'));
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertContains($conflict->id, array_column($result['priority_questions'], 'conflict_id'));
        self::assertSame('finish.floor', $result['priority_questions'][0]['affected_position_keys'][0]);
        self::assertNull($result['scenarios'][0]['positions'][0]['quantity']);
        self::assertNull($result['scenarios'][0]['positions'][0]['total_cost']);
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['quantity'] = '20';
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['quantity_basis_details'] = ['formula_inputs' => ['operands' => [['fact_id' => 'fact:plan']]]];
        $result = $builder->build($draft, $snapshot);
        self::assertNull($result['scenarios'][0]['positions'][0]['quantity']);
        self::assertSame('review_source_questions', $result['priority_questions'][0]['next_action']);
        $item = &$draft['local_estimates'][0]['sections'][0]['work_items'][0];
        unset($item['quantity_basis_details']);
        $item['commercial_price_snapshot'] = ['unit_price' => '100', 'unit' => 'm2', 'currency' => 'RUB', 'verified' => true,
            'source_type' => 'catalog', 'source_reference' => 'catalog:1', 'as_of_date' => '2026-10-10'];
        foreach ([['fact:plan'], [['fact_id' => 'fact:plan', 'role' => 'quantity_operand']]] as $references) {
            $item['source_refs'] = $references;
            $withoutConflict = $builder->build($draft, new ProjectModelSnapshot($entities, $facts, $proofs, []));
            self::assertSame(['RUB' => '2000.00'], $withoutConflict['scenarios'][0]['known_subtotals']);
            $legacy = $builder->build($draft, $snapshot);
            self::assertNull($legacy['scenarios'][0]['positions'][0]['quantity']);
            self::assertNull($legacy['scenarios'][0]['positions'][0]['price_snapshot']);
            self::assertNull($legacy['scenarios'][0]['positions'][0]['total_cost']);
            self::assertSame([], $legacy['scenarios'][0]['known_subtotals']);
        }
        unset($item['metadata']['scope_entity_id']);
        $item['source_refs'] = [];
        $unbound = $builder->build($draft, $snapshot);
        self::assertContains('source_conflict', array_column($unbound['missing_requirements'], 'requirement'));
        $sourceQuestions = array_values(array_filter($unbound['priority_questions'], static fn (array $question): bool => ($question['conflict_id'] ?? null) === $conflict->id));
        self::assertSame([], $sourceQuestions[0]['affected_position_keys']);
        $item['metadata']['scope_entity_id'] = 'room:unrelated';
        $unrelated = new Entity('room:unrelated', 1, 2, 3, $entities[0]->sourceVersion, 'room', 'room:unrelated');
        $separate = new ProjectModelSnapshot([...$entities, $unrelated], $facts, $proofs, [$conflict]);
        $item['source_refs'] = [];
        self::assertNotContains('source_conflict', array_column($builder->build($draft, $separate)['missing_requirements'], 'requirement'));
        unset($item);
        $stale = new ProjectModelSnapshot($entities, [$facts[0]], $proofs, [$conflict]);
        self::assertNotContains('source_conflict', array_column($builder->build($draft, $stale)['missing_requirements'], 'requirement'));
    }
}
