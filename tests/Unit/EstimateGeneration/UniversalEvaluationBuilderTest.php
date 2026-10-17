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
    public function test_native_price_identity_ignores_capture_time_but_preserves_source_and_amount_changes(): void
    {
        $item = ['key' => 'floor', 'name' => 'Пол', 'unit' => 'm2', 'quantity' => '2', 'evaluation_price_basis_quantity' => '2',
            'pricing_status' => 'calculated', 'price_snapshot' => ['source_type' => 'regional_resource_aggregate',
                'source_reference' => 'price:1', 'currency' => 'RUB', 'region_id' => 1, 'period_id' => 1, 'version_id' => 1,
                'final_amount' => '20.00', 'captured_at' => '2026-10-17T01:00:00+00:00',
                'coefficients' => ['resource_evidence' => [['source_type' => 'regional_catalog', 'source_reference' => 'resource:1',
                    'final_amount' => '20.00', 'captured_at' => '2026-10-17T01:00:00+00:00']]]]];
        $draft = ['evaluation_policy' => ['selected_sections' => ['finishing']],
            'local_estimates' => [['key' => 'rough_finishing', 'sections' => [['work_items' => [$item]]]]]];
        $builder = new UniversalEvaluationBuilder;
        $snapshot = new ProjectModelSnapshot([], [], [], []);
        $original = $builder->build($draft, $snapshot)['scenarios'][0]['positions'][0]['price_snapshot'];
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['price_snapshot']['captured_at'] = '2026-10-17T02:00:00+00:00';
        $draft['local_estimates'][0]['sections'][0]['work_items'][0]['price_snapshot']['coefficients']['resource_evidence'][0]['captured_at'] = '2026-10-17T02:00:00+00:00';
        self::assertSame($original, $builder->build($draft, $snapshot)['scenarios'][0]['positions'][0]['price_snapshot']);
        self::assertSame('2026-10-17T02:00:00+00:00', $draft['local_estimates'][0]['sections'][0]['work_items'][0]['price_snapshot']['captured_at']);
        foreach ([['source_reference' => 'price:2'], ['version_id' => 2], ['final_amount' => '21.00']] as $change) {
            $changed = $draft;
            $changed['local_estimates'][0]['sections'][0]['work_items'][0]['price_snapshot'] = [...$item['price_snapshot'], ...$change];
            $result = $builder->build($changed, $snapshot)['scenarios'][0]['positions'][0]['price_snapshot'];
            self::assertNotSame($original['calculation_basis']['source_hash'], $result['calculation_basis']['source_hash']);
        }
    }

    public function test_zero_price_audit_finding_is_resolved_only_by_matching_calculated_native_money(): void
    {
        $item = ['key' => 'floor', 'name' => 'Пол', 'unit' => 'm2', 'quantity' => '20', 'evaluation_price_basis_quantity' => '20',
            'pricing_status' => 'calculated', 'total_cost' => '20.00',
            'price_snapshot' => ['source_type' => 'regional_resource_aggregate', 'final_amount' => '20.00',
                'source_reference' => 'price:1', 'currency' => 'RUB', 'region_id' => 1, 'period_id' => 1, 'version_id' => 1,
                'coefficients' => ['resource_evidence' => [['source_type' => 'normative_rate_base', 'final_amount' => '20.00',
                    'source_reference' => 'normative_rates:1', 'version_id' => 1, 'coefficients' => ['base_year' => 2001]]]]]];
        $draft = ['evaluation_policy' => ['selected_sections' => ['finishing']], 'audit_review_items' => [
            ['finding_id' => 'audit:zero', 'item_key' => 'floor', 'type' => 'suspicious_zero', 'reason' => 'Итог ранее отсутствовал.'],
            ['finding_id' => 'audit:quantity', 'item_key' => 'floor', 'type' => 'quantity_mismatch', 'reason' => 'Проверить исходный объём.'],
        ], 'local_estimates' => [['key' => 'rough_finishing', 'sections' => [['work_items' => [$item]]]]]];
        $builder = new UniversalEvaluationBuilder;
        $snapshot = new ProjectModelSnapshot([], [], [], []);
        $result = $builder->build($draft, $snapshot);
        self::assertSame(['corrected_by_native_pricing'], array_column($result['audit_dispositions'], 'status'));
        self::assertCount(1, array_filter($result['missing_requirements'], static fn (array $row): bool => $row['requirement'] === 'audit_finding'));
        self::assertCount(2, $draft['audit_review_items']);
        foreach ([['pricing_status' => 'not_calculated'], ['total_cost' => '0.00'], ['total_cost' => '21.00'],
            ['evaluation_price_basis_quantity' => '19']] as $change) {
            $draft['local_estimates'][0]['sections'][0]['work_items'][0] = [...$item, ...$change];
            $blocked = $builder->build($draft, $snapshot);
            self::assertSame([], $blocked['audit_dispositions']);
            self::assertCount(2, array_filter($blocked['missing_requirements'], static fn (array $row): bool => $row['requirement'] === 'audit_finding'));
        }
    }

    public function test_zero_price_audit_finding_requires_matching_current_verified_commercial_money(): void
    {
        $price = ['source_type' => 'catalog', 'source_reference' => 'catalog:6', 'source_hash' => str_repeat('a', 64),
            'unit_price' => '520.00', 'unit' => 'm2', 'currency' => 'RUB', 'as_of_date' => '2026-10-16',
            'verified' => true, 'applicable' => true, 'stale' => false, 'conditions_missing' => [],
            'vat_mode' => 'excluded', 'vat_rate' => '22', 'delivery_included' => true];
        $item = ['key' => 'floor', 'name' => 'Пол', 'unit' => 'm2', 'quantity' => '8.51', 'unit_price' => '520.00',
            'quantity_status' => 'supported', 'pricing_status' => 'calculated', 'total_cost' => '4425.20',
            'price_snapshot' => ['final_amount' => '4425.20'],
            'commercial_price_snapshot' => $price];
        $draft = ['evaluation_policy' => ['selected_sections' => ['finishing']], 'audit_review_items' => [
            ['finding_id' => 'audit:zero', 'item_key' => 'floor', 'type' => 'suspicious_zero', 'reason' => 'Итог ранее отсутствовал.'],
            ['finding_id' => 'audit:quantity', 'item_key' => 'floor', 'type' => 'quantity_mismatch', 'reason' => 'Проверить исходный объём.'],
        ], 'local_estimates' => [['key' => 'finish_finishing', 'sections' => [['work_items' => [$item]]]]]];
        $builder = new UniversalEvaluationBuilder;
        $snapshot = new ProjectModelSnapshot([], [], [], []);
        $result = $builder->build($draft, $snapshot);
        self::assertSame(['corrected_by_commercial_pricing'], array_column($result['audit_dispositions'], 'status'));
        self::assertSame('4425.20', $result['scenarios'][0]['positions'][0]['total_cost']);
        self::assertSame($price['source_hash'], $result['audit_dispositions'][0]['source_hash']);
        self::assertSame('4425.20', $result['audit_dispositions'][0]['calculated_amount']);
        self::assertCount(1, array_filter($result['missing_requirements'], static fn (array $row): bool => $row['requirement'] === 'audit_finding'));
        self::assertCount(2, $draft['audit_review_items']);
        foreach ([['pricing_status' => 'not_calculated', 'total_cost' => null, 'unit_price' => null, 'pricing_blocker' => 'price_unknown'],
            ['total_cost' => '0.00'], ['total_cost' => '4425.21'], ['unit_price' => '521.00']] as $change) {
            $draft['local_estimates'][0]['sections'][0]['work_items'][0] = [...$item, ...$change];
            $recalculated = $builder->build($draft, $snapshot);
            self::assertSame('4425.20', $recalculated['scenarios'][0]['positions'][0]['total_cost']);
            self::assertSame('4425.20', $recalculated['audit_dispositions'][0]['calculated_amount']);
        }
        $changes = [['quantity' => null], ['quantity' => '0']];
        foreach ([['verified' => false], ['applicable' => false], ['stale' => true], ['source_type' => 'manual'],
            ['source_reference' => ''], ['source_hash' => 'invalid'], ['unit' => 'm3'], ['as_of_date' => 'invalid'],
            ['conditions_missing' => ['delivery_cost']], ['vat_mode' => null]] as $change) {
            $changes[] = ['commercial_price_snapshot' => [...$price, ...$change]];
        }
        foreach ($changes as $change) {
            $draft['local_estimates'][0]['sections'][0]['work_items'][0] = [...$item, ...$change];
            $blocked = $builder->build($draft, $snapshot);
            self::assertSame([], $blocked['audit_dispositions']);
            self::assertCount(2, array_filter($blocked['missing_requirements'], static fn (array $row): bool => $row['requirement'] === 'audit_finding'));
        }
        $draft['local_estimates'][0]['sections'][0]['work_items'][0] = [...$item,
            'commercial_price_snapshot' => [...$price, 'stale' => true, 'stale_accepted' => true]];
        $acceptedStale = $builder->build($draft, $snapshot);
        self::assertSame(['corrected_by_commercial_pricing'], array_column($acceptedStale['audit_dispositions'], 'status'));
        self::assertSame('4425.20', $acceptedStale['audit_dispositions'][0]['calculated_amount']);
        $item['quantity'] = '1';
        $item['unit_price'] = $item['commercial_price_snapshot']['unit_price'] = '10.005';
        $item['total_cost'] = '10.01';
        $draft['local_estimates'][0]['sections'][0]['work_items'][0] = $item;
        $rounded = $builder->build($draft, $snapshot);
        self::assertSame(['corrected_by_commercial_pricing'], array_column($rounded['audit_dispositions'], 'status'));
        self::assertSame('10.01', $rounded['scenarios'][0]['positions'][0]['total_cost']);
    }

    public function test_zero_price_reconciliation_uses_the_full_selected_scope_for_linked_delivery(): void
    {
        $entity = new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity('room:1', 1, 2, 3,
            'sha256:'.str_repeat('a', 64), 'room', 'room:1');
        $price = ['source_type' => 'catalog', 'source_reference' => 'catalog:6', 'source_hash' => str_repeat('a', 64),
            'unit_price' => '520.00', 'unit' => 'm2', 'currency' => 'RUB', 'as_of_date' => '2026-10-16',
            'verified' => true, 'applicable' => true, 'stale' => false, 'conditions_missing' => ['delivery_cost'],
            'vat_mode' => 'excluded', 'vat_rate' => '22', 'delivery_included' => false, 'delivery_work_item_key' => 'delivery'];
        $floor = ['key' => 'floor', 'name' => 'Пол', 'unit' => 'm2', 'quantity' => '8.51',
            'metadata' => ['scope_entity_id' => $entity->id, 'requirement_key' => 'finishing'],
            'commercial_price_snapshot' => $price];
        $delivery = ['key' => 'delivery', 'name' => 'Доставка', 'unit' => 'pcs', 'quantity' => '1',
            'metadata' => ['scope_entity_id' => $entity->id, 'requirement_key' => 'delivery'],
            'commercial_price_snapshot' => [...$price, 'source_reference' => 'catalog:7', 'source_hash' => str_repeat('b', 64),
                'unit_price' => '40.00', 'unit' => 'pcs', 'conditions_missing' => [], 'vat_mode' => 'not_applicable',
                'vat_rate' => null, 'delivery_included' => true, 'delivery_work_item_key' => null]];
        $draft = ['evaluation_policy' => ['selected_sections' => ['finishing']], 'audit_review_items' => [
            ['finding_id' => 'audit:zero', 'item_key' => 'floor', 'type' => 'suspicious_zero', 'reason' => 'Итог ранее отсутствовал.'],
        ], 'local_estimates' => [['key' => 'finish_finishing', 'sections' => [['work_items' => [$floor, $delivery]]]]]];
        $builder = new UniversalEvaluationBuilder;
        $snapshot = new ProjectModelSnapshot([$entity], [], [], []);
        $result = $builder->build($draft, $snapshot);
        self::assertSame([], $result['scenarios'][0]['price_conditions_unresolved']);
        self::assertSame(['corrected_by_commercial_pricing'], array_column($result['audit_dispositions'], 'status'));
        self::assertSame('4425.20', $result['audit_dispositions'][0]['calculated_amount']);
        self::assertSame('5438.74', $result['scenarios'][0]['total_by_currency']['RUB']);
        $invalidDeliveries = [null, [...$delivery, 'quantity' => null],
            [...$delivery, 'metadata' => ['requirement_key' => 'delivery']],
            [...$delivery, 'commercial_price_snapshot' => [...$delivery['commercial_price_snapshot'], 'verified' => false]],
            [...$delivery, 'commercial_price_snapshot' => [...$delivery['commercial_price_snapshot'], 'currency' => 'USD']],
            [...$delivery, 'commercial_price_snapshot' => [...$delivery['commercial_price_snapshot'], 'vat_mode' => null]],
        ];
        foreach ($invalidDeliveries as $invalidDelivery) {
            $draft['local_estimates'][0]['sections'][0]['work_items'] = $invalidDelivery === null ? [$floor] : [$floor, $invalidDelivery];
            $blocked = $builder->build($draft, $snapshot);
            self::assertSame([], $blocked['audit_dispositions']);
            self::assertContains('audit_finding', array_column($blocked['missing_requirements'], 'requirement'));
            self::assertFalse($blocked['can_confirm_for_apply']);
        }
    }

    public function test_unresolved_source_rows_are_visible_without_becoming_positions_or_changing_selected_scope(): void
    {
        $draft = ['evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['finishing']],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['work_items' => [['key' => 'floor', 'name' => 'Пол', 'quantity' => null, 'unit' => 'm2']]]]]]];
        $snapshot = new ProjectModelSnapshot([], [], [], []);
        $builder = new UniversalEvaluationBuilder;
        $baseline = $builder->build($draft, $snapshot);
        $draft['local_estimates'][] = ['key' => 'document_review', 'coverage_warnings' => [[
            'quantity_key' => 'document_source:row-1', 'reason' => 'work_scope_review_required', 'message' => 'Неопределённая работа: ведомость.pdf, строка 7; не включена в стоимость.',
        ]], 'sections' => [['work_items' => [['key' => 'unknown-row', 'item_type' => 'review_note', 'quantity' => '10']]]]];
        $actual = $builder->build($draft, $snapshot);
        self::assertSame([['key' => 'document_source:row-1', 'message' => $draft['local_estimates'][1]['coverage_warnings'][0]['message']]], $actual['scope_exclusions']);
        unset($actual['scope_exclusions']);
        self::assertSame($baseline, $actual);
    }

    public function test_unconfirmed_source_does_not_disappear_from_scope_checks_or_become_a_priced_position(): void
    {
        $draft = ['evaluation_policy' => ['mode' => 'universal', 'selected_sections' => ['finishing']],
            'local_estimates' => [['key' => 'finishing', 'sections' => [['work_items' => [['key' => 'floor', 'name' => 'Пол', 'quantity' => '20', 'unit' => 'm2']]]]]],
            'estimate_composition' => ['request_info' => [['reason' => 'estimate_composer_work_context_unconfirmed', 'work_name' => 'Окраска стен']]]];
        $result = (new UniversalEvaluationBuilder)->build($draft, new ProjectModelSnapshot([], [], [], []));
        self::assertContains('work_source_review', array_column($result['missing_requirements'], 'requirement'));
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertCount(1, $result['scenarios'][0]['positions']);
        self::assertContains('confirm_work_source', array_column($result['priority_questions'], 'next_action'));
        $draft['estimate_composition']['request_info'][0]['reason'] = 'estimate_composer_work_section_not_selected';
        $outsideScope = (new UniversalEvaluationBuilder)->build($draft, new ProjectModelSnapshot([], [], [], []));
        self::assertNotContains('work_source_review', array_column($outsideScope['missing_requirements'], 'requirement'));
        $draft['estimate_composition']['request_info'][0]['reason'] = 'estimate_composer_physical_duplicate_covered';
        $covered = (new UniversalEvaluationBuilder)->build($draft, new ProjectModelSnapshot([], [], [], []));
        self::assertNotContains('work_source_review', array_column($covered['missing_requirements'], 'requirement'));
        $draft['estimate_composition']['request_info'][0]['reason'] = 'estimate_composer_supplementary_duplicate';
        $ambiguous = (new UniversalEvaluationBuilder)->build($draft, new ProjectModelSnapshot([], [], [], []));
        self::assertContains('work_source_review', array_column($ambiguous['missing_requirements'], 'requirement'));
    }

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

    public function test_unconfirmed_model_reserve_and_forged_manual_choice_remain_visible_blockers(): void
    {
        foreach ([['contingency_percent' => '7'], ['evaluation_policy' => ['contingency_selection' => ['kind' => 'fixed', 'currency' => 'RUB',
            'quoted_amount' => '120', 'vat_mode' => 'included', 'vat_rate' => '20', 'basis' => 'Без канонического факта.',
            'fact_id' => 'fact:'.str_repeat('a', 64), 'decision_id' => 'decision:'.str_repeat('b', 64)]]]] as $change) {
            $draft = ['evaluation_policy' => ['mode' => 'universal', 'profile_id' => 'construction', 'selected_sections' => ['finishing']],
                'local_estimates' => [['key' => 'finishing', 'sections' => [['work_items' => [['key' => 'floor', 'name' => 'Пол', 'quantity' => null, 'unit' => 'm2']]]]]]];
            $draft = array_replace_recursive($draft, $change);
            $result = (new UniversalEvaluationBuilder)->build($draft, new ProjectModelSnapshot([], [], [], []));
            self::assertContains('contingency_not_confirmed', array_column($result['missing_requirements'], 'reason'));
            self::assertContains('contingency_confirmation', array_column($result['priority_questions'], 'key'));
            self::assertArrayNotHasKey('contingency', $result['scenarios'][0]);
            self::assertFalse($result['can_confirm_for_apply']);
        }
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
        $item['pricing_status'] = 'calculated';
        unset($item['pricing_blocker']);
        $item['price_snapshot']['final_amount'] = '20.00';
        $item['price_snapshot']['coefficients']['resource_evidence'][0] = ['source_type' => 'normative_rate_base',
            'final_amount' => '20.00', 'source_reference' => 'normative_rates:1', 'version_id' => 1, 'coefficients' => ['base_year' => 2001]];
        $draft['local_estimates'][0]['sections'][0]['work_items'] = [$item];
        $result = (new UniversalEvaluationBuilder)->build($draft, new ProjectModelSnapshot([], [], [], []));
        $snapshot = $result['scenarios'][0]['positions'][0]['price_snapshot'];
        self::assertNull($snapshot['price_version_id']);
        self::assertNull($snapshot['price_period_id']);
        self::assertNull($snapshot['level']);
        self::assertSame(2001, $snapshot['resource_sources'][0]['coefficients']['base_year']);
    }
}
