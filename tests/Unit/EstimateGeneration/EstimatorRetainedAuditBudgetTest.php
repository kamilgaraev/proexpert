<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorRetainedAuditBudget;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EstimatorRetainedAuditBudgetTest extends TestCase
{
    public function test_a_budget_that_fits_one_call_but_not_the_whole_initial_pass_is_rejected(): void
    {
        [$plan, $preview] = $this->fixture(2999999);
        $this->expectExceptionMessage('estimator_retained_audit_budget_insufficient');

        EstimatorRetainedAuditBudget::forecast($plan, $plan, $preview);
    }

    public function test_exact_initial_pass_budget_is_accepted_without_promising_correction_or_completion(): void
    {
        [$plan, $preview] = $this->fixture(3000000);
        $forecast = EstimatorRetainedAuditBudget::forecast($plan, $plan, $preview);

        self::assertSame(2, $forecast['initial_audit_parts']);
        self::assertSame(3000000, $forecast['maximum_initial_audit_cost_micro_rub']);
        self::assertSame(3000000, $forecast['available_provider_cost_micro_rub']);
        self::assertFalse($forecast['corrections_included']);
        self::assertFalse($forecast['completion_guaranteed']);
        self::assertSame('artifact:house', $forecast['source_artifact_version']);
    }

    public function test_included_document_cost_is_not_available_to_spend_on_audit(): void
    {
        [$plan, $preview] = $this->fixture(4000000);
        $plan['document_costs'] = ['cost_micro_rub' => 1000001];
        $this->expectExceptionMessage('estimator_retained_audit_budget_insufficient');

        EstimatorRetainedAuditBudget::forecast($plan, $plan, $preview);
    }

    #[DataProvider('unavailableCases')]
    public function test_unproven_or_differently_frozen_projection_cannot_approve_a_budget(string $case): void
    {
        [$plan, $preview] = $this->fixture(3000000);
        $source = $plan;
        match ($case) {
            'policy' => $source['text_payload_policy']['contract'] = 'older-policy',
            'audit_input' => $source['roles']['estimate_audit']['input_ceiling'] = 32768,
            'correction_input' => $source['roles']['estimate_composer_correction']['input_ceiling'] = 32768,
            'work_limit' => $source['work_item_limit'] = 64,
            'artifact' => $preview['artifact_version'] = 'artifact:stale',
            'unavailable' => $preview['available'] = false,
            'split_required' => $preview['audit_preview']['fits'] = false,
            'no_parts' => $preview['audit_preview']['parts'] = [],
        };
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('estimator_retained_audit_preflight_unavailable');

        EstimatorRetainedAuditBudget::forecast($plan, $source, $preview);
    }

    public static function unavailableCases(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'policy', 'audit_input', 'correction_input', 'work_limit', 'artifact', 'unavailable', 'split_required', 'no_parts',
        ]);
    }

    public function test_large_integer_cost_cannot_overflow_into_an_admissible_budget(): void
    {
        [$plan, $preview] = $this->fixture(PHP_INT_MAX);
        $plan['roles']['estimate_audit']['maximum_cost_micro_rub'] = PHP_INT_MAX;
        $this->expectExceptionMessage('estimator_retained_audit_budget_insufficient');

        EstimatorRetainedAuditBudget::forecast($plan, $plan, $preview);
    }

    private function fixture(int $maximum): array
    {
        $plan = ['retained_pipeline_prefix' => ['source_operation_id' => 'source-operation',
            'stages' => ['build_draft' => ['output_version' => 'artifact:house']]],
            'text_payload_policy' => ['contract' => 'frozen-policy'], 'work_item_limit' => 32, 'audit_part_limit' => 32,
            'maximum_provider_cost_micro_rub' => $maximum,
            'roles' => [
                'estimate_audit' => ['input_ceiling' => 65536, 'output_ceiling' => 8192, 'maximum_cost_micro_rub' => 1500000],
                'estimate_composer_correction' => ['input_ceiling' => 65536, 'output_ceiling' => 8192],
            ]];

        return [$plan, ['available' => true, 'artifact_version' => 'artifact:house',
            'audit_preview' => ['fits' => true, 'parts' => [['id' => 'one'], ['id' => 'two']]]]];
    }
}
