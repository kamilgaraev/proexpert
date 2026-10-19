<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\NativeParameterQuestionProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use PHPUnit\Framework\TestCase;

final class NativeParameterQuestionProjectorTest extends TestCase
{
    public function test_all_ten_house_sections_have_native_parameter_questions_without_draft_positions(): void
    {
        $definitions = [
            ['room', 'floor_area', 'length', 'm', 'finishing'], ['wall', 'wall_net_area', 'wall_height', 'm', 'structures'],
            ['roof', 'sloped_roof_area', 'plan_areas', 'm2', 'roofing'], ['site', 'earthwork_volume', 'depth', 'm', 'earthworks'],
            ['water_pipe', 'water_pipe_length', 'measurement', 'm', 'water_supply'], ['sewer_pipe', 'sewer_pipe_length', 'measurement', 'm', 'sewerage'],
            ['radiator', 'radiator_count', 'measurement', 'count', 'heating'], ['ventilation_duct', 'duct_length', 'measurement', 'm', 'ventilation'],
            ['electrical_circuit', 'cable_length', 'measurement', 'm', 'electricity'], ['equipment', 'equipment_count', 'measurement', 'count', 'equipment'],
            ['foundation_beam', 'foundation_beam_volume', 'height', 'm', 'structures'], ['foundation_slab', 'foundation_slab_volume', 'thickness', 'm', 'structures'],
        ];
        $entities = $warnings = $sections = [];
        foreach ($definitions as [$type, $formula, $operand, $unit, $section]) {
            $entity = $this->entity($type.':one', $type);
            $entities[] = $entity;
            $warnings[] = $this->warning($entity->id, $formula, $operand);
            $sections[] = $section;
        }
        $view = $this->project(new ProjectModelSnapshot($entities, [], [], []), $warnings, array_values(array_unique($sections)));
        self::assertCount(12, $view['questions']);
        self::assertSame([], $view['limitations']);
        self::assertSame('0', $view['cost_units']);
        $byEntity = array_column(array_column($view['questions'], 'answer'), null, 'entity_id');
        foreach ($definitions as [$type, $formula, $operand, $unit]) {
            self::assertSame($unit, $byEntity[$type.':one']['unit']);
        }
        foreach ($view['questions'] as $question) {
            self::assertTrue($question['can_answer']);
            self::assertArrayNotHasKey('affected_position_keys', $question);
            self::assertArrayNotHasKey('quantity', $question);
            self::assertSame($question['key'], $question['answer']['native_question']['key']);
        }
        self::assertCount(4, $this->project(new ProjectModelSnapshot($entities, [], [], []), $warnings, ['structures'])['questions']);
    }

    public function test_geometry_questions_keep_each_child_and_parameter_and_do_not_rewrite_the_parent(): void
    {
        $entities = [$this->entity('wall:one', 'wall'), $this->entity('opening:a', 'opening'), $this->entity('opening:b', 'opening')];
        $warning = $this->warning('wall:one', 'wall_net_area', 'wall_height');
        $warning['inputs'] = [];
        foreach (['opening:a', 'opening:b'] as $id) {
            foreach (['width', 'height'] as $parameter) {
                $warning['inputs'][] = ['code' => 'geometry_operand_missing', 'operand' => 'wall_opening', 'entity_id' => $id, 'missing_operand' => $parameter];
            }
        }
        $view = $this->project(new ProjectModelSnapshot($entities, [], [], []), [$warning], ['finishing']);
        self::assertCount(4, $view['questions']);
        $ids = array_values(array_unique(array_column(array_column($view['questions'], 'answer'), 'entity_id')));
        sort($ids, SORT_STRING);
        self::assertSame(['opening:a', 'opening:b'], $ids);
    }

    public function test_conflicts_ambiguity_coverage_and_budget_are_not_silently_turned_into_numeric_answers(): void
    {
        $entity = $this->entity('foundation_beam:one', 'foundation_beam');
        $facts = [];
        foreach (['height', 'wall_height'] as $index => $type) {
            $facts[] = new Fact('fact:'.$index, 1, 2, 3, $entity->sourceVersion, $entity->id, $type, '0.4', 'm', 0, 'ai_inference', 'candidate', []);
        }
        $warning = $this->warning($entity->id, 'foundation_beam_volume', 'height');
        $view = $this->project(new ProjectModelSnapshot([$entity], $facts, [], []), [$warning], ['structures']);
        self::assertCount(1, $view['questions']);
        self::assertFalse($view['questions'][0]['can_answer']);
        self::assertContains('source_review_required', $view['limitations']);
        foreach (['operand_source_conflict', 'evidence_budget_exceeded', 'geometry_coverage_unresolved', 'engineering_measurement_incompatible'] as $code) {
            $warning['inputs'] = [['code' => $code, 'operand' => 'height']];
            $blocked = $this->project(new ProjectModelSnapshot([$entity], [], [], []), [$warning], ['structures']);
            self::assertSame([], $blocked['questions']);
            self::assertNotEmpty($blocked['limitations']);
        }
    }

    public function test_source_state_scope_and_explicit_truncation_are_bound_without_claiming_completeness(): void
    {
        $entities = $warnings = [];
        for ($index = 0; $index < 64; $index++) {
            $entity = $this->entity('foundation_beam:'.$index, 'foundation_beam');
            $entities[] = $entity;
            $warnings[] = $this->warning($entity->id, 'foundation_beam_volume', 'height');
        }
        $snapshot = new ProjectModelSnapshot($entities, [], [], []);
        $view = $this->project($snapshot, $warnings, ['structures']);
        self::assertCount(50, $view['questions']);
        self::assertSame(64, $view['total_questions']);
        self::assertTrue($view['truncated']);
        self::assertContains('questions_truncated', $view['limitations']);
        $changed = $this->projector()->project($snapshot, str_repeat('c', 64), ['structures'], $warnings, [], 2, true);
        self::assertNotSame($view['questions'][0]['answer']['version'], $changed['questions'][0]['answer']['version']);
        $foreign = new Entity('foundation_beam:document', 1, 2, 3, 'sha256:'.str_repeat('b', 64), 'quantity', 'foundation_beam:document', ['semantic_type' => 'foundation_beam']);
        $unanchored = $this->project(new ProjectModelSnapshot([$foreign], [], [], []), [$this->warning($foreign->id, 'foundation_beam_volume', 'height')], ['structures']);
        self::assertFalse($unanchored['questions'][0]['can_answer']);
        self::assertContains('source_review_required', $unanchored['limitations']);
    }

    private function project(ProjectModelSnapshot $snapshot, array $warnings, array $sections): array
    {
        return $this->projector()->project($snapshot, str_repeat('a', 64), $sections, $warnings, [], 1, true);
    }

    private function projector(): NativeParameterQuestionProjector
    {
        return new NativeParameterQuestionProjector(static fn (string $key, array $parameters): string => $key.json_encode($parameters));
    }

    private function entity(string $id, string $type): Entity
    {
        return new Entity($id, 1, 2, 3, 'sha256:'.str_repeat('b', 64), in_array($type, Entity::TYPES, true) ? $type : 'quantity', $id,
            ['semantic_type' => $type, 'identity' => ['manual_key' => $id]]);
    }

    private function warning(string $id, string $formula, string $operand): array
    {
        return ['code' => 'canonical_quantity_unresolved', 'entity_id' => $id, 'formula' => $formula,
            'inputs' => [['code' => 'operand_missing', 'operand' => $operand]]];
    }
}
