<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationQuestionPrioritizer;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationResultValidator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use PHPUnit\Framework\TestCase;

final class UniversalEvaluationBuilderTest extends TestCase
{
    public function test_quantity_source_keeps_exact_document_reference_page_region_and_native_cell(): void
    {
        $version = 'sha256:'.str_repeat('a', 64);
        $entity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('room:1', 1, 2, 3, $version, 'room', 'room:1');
        $evidence = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence('evidence:1', 1, 2, 3, $version,
            'artifact:rendered-page', 'document', 7, ['x' => 1, 'y' => 2, 'width' => 3, 'height' => 4], 'xlsx:sheet:Размеры!C2', 'native_numeric_parser', 'document:42');
        $fact = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact('fact:1', 1, 2, 3, $version, $entity->id, 'area', '20', 'm2', 0, 'document', 'confirmed', [$evidence->id]);
        $item = ['key' => 'floor', 'name' => 'Пол', 'quantity' => '20', 'unit' => 'm2', 'quantity_basis_details' => ['formula_key' => 'direct_floor_area', 'formula_inputs' => ['entity_id' => $entity->id, 'operands' => [['fact_id' => $fact->id]]]]];
        $draft = ['evaluation_policy' => ['selected_sections' => ['finishing']], 'local_estimates' => [['key' => 'rough_finishing', 'sections' => [['work_items' => [$item]]]]]];
        $result = (new UniversalEvaluationBuilder)->build($draft, new ProjectModelSnapshot([$entity], [$fact], [$evidence], []));
        $source = $result['scenarios'][0]['positions'][0]['quantity_evidence_details'][0];
        self::assertSame(42, $source['document_id']);
        self::assertSame(7, $source['page']);
        self::assertSame($evidence->region, $source['region']);
        self::assertSame($evidence->nativeReference, $source['native_reference']);
        self::assertSame('20', $source['value']);
    }

    public function test_partial_composition_keeps_all_rows_and_requested_uncovered_sections(): void
    {
        $rows = [['key' => 'floor', 'name' => 'Устройство пола', 'quantity' => '20', 'unit' => 'm2',
            'commercial_price_snapshot' => ['unit_price' => '100', 'unit' => 'm2', 'currency' => 'RUB', 'verified' => true,
                'source_type' => 'catalog', 'source_reference' => 'catalog:1', 'as_of_date' => '2026-10-10']],
            ['key' => 'wall', 'name' => 'Отделка стен', 'quantity' => null, 'unit' => 'm2']];
        $draft = ['evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['finishing', 'electricity']],
            'local_estimates' => [['key' => 'finish_finishing', 'sections' => [['work_items' => $rows]]]]];
        $result = (new UniversalEvaluationBuilder)->build($draft, new ProjectModelSnapshot([], [], [], []));
        (new EvaluationResultValidator)->validate($result);
        self::assertSame(['finishing', 'electricity'], $result['selected_scope']);
        self::assertSame(['electricity'], $result['scope_boundaries']['uncovered_sections']);
        self::assertCount(2, $result['scenarios'][0]['positions']);
        self::assertSame(['RUB' => '2000.00'], $result['scenarios'][0]['known_subtotals']);
        self::assertSame(['finishing', 'electricity'], array_column($result['missing_requirements'], 'section'));
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertSame(['wall'], $result['priority_questions'][0]['affected_position_keys']);
    }

    public function test_questions_are_bounded_merge_affected_work_and_skip_already_confirmed_parameters(): void
    {
        $questions = [];
        foreach (['q1', 'q2', 'q3', 'q4'] as $key) {
            $questions[] = ['key' => $key, 'question' => 'Уточните параметр', 'affected_position_keys' => [$key],
                'correctness_blocker' => $key === 'q4', 'cost_blocker' => true];
        }
        $questions[] = ['key' => 'q4', 'question' => 'Уточните параметр', 'affected_position_keys' => ['another-work'], 'correctness_blocker' => true];
        $result = (new EvaluationQuestionPrioritizer)->prioritize($questions, ['q2']);
        self::assertCount(3, $result);
        self::assertSame('q4', $result[0]['key']);
        self::assertSame(['q4', 'another-work'], $result[0]['affected_position_keys']);
        self::assertNotContains('q2', array_column($result, 'key'));
    }

    public function test_blocked_or_zero_normative_snapshot_is_unknown_instead_of_a_zero_price(): void
    {
        $item = ['key' => 'floor', 'name' => 'Пол', 'unit' => 'm2', 'quantity' => '20', 'evaluation_price_basis_quantity' => '20',
            'pricing_status' => 'not_calculated', 'pricing_blocker' => 'pricing_not_calculated',
            'price_snapshot' => ['source_type' => 'regional_resource_aggregate', 'final_amount' => '0.00',
                'source_reference' => 'price:1', 'currency' => 'RUB', 'region_id' => 1, 'period_id' => 1, 'version_id' => 1,
                'coefficients' => ['resource_evidence' => [['final_amount' => '0.00', 'source_reference' => 'resource:1']]]]];
        $draft = ['evaluation_policy' => ['selected_sections' => ['finishing']], 'local_estimates' => [['key' => 'rough_finishing', 'sections' => [['work_items' => [$item]]]]]];
        $result = (new UniversalEvaluationBuilder)->build($draft, new ProjectModelSnapshot([], [], [], []));
        self::assertNull($result['scenarios'][0]['positions'][0]['price_snapshot']);
        self::assertNull($result['scenarios'][0]['positions'][0]['unit_price']);
        self::assertNull($result['scenarios'][0]['positions'][0]['total_cost']);
        self::assertSame([], $result['scenarios'][0]['known_subtotals']);
    }
}
