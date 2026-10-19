<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationParameterQuestionBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use PHPUnit\Framework\TestCase;

final class EvaluationParameterQuestionBuilderTest extends TestCase
{
    public function test_missing_equipment_count_is_one_integer_question_for_purchase_and_installation(): void
    {
        $entity = new Entity('equipment:p1', 1, 2, 3, 'sha256:'.str_repeat('a', 64), 'equipment', 'equipment:p1',
            ['name' => 'Насос P1', 'properties' => ['semantic_type' => 'equipment']]);
        $warning = ['code' => 'canonical_quantity_unresolved', 'entity_id' => $entity->id, 'formula' => 'equipment_count',
            'inputs' => [['code' => 'operand_missing', 'operand' => 'measurement']]];
        $positions = [];
        foreach (['equipment.purchase', 'equipment.install'] as $work) {
            $positions[] = ['key' => $work, 'entity_id' => $entity->id, 'work_key' => $work, 'quantity' => null, 'unit' => 'pcs'];
        }
        $questions = (new EvaluationParameterQuestionBuilder)->candidates($positions, new ProjectModelSnapshot([$entity], [], [], []), [$warning]);
        self::assertCount(1, $questions);
        self::assertSame('equipment_count', $questions[0]['answer']['parameter']);
        self::assertSame('count', $questions[0]['answer']['unit']);
        self::assertSame('Насос P1', $questions[0]['answer']['entity_name']);
        self::assertSame(['equipment.purchase', 'equipment.install'], $questions[0]['affected_position_keys']);
    }

    private function entity(string $id = 'wall:1', string $type = 'wall'): Entity
    {
        return new Entity($id, 1, 2, 3, 'sha256:'.str_repeat('a', 64), $type, $id);
    }

    private function warning(string $id = 'wall:1', string $parameter = 'height', string $code = 'operand_missing'): array
    {
        return ['code' => 'canonical_quantity_unresolved', 'entity_id' => $id, 'formula' => 'wall_net_area',
            'inputs' => [['code' => $code, 'operand' => $parameter]]];
    }

    private function position(string $key, string $entity = 'wall:1', string $work = 'finish.paint'): array
    {
        return ['key' => $key, 'entity_id' => $entity, 'work_key' => $work, 'quantity' => null, 'unit' => 'm2'];
    }

    public function test_one_wall_height_question_covers_all_dependent_works_without_inventing_a_quantity(): void
    {
        $snapshot = new ProjectModelSnapshot([$this->entity()], [], [], []);
        $positions = [$this->position('paint'), $this->position('plaster', work: 'rough.walls'), $this->position('floor', work: 'finish.floor')];
        $questions = (new EvaluationParameterQuestionBuilder)->candidates($positions, $snapshot, [$this->warning(), $this->warning()]);
        self::assertCount(1, $questions);
        self::assertSame(['paint', 'plaster'], $questions[0]['affected_position_keys']);
        self::assertSame(['kind' => 'project_parameter', 'entity_id' => 'wall:1', 'entity_type' => 'wall',
            'entity_name' => 'Стена', 'parameter' => 'height', 'unit' => 'm'], array_diff_key($questions[0]['answer'], ['version' => true]));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $questions[0]['answer']['version']);
        self::assertNull($positions[0]['quantity']);
    }

    public function test_same_parameter_on_two_walls_is_not_merged_and_stale_missing_input_is_not_repeated(): void
    {
        $fact = new Fact('height:1', 1, 2, 3, 'sha256:'.str_repeat('a', 64), 'wall:1', 'wall_height', '3', 'm',
            0, 'user_input', 'confirmed', ['evidence:height']);
        $proof = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence('evidence:height',
            1, 2, 3, $fact->sourceVersion, 'input:3', 'user_input', null, null, 'input_payload.manual_facts');
        $snapshot = new ProjectModelSnapshot([$this->entity(), $this->entity('wall:2')], [$fact], [$proof], []);
        $questions = (new EvaluationParameterQuestionBuilder)->candidates([$this->position('paint'), $this->position('paint-2', 'wall:2')],
            $snapshot, [$this->warning(), $this->warning('wall:2')]);
        self::assertCount(1, $questions);
        self::assertSame('wall:2', $questions[0]['answer']['entity_id']);
        self::assertSame(['paint-2'], $questions[0]['affected_position_keys']);
        $confirmation = (new EvaluationParameterQuestionBuilder)->candidates([$this->position('paint')], $snapshot,
            [$this->warning(code: 'operand_origin_requires_confirmation')]);
        self::assertCount(1, $confirmation);
    }

    public function test_unknown_or_unrelated_formulas_and_coverage_issues_do_not_create_a_numeric_answer(): void
    {
        $snapshot = new ProjectModelSnapshot([$this->entity()], [], [], []);
        foreach ([[...$this->warning(), 'formula' => 'direct_floor_area'], $this->warning('wall:missing'),
            $this->warning(parameter: 'geometry_coverage', code: 'geometry_coverage_unknown'),
            $this->warning(parameter: 'opening_widths')] as $warning) {
            self::assertSame([], (new EvaluationParameterQuestionBuilder)->candidates([$this->position('paint')], $snapshot, [$warning]));
        }
    }

    public function test_real_result_replaces_per_work_questions_with_one_typed_parameter_question(): void
    {
        $draft = ['evaluation_policy' => ['selected_sections' => ['finishing']], 'quantity_coverage_warnings' => [$this->warning()],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['work_items' => [
                ['key' => 'paint', 'work_key' => 'finish.paint', 'name' => 'Окраска стен', 'quantity' => null, 'unit' => 'm2',
                    'metadata' => ['scope_entity_id' => 'wall:1', 'requirement_key' => 'finishing']],
                ['key' => 'plaster', 'work_key' => 'rough.walls', 'name' => 'Штукатурка стен', 'quantity' => null, 'unit' => 'm2',
                    'metadata' => ['scope_entity_id' => 'wall:1', 'requirement_key' => 'finishing']],
            ]]]]]];
        $result = (new UniversalEvaluationBuilder)->build($draft, new ProjectModelSnapshot([$this->entity()], [], [], []));
        self::assertLessThanOrEqual(3, count($result['priority_questions']));
        self::assertSame('parameter:wall:1:height', $result['priority_questions'][0]['key']);
        self::assertSame(['paint', 'plaster'], $result['priority_questions'][0]['affected_position_keys']);
        self::assertNotContains('quantity:paint', array_column($result['priority_questions'], 'key'));
        self::assertNotContains('quantity:plaster', array_column($result['priority_questions'], 'key'));
        self::assertNull($result['scenarios'][0]['positions'][0]['quantity']);
    }

    public function test_foundation_questions_keep_height_and_thickness_scoped_to_their_elements(): void
    {
        foreach ([['foundation_beam', 'foundation_beam_volume', 'height'], ['foundation_slab', 'foundation_slab_volume', 'thickness']] as [$type, $formula, $parameter]) {
            $entity = new Entity('foundation:1', 1, 2, 3, 'sha256:'.str_repeat('a', 64), 'quantity', 'foundation:1', ['semantic_type' => $type]);
            $position = ['key' => 'concrete', 'work_key' => 'foundation.concrete', 'entity_id' => $entity->id, 'quantity' => null, 'unit' => 'm3'];
            $warning = ['code' => 'canonical_quantity_unresolved', 'entity_id' => $entity->id, 'formula' => $formula,
                'inputs' => [['code' => 'operand_missing', 'operand' => $parameter]]];
            $questions = (new EvaluationParameterQuestionBuilder)->candidates([$position], new ProjectModelSnapshot([$entity], [], [], []), [$warning]);
            self::assertCount(1, $questions);
            self::assertSame('parameter:foundation:1:'.$parameter, $questions[0]['key']);
            self::assertSame($type, $questions[0]['answer']['entity_type']);
            self::assertSame($parameter, $questions[0]['answer']['parameter']);
            self::assertSame('m', $questions[0]['answer']['unit']);
            self::assertTrue($questions[0]['correctness_blocker']);
            self::assertTrue($questions[0]['cost_blocker']);
            self::assertNull($position['quantity']);
        }
    }
}
