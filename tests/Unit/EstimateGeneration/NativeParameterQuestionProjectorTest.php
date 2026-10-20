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
    public function test_separate_foundation_quantities_do_not_require_an_invented_global_aggregate_to_continue_planning(): void
    {
        $entity = $this->entity('beam', 'foundation_beam');
        $snapshot = new ProjectModelSnapshot([$entity], [], [], []);
        foreach (['foundation_beam_volume', 'foundation_slab_volume', 'foundation_volume'] as $formula) {
            $aggregate = ['code' => 'canonical_quantity_aggregate_unresolved', 'formula' => $formula, 'quantity_ids' => ['beam:a', 'slab:b']];
            $view = $this->project($snapshot, [$aggregate], ['structures']);
            self::assertSame([], $view['questions']);
            self::assertSame([], $view['limitations']);
            self::assertSame('planning_required', \App\BusinessModules\Addons\EstimateGeneration\Application\Factory\FactoryInputInspector::summarize(['available' => true, ...$view])['outcome']);
            $missing = $this->project($snapshot, [$aggregate, $this->warning('beam', 'foundation_beam_volume', 'height')], ['structures']);
            self::assertCount(1, $missing['questions']);
            foreach (['quantity_formula_budget_exceeded', 'operand_source_conflict'] as $code) {
                $blocked = $this->project($snapshot, [$aggregate, ['code' => $code, 'formula' => $formula]], ['structures']);
                self::assertNotEmpty($blocked['limitations']);
            }
            $coverage = $this->warning('beam', 'foundation_beam_volume', 'height');
            $coverage['inputs'] = [['code' => 'geometry_coverage_unresolved']];
            self::assertNotEmpty($this->project($snapshot, [$aggregate, $coverage], ['structures'])['limitations']);
        }
        foreach (['floor_area', 'sloped_roof_area', 'equipment_count', 'cable_length', 'earthwork_volume'] as $formula) {
            $view = $this->project($snapshot, [['code' => 'canonical_quantity_aggregate_unresolved', 'formula' => $formula]], ['structures']);
            self::assertContains('additional_review_required', $view['limitations']);
        }
    }

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

    public function test_missing_beam_height_has_an_optional_bound_estimating_scenario_for_an_explicit_single_storey_house(): void
    {
        $entity = $this->entity('beam', 'foundation_beam');
        $snapshot = new ProjectModelSnapshot([$entity], [], [], []);
        $warnings = [$this->warning($entity->id, 'foundation_beam_volume', 'height')];
        $plain = $this->project($snapshot, $warnings, ['structures']);
        $proposed = $this->projector()->project($snapshot, str_repeat('a', 64), ['structures'], $warnings, [], 1, true,
            ['building_type' => 'Одноэтажный жилой дом', 'floors' => 1]);
        self::assertArrayNotHasKey('estimating_suggestion', $plain['questions'][0]);
        self::assertSame(['value' => '0.4', 'unit' => 'm', 'basis' => 'assumption',
            'description' => 'estimate_generation.native_questions.estimating_suggestions.single_storey_foundation_height[]'],
            $proposed['questions'][0]['estimating_suggestion']);
        self::assertNotSame($plain['questions'][0]['answer']['version'], $proposed['questions'][0]['answer']['version']);
        self::assertSame([], $snapshot->facts);
        self::assertSame('0', $proposed['cost_units']);
    }

    public function test_estimating_scenario_does_not_replace_existing_or_conflicting_dimensions_or_other_building_contexts(): void
    {
        $entity = $this->entity('beam', 'foundation_beam');
        $warnings = [$this->warning($entity->id, 'foundation_beam_volume', 'height')];
        foreach ([[], ['building_type' => 'Склад', 'floors' => 1], ['building_type' => 'Жилой дом', 'floors' => 2],
            ['building_type' => 'Жилой дом', 'floors' => null], ['building_type' => 'Производственный жилой дом', 'floors' => 1]] as $context) {
            $view = $this->projector()->project(new ProjectModelSnapshot([$entity], [], [], []), str_repeat('a', 64), ['structures'], $warnings, [], 1, true, $context);
            self::assertArrayNotHasKey('estimating_suggestion', $view['questions'][0]);
        }
        foreach ([['height', '0.5'], ['floor_count', 2]] as [$type, $value]) {
            $fact = new Fact('existing', 1, 2, 3, $entity->sourceVersion, $entity->id, $type, $value, $type === 'height' ? 'm' : 'pcs', 0, 'user_input', 'confirmed', ['evidence:existing']);
            $view = $this->projector()->project(new ProjectModelSnapshot([$entity], [$fact], [], []), str_repeat('a', 64), ['structures'], $warnings, [], 1, true,
                ['building_type' => 'Жилой дом', 'floors' => 1]);
            self::assertArrayNotHasKey('estimating_suggestion', $view['questions'][0]);
        }
        $view = $this->projector()->project(new ProjectModelSnapshot([$entity], [], [], []), str_repeat('a', 64), ['structures'], $warnings, [], 1, false,
            ['building_type' => 'Жилой дом', 'floors' => 1]);
        self::assertArrayNotHasKey('estimating_suggestion', $view['questions'][0]);
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
