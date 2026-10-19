<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorGenerationCycleBudget;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EstimatorGenerationCycleBudgetTest extends TestCase
{
    public function test_a_partial_ceiling_cannot_approve_the_full_generation_cycle(): void
    {
        $this->expectExceptionMessage('estimator_generation_cycle_budget_insufficient');

        EstimatorGenerationCycleBudget::forecast('generation', $this->plan(1080000));
    }

    public function test_the_exact_envelope_keeps_every_frozen_role_and_retry_without_a_replay_discount(): void
    {
        $plan = $this->plan(51000000);
        $forecast = EstimatorGenerationCycleBudget::forecast('generation', $plan);

        self::assertSame('estimator-generation-cycle-preflight:v1', $forecast['contract_version']);
        self::assertSame(51000000, $forecast['maximum_remaining_cycle_cost_micro_rub']);
        self::assertSame(51000000, $forecast['maximum_total_provider_cost_micro_rub']);
        self::assertSame(0, $forecast['included_document_cost_micro_rub']);
        self::assertSame(51, $forecast['maximum_calls']);
        self::assertSame(27000000, $forecast['role_budgets']['estimate_audit']['maximum_cost_micro_rub']);
        self::assertSame(51, array_sum(array_column($forecast['role_budgets'], 'maximum_calls')));
        self::assertTrue($forecast['full_cycle_fits']);
        self::assertTrue($forecast['corrections_included']);
        self::assertTrue($forecast['retries_included']);
        self::assertFalse($forecast['completion_guaranteed']);
        self::assertSame(0, $forecast['replay_discount_micro_rub']);
        self::assertArrayNotHasKey('qa_expense_budget', $forecast);
        self::assertArrayNotHasKey('remaining_units_minor', $forecast);
        self::assertSame($this->plan(51000000), $plan);
    }

    public function test_carried_document_cost_is_included_once_and_cannot_fund_new_calls(): void
    {
        $plan = $this->plan(52000000);
        $plan['document_costs'] = ['cost_micro_rub' => 1000000];
        $forecast = EstimatorGenerationCycleBudget::forecast('generation', $plan);
        self::assertSame(51000000, $forecast['maximum_remaining_cycle_cost_micro_rub']);
        self::assertSame(52000000, $forecast['maximum_total_provider_cost_micro_rub']);
        self::assertSame(1000000, $forecast['included_document_cost_micro_rub']);
        $plan['maximum_provider_cost_micro_rub']--;
        $this->expectExceptionMessage('estimator_generation_cycle_budget_insufficient');

        EstimatorGenerationCycleBudget::forecast('generation', $plan);
    }

    public function test_a_complete_legacy_plan_uses_its_exact_frozen_role_allowances(): void
    {
        $plan = $this->plan(51000000);
        $plan['contract_version'] = 'estimator-processing-plan:v1';
        $before = $plan;
        $forecast = EstimatorGenerationCycleBudget::forecast('generation', $plan);

        self::assertSame(51000000, $forecast['maximum_remaining_cycle_cost_micro_rub']);
        self::assertSame(51, $forecast['maximum_calls']);
        self::assertSame($before, $plan);
    }

    public function test_an_overflowing_role_envelope_cannot_become_an_admissible_budget(): void
    {
        $plan = $this->plan(PHP_INT_MAX);
        $plan['roles']['estimate_audit']['maximum_cost_micro_rub'] = PHP_INT_MAX;
        $this->expectExceptionMessage('estimator_generation_cycle_budget_insufficient');

        EstimatorGenerationCycleBudget::forecast('generation', $plan);
    }

    #[DataProvider('invalidPlans')]
    public function test_malformed_frozen_plans_are_not_certified(string $case): void
    {
        $plan = $this->plan(51000000);
        match ($case) {
            'contract' => $plan['contract_version'] = 'older-plan',
            'missing_role' => $plan['roles']['estimate_composer_correction'] = null,
            'unknown_role' => $plan['roles']['unbounded_tool'] = $plan['roles']['vision'],
            'zero_calls' => $plan['roles']['vision']['max_calls'] = 0,
            'string_calls' => $plan['roles']['vision']['max_calls'] = '15',
            'large_calls' => $plan['roles']['vision']['max_calls'] = 10001,
            'missing_cost' => $plan['roles']['vision']['maximum_cost_micro_rub'] = null,
            'negative_cost' => $plan['roles']['vision']['maximum_cost_micro_rub'] = -1,
            'calls_mismatch' => $plan['maximum_calls'] = 50,
            'document_cost' => $plan['document_costs'] = ['cost_micro_rub' => '1'],
            'document_shape' => $plan['document_costs'] = 'invalid',
            'document_exceeds_cap' => $plan['document_costs'] = ['cost_micro_rub' => 51000001],
            'maximum' => $plan['maximum_provider_cost_micro_rub'] = '51000000',
        };
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('estimator_generation_cycle_preflight_unavailable');

        EstimatorGenerationCycleBudget::forecast('generation', $plan);
    }

    public static function invalidPlans(): array
    {
        return array_map(static fn (string $case): array => [$case], ['contract', 'missing_role', 'unknown_role',
            'zero_calls', 'string_calls', 'large_calls', 'missing_cost', 'negative_cost', 'calls_mismatch', 'document_cost',
            'document_shape', 'document_exceeds_cap', 'maximum']);
    }

    public function test_other_operation_kinds_and_retained_cycles_keep_their_own_contract(): void
    {
        foreach (['documents', 'dialogue', 'analysis', 'rebuild'] as $kind) {
            self::assertNull(EstimatorGenerationCycleBudget::forecast($kind, []));
        }
        self::assertNull(EstimatorGenerationCycleBudget::forecast('generation', ['retained_pipeline_prefix' => []]));
    }

    private function plan(int $maximum): array
    {
        $calls = ['vision' => 15, 'ocr' => 3, 'project_synthesis' => 3, 'estimate_composition' => 3,
            'estimate_audit' => 18, 'estimate_composer_correction' => 6, 'rerank' => 3];
        $roles = [];
        foreach ($calls as $role => $count) {
            $roles[$role] = ['max_calls' => $count, 'maximum_cost_micro_rub' => $role === 'estimate_audit' ? 1500000 : 727272];
        }
        $roles['estimate_composer_correction']['maximum_cost_micro_rub'] = 727276;

        return ['contract_version' => 'estimator-processing-plan:v2', 'maximum_calls' => 51,
            'maximum_provider_cost_micro_rub' => $maximum, 'roles' => $roles];
    }
}
