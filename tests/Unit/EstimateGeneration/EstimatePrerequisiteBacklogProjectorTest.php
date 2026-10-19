<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Review\EstimatePrerequisiteBacklogProjector;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackage;
use PHPUnit\Framework\TestCase;

final class EstimatePrerequisiteBacklogProjectorTest extends TestCase
{
    public function test_groups_repeated_missing_inputs_without_inventing_values_or_entity_targets(): void
    {
        $finding = $this->finding([
            ['id' => 'formula:one', 'unit' => 'm2', 'unresolved_inputs' => ['roof_area', 'roof_area']],
            ['id' => 'formula:two', 'unit' => 'm3', 'unresolved_inputs' => ['roof_area']],
        ]);
        $group = $this->projector()->project($finding, $this->projection());

        self::assertSame('estimate-prerequisites:v1', $group['contract_version']);
        self::assertCount(1, $group['questions']);
        $question = $group['questions'][0];
        self::assertSame('roof_area', $question['input_fact_type']);
        self::assertSame(['formula:one', 'formula:two'], $question['formula_ids']);
        self::assertSame(['m2', 'm3'], $question['quantity_units']);
        self::assertSame('rule', $question['scope']);
        self::assertSame('review_sources', $question['answer_action']);
        self::assertSame('Площадь кровли', $question['label']);
        self::assertArrayNotHasKey('value', $question);
        self::assertArrayNotHasKey('entity_id', $question);
        self::assertArrayNotHasKey('fact_id', $question);
        self::assertFalse($group['partial']);
        self::assertSame(1, $group['total']);
    }

    public function test_known_operands_are_not_reasked_and_projection_never_mutates_the_package(): void
    {
        $formulas = [
            ['id' => 'formula:known', 'unit' => 'm2', 'resolved_value' => '56.86',
                'operands' => [['fact_id' => 'fact:roof', 'value' => '56.86']]],
            ['id' => 'formula:missing', 'unit' => 'm3', 'unresolved_inputs' => ['waste_volume']],
        ];
        $finding = $this->finding($formulas);
        $group = $this->projector()->project($finding, $this->projection());

        self::assertSame(['waste_volume'], array_column($group['questions'], 'input_fact_type'));
        self::assertSame($formulas, $finding->workPackage->quantityFormulas);
    }

    public function test_a_real_unique_scope_exposes_a_typed_target_without_enabling_an_unimplemented_writer(): void
    {
        $target = ['entity_id' => 'entity:roof', 'source_version' => 'sha256:'.str_repeat('a', 64)];
        $finding = $this->finding([
            ['id' => 'formula:roof', 'unit' => 'm2', 'unresolved_inputs' => ['roof_area']],
        ], inputScope: ['status' => 'single_entity', 'target' => $target]);
        $question = $this->projector()->project($finding, $this->projection())['questions'][0];

        self::assertSame('entity', $question['scope']);
        self::assertSame($target, $question['target']);
        self::assertSame(['type' => 'positive_decimal', 'unit' => 'm2', 'basis' => ['input', 'measurement', 'assumption']], $question['input_contract']);
        self::assertSame('review_sources', $question['answer_action']);
        self::assertArrayNotHasKey('fact_id', $question);
        self::assertArrayNotHasKey('value', $question);

        $other = $this->finding($finding->workPackage->quantityFormulas,
            inputScope: ['status' => 'single_entity', 'target' => [...$target, 'entity_id' => 'entity:other-roof']]);
        self::assertNotSame($question['key'], $this->projector()->project($other, $this->projection())['questions'][0]['key']);
    }

    public function test_ambiguous_missing_or_invalid_scope_never_invents_a_target(): void
    {
        foreach ([[], ['status' => 'ambiguous', 'entity_count' => 2],
            ['status' => 'single_entity', 'target' => ['entity_id' => 'entity:roof', 'source_version' => 'invalid']],
        ] as $inputScope) {
            $finding = $this->finding([
                ['id' => 'formula:roof', 'unit' => 'm2', 'unresolved_inputs' => ['roof_area']],
            ], inputScope: $inputScope);
            $question = $this->projector()->project($finding, $this->projection())['questions'][0];
            self::assertSame('rule', $question['scope']);
            self::assertArrayNotHasKey('target', $question);
            self::assertArrayNotHasKey('input_contract', $question);
        }
    }

    public function test_revision_changes_with_source_or_rule_versions_but_not_formula_order(): void
    {
        $formulas = [
            ['id' => 'formula:z', 'unit' => 'm2', 'unresolved_inputs' => ['roof_area']],
            ['id' => 'formula:a', 'unit' => 'm2', 'unresolved_inputs' => ['roof_area']],
        ];
        $projector = $this->projector();
        $first = $projector->project($this->finding($formulas), $this->projection());
        $reordered = $projector->project($this->finding(array_reverse($formulas)), $this->projection());
        $newSource = $projector->project($this->finding($formulas), [...$this->projection(), 'source_version' => 'source:v2']);
        $newRule = $projector->project($this->finding($formulas, ruleHash: str_repeat('f', 64)), $this->projection());

        self::assertSame($first, $reordered);
        self::assertNotSame($first['version'], $newSource['version']);
        self::assertSame($first['questions'][0]['key'], $newSource['questions'][0]['key']);
        self::assertNotSame($first['questions'][0]['key'], $newRule['questions'][0]['key']);
        self::assertSame('source:v1', $first['provenance']['source_version']);
        self::assertSame(str_repeat('c', 64), $first['provenance']['accepted_input_fingerprint']);
        self::assertSame('finding:roof', $first['provenance']['finding_key']);
    }

    public function test_resolved_excluded_and_inapplicable_findings_do_not_create_questions(): void
    {
        foreach (['resolved', 'excluded', 'not_applicable', 'unresolved'] as $status) {
            $group = $this->projector()->project($this->finding([
                ['id' => 'formula:roof', 'unit' => 'm2', 'unresolved_inputs' => ['roof_area']],
            ], status: $status), $this->projection());
            self::assertSame([], $group['questions']);
            self::assertSame(0, $group['total']);
        }
    }

    public function test_unknown_parameter_uses_a_human_label_and_incomplete_contract_stays_explicit(): void
    {
        $group = $this->projector()->project($this->finding([
            ['id' => 'formula:custom', 'unit' => 'item', 'unresolved_inputs' => ['company_custom_quantity']],
            ['id' => 'formula:broken', 'unit' => 'item', 'unresolved_inputs' => [null, 123, ['prompt' => 'private']]],
        ]), $this->projection());

        self::assertSame('Исходный параметр', $group['questions'][0]['label']);
        self::assertTrue($group['partial']);
        self::assertSame(['prerequisite_input_contract_invalid'], $group['limitations']);
        self::assertStringNotContainsString('private', json_encode($group, JSON_THROW_ON_ERROR));
    }

    public function test_large_question_group_reports_its_limit_without_claiming_full_coverage(): void
    {
        $inputs = array_map(static fn (int $id): string => 'parameter_'.$id, range(1, 101));
        $group = $this->projector()->project($this->finding([
            ['id' => 'formula:large', 'unit' => 'item', 'unresolved_inputs' => $inputs],
        ]), $this->projection());

        self::assertCount(100, $group['questions']);
        self::assertSame(101, $group['total']);
        self::assertTrue($group['partial']);
        self::assertSame(['prerequisite_question_budget_reached'], $group['limitations']);
    }

    private function projector(): EstimatePrerequisiteBacklogProjector
    {
        return new EstimatePrerequisiteBacklogProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.prerequisites.parameters.roof_area' => 'Площадь кровли',
            'estimate_generation.prerequisites.parameters.waste_volume' => 'Объём отходов',
            'estimate_generation.prerequisites.unknown_parameter' => 'Исходный параметр',
            default => $key,
        });
    }

    private function projection(): array
    {
        return ['source_version' => 'source:v1', 'input_fingerprint' => str_repeat('c', 64),
            'catalog_version' => 'technology:v1', 'catalog_hash' => str_repeat('d', 64),
            'rule_catalog_version' => 'rules:v1', 'rule_catalog_hash' => str_repeat('e', 64)];
    }

    private function finding(array $formulas, string $status = 'unknown', string $ruleHash = '', array $inputScope = []): CompletenessFinding
    {
        return new CompletenessFinding('roof', '1.0.0', $ruleHash ?: str_repeat('a', 64), 'finding:roof', 1,
            'technology_required', $status, 'warning', 'Проверить кровлю', 0.75,
            ['fact:roof-type'], ['entity:roof'], ['roof_type'], ['status' => 'applicable'], [], null,
            new TechnologyWorkPackage('package:roof', [], [], [], [], $formulas, [], [], [], [], ['input_scope' => $inputScope]));
    }
}
