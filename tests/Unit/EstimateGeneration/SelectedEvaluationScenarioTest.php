<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScenarioEstimateCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario;
use PHPUnit\Framework\TestCase;

final class SelectedEvaluationScenarioTest extends TestCase
{
    public function test_context_presentation_does_not_invalidate_a_preexisting_confirmation_hash(): void
    {
        $result = $this->evaluationFixture();
        $selector = new SelectedEvaluationScenario;
        $legacy = $selector->select($result, 'base');
        self::assertSame('3de23fc1764d7e846eddd750ff51bd7a3c79380a11c21d8be15e0803f739079c', $legacy['scenario_hash']);
        $result['scenarios'][1]['positions'][0]['proposed_entity_context'] = ['status' => 'proposed',
            'entity_id' => 'room:1', 'entity_name' => 'Кухня'];
        self::assertSame($legacy['scenario_hash'], $selector->select($result, 'base')['scenario_hash']);
        $result['scenarios'][1]['positions'][0]['proposed_entity_context']['entity_name'] = 'Другое название';
        self::assertSame($legacy['scenario_hash'], $selector->select($result, 'base')['scenario_hash']);
        $result['scenarios'][1]['positions'][0]['quantity'] = '21';
        $result = [...(new ScenarioEstimateCalculator)->calculate($result['scenarios'], ['finishing'], [], true), 'profile_version' => 'test:v1'];
        self::assertNotSame($legacy['scenario_hash'], $selector->select($result, 'base')['scenario_hash']);
    }

    public function test_one_selected_case_preserves_assumptions_and_does_not_sum_other_cases(): void
    {
        $result = $this->evaluationFixture();
        $selected = (new SelectedEvaluationScenario)->select($result, 'base');
        self::assertSame(['RUB' => '2000.00'], $selected['total_by_currency']);
        self::assertSame('assumed', $selected['scenario']['positions'][0]['quantity_status']);
        self::assertSame(['Явно заданный сценарный объём'], $selected['assumptions']);
        self::assertCount(1, $selected['scenario']['positions']);
        self::assertSame($selected, (new SelectedEvaluationScenario)->select($result, 'base'));
    }

    public function test_a_different_unpriced_case_does_not_block_a_complete_selected_case(): void
    {
        $result = $this->evaluationFixture();
        $scenarios = $result['scenarios'];
        $scenarios[2]['positions'][0]['price_snapshot'] = null;
        $result = [...(new ScenarioEstimateCalculator)->calculate($scenarios, ['finishing'], [], true), 'profile_version' => 'test:v1'];
        self::assertFalse($result['can_confirm_for_apply']);
        self::assertSame(['RUB' => '2000.00'], (new SelectedEvaluationScenario)->select($result, 'base')['total_by_currency']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCases')]
    public function test_incomplete_or_tampered_cases_are_rejected(string $case): void
    {
        $result = $this->evaluationFixture();
        if ($case === 'tampered_total') {
            $result['scenarios'][1]['total_by_currency']['RUB'] = '0.00';
        } else {
            $scenarios = $result['scenarios'];
            if ($case === 'quantity') {
                $scenarios[1]['positions'][0]['quantity'] = null;
            } elseif ($case === 'price') {
                $scenarios[1]['positions'][0]['price_snapshot'] = null;
            } elseif ($case === 'currency') {
                $scenarios[1]['positions'][0]['price_snapshot']['currency'] = 'USD';
            }
            $result = [...(new ScenarioEstimateCalculator)->calculate($scenarios, ['finishing'], $case === 'requirements' ? [['requirement' => 'preparation']] : [], $case !== 'scope'), 'profile_version' => 'test:v1'];
        }
        $this->expectException(\InvalidArgumentException::class);
        (new SelectedEvaluationScenario)->select($result, $case === 'missing' ? 'invalid' : 'base');
    }

    public static function invalidCases(): array
    {
        return array_map(static fn (string $case): array => [$case], ['quantity', 'price', 'currency', 'scope', 'requirements', 'tampered_total', 'missing']);
    }

    private function evaluationFixture(): array
    {
        $scenarios = [];
        foreach (['minimum' => '12', 'base' => '20', 'maximum' => '30'] as $id => $quantity) {
            $scenarios[] = ['id' => $id, 'assumptions' => ['Явно заданный сценарный объём'], 'positions' => [[
                'key' => 'floor', 'section' => 'finishing', 'unit' => 'm2', 'quantity' => $quantity, 'quantity_status' => 'assumed',
                'price_snapshot' => ['unit_price' => '100', 'unit' => 'm2', 'currency' => 'RUB', 'verified' => true,
                    'source_type' => 'catalog', 'source_reference' => 'catalog:1', 'as_of_date' => '2026-10-12', 'vat_mode' => 'not_applicable'],
            ]]];
        }

        return [...(new ScenarioEstimateCalculator)->calculate($scenarios, ['finishing'], [], true), 'profile_version' => 'test:v1'];
    }
}
