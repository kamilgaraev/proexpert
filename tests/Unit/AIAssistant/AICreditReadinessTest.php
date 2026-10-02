<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\Services\Credits\AICreditReadinessService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AICreditReadinessTest extends TestCase
{
    public function test_actual_report_accounts_for_background_and_errors_separately_from_estimates(): void
    {
        $report = $this->service()->evaluate($this->traces());
        self::assertTrue($report['ready_for_approval']);
        self::assertSame(240, $report['scenario_count']);
        self::assertSame(80, $report['normal_spend_minor']['within_50_150']);
        self::assertSame(6_000, $report['errors_cost_micro_rub']);
        self::assertSame(2_418_000, $report['external_assistant_cost_micro_rub']);
        self::assertSame(999_000_000, $report['costs_micro_rub']['estimate_generation']);
        self::assertSame(2_400_000, $report['successful_billable_cost_micro_rub']);
    }

    public function test_synthetic_results_never_qualify_and_cannot_be_approved(): void
    {
        $traces = $this->traces();
        $traces['stage'] = 'synthetic';
        $report = $this->service()->evaluate($traces);
        self::assertFalse($report['ready_for_approval']);
        self::assertContains('synthetic_traces_not_production_evidence', $report['reasons']);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->approveReport($report, 'test-secret', strtotime('2026-09-29T01:00:00Z'));
    }

    public function test_missing_cost_coverage_and_failed_security_block_readiness(): void
    {
        $traces = $this->traces();
        $traces['assistant_revenue_minor'] = 1;
        $traces['cost_coverage']['index'] = false;
        $traces['scenarios'][0]['assertions']['leak']['observed'] = 'other_organization_amount';
        $report = $this->service()->evaluate($traces);
        self::assertFalse($report['ready_for_approval']);
        self::assertContains('missing_cost_coverage_index', $report['reasons']);
        self::assertContains('business_or_security_assertions_failed', $report['reasons']);
        self::assertContains('external_cost_exceeds_30_percent_of_assistant_revenue', $report['reasons']);
        self::assertSame(['financial-1-1:leak'], $report['quality_failures']);
    }

    public function test_failed_call_cost_is_not_charged_and_successful_calls_are_rounded_once(): void
    {
        $traces = $this->traces();
        $scenario = &$traces['scenarios'][0];
        $scenario['provider_calls'] = [$this->call('assistant', true, 40_001), $this->call('assistant', true, 40_001), $this->call('assistant', false, 400_000)];
        $scenario['successful_cost_micro_rub'] = 80_002;
        $report = $this->service()->evaluate($traces);
        self::assertTrue($report['ready_for_approval']);
        $scenario['charged_minor'] = 100;
        self::assertContains('charge_or_approval_mismatch:financial-1-1', $this->service()->evaluate($traces)['reasons']);
    }

    public function test_approval_is_bound_to_policy_report_and_expiry(): void
    {
        $service = $this->service();
        $now = strtotime('2026-09-29T01:00:00Z');
        $approval = $service->approveReport($service->evaluate($this->traces()), 'test-secret', $now);
        self::assertTrue($service->verifyApproval($approval, 'test-secret', $now));
        $serialized = json_encode($approval, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
        self::assertTrue($service->verifyApproval(json_decode($serialized, true, 512, JSON_THROW_ON_ERROR), 'test-secret', $now));
        self::assertFalse($service->verifyApproval($approval, 'wrong-secret', $now));
        self::assertFalse($service->verifyApproval($approval, 'test-secret', $now + 604800));
        $newPolicy = $this->policy();
        $newPolicy['rub_per_unit'] = 0.36;
        $newPolicy['price_version'] = 2;
        self::assertFalse((new AICreditReadinessService($newPolicy))->verifyApproval($approval, 'test-secret', $now));
        $approval['report']['scenario_count'] = 999;
        self::assertFalse($service->verifyApproval($approval, 'test-secret', $now));
    }

    public function test_changed_policy_charge_step_and_price_are_used(): void
    {
        $policy = $this->policy();
        $policy['price_version'] = 2;
        $policy['rub_per_unit'] = 0.36;
        $policy['charge_step_minor'] = 25;
        $policy['minimum_units_minor'] = 25;
        $service = new AICreditReadinessService($policy);
        $traces = $this->traces();
        $traces['policy_hash'] = $service->policyFingerprint();
        foreach ($traces['scenarios'] as &$scenario) {
            $scenario['charged_minor'] = 25;
        }
        unset($scenario);
        self::assertTrue($service->evaluate($traces)['ready_for_approval']);
    }

    public function test_duplicate_inputs_and_absent_evidence_cannot_inflate_coverage(): void
    {
        $traces = $this->traces();
        foreach ($traces['scenarios'] as &$scenario) {
            $scenario['input'] = ['message' => 'Одинаковый вопрос', 'request_id' => $scenario['id']];
        }
        unset($scenario);
        unset($traces['scenarios'][0]['assertions']['rights']['evidence_sha256']);
        $report = $this->service()->evaluate($traces);
        self::assertContains('minimum_200_distinct_scenarios_required', $report['reasons']);
        self::assertContains('missing_verification_evidence:financial-1-1:rights', $report['reasons']);
    }

    public function test_numeric_strings_and_negative_cost_are_rejected(): void
    {
        $traces = $this->traces();
        $traces['scenarios'][0]['provider_calls'][0]['cost_micro_rub'] = -1;
        $this->expectException(InvalidArgumentException::class);
        $this->service()->evaluate($traces);
    }

    public function test_dataset_contains_240_unexecuted_cases_and_no_claimed_model_quality(): void
    {
        $cases = require dirname(__DIR__, 2).'/Fixtures/AIAssistant/shadow-scenarios.php';
        self::assertCount(240, $cases);
        self::assertCount(20, array_unique(array_column($cases, 'category')));
        foreach ($cases as $case) {
            self::assertSame([], $case['provider_calls']);
            self::assertSame('not_run', $case['assertions']['business_quality']['observed']);
        }
    }

    public function test_shadow_quality_and_pricing_projection_never_invent_paid_revenue(): void
    {
        $report = $this->service()->evaluate($this->shadowTraces());
        self::assertTrue($report['quality_ready']);
        self::assertFalse($report['ready_for_approval']);
        self::assertSame(array_fill(0, 80, 0), $report['actual_normal_spend_minor']);
        self::assertSame(50, $report['normal_spend_minor']['min']);
        self::assertNull($report['pricing_projection']['actual_revenue_minor']);
        self::assertFalse($report['pricing_projection']['eligible_for_approval']);
        self::assertSame(399000, $report['pricing_projection']['offers']['subscription']['amount_minor']);
        self::assertSame(500000, $report['pricing_projection']['offers']['subscription']['units_minor']);
        self::assertContains('actual_assistant_revenue_evidence_required', $report['reasons']);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->approveReport($report, 'test-secret');
    }

    public function test_shadow_immutable_projection_exposes_budget_overrun_and_actual_zero_independently(): void
    {
        $traces = $this->shadowTraces();
        $traces['scenarios'][0]['pricing_snapshot']['minimum_minor'] = 500;
        $traces['scenarios'][0]['approved_minor'] = 100;
        $traces['scenarios'][0]['estimated_minor'] = 100;
        $traces['scenarios'][0]['projected_charge_minor'] = 500;
        $report = $this->service()->evaluate($traces);
        self::assertFalse($report['quality_ready']);
        self::assertContains('projected_charge_exceeds_approved_budget:financial-1-1', $report['quality_reasons']);
        self::assertContains('financial-1-1:approved_budget', $report['quality_failures']);
        $traces['scenarios'][0]['charged_minor'] = 100;
        self::assertContains('charge_or_approval_mismatch:financial-1-1', $this->service()->evaluate($traces)['quality_reasons']);
        $traces['scenarios'][0]['charged_minor'] = 0;
        $traces['scenarios'][0]['projected_charge_minor'] = 50;
        self::assertContains('projected_charge_mismatch:financial-1-1', $this->service()->evaluate($traces)['quality_reasons']);
    }

    public function test_domain_contract_requires_its_fixed_categories_and_allows_supplemental_workflow_evidence(): void
    {
        $traces = $this->domainTraces();
        $traces['scenarios'][] = $this->shadowTraces()['scenarios'][0];
        $report = $this->service()->evaluate($traces);
        self::assertTrue($report['quality_ready']);
        self::assertSame('domain_v1', $report['scenario_contract']);
        self::assertSame(253, $report['distinct_input_count']);
        self::assertArrayHasKey('financial', $report['categories']);
        $traces['scenarios'] = array_values(array_filter($traces['scenarios'], static fn (array $scenario): bool => $scenario['category'] !== 'crm_owner'));
        $report = $this->service()->evaluate($traces);
        self::assertFalse($report['quality_ready']);
        self::assertContains('insufficient_category_coverage:crm_owner', $report['quality_reasons']);
    }

    public function test_unknown_scenario_contract_is_rejected(): void
    {
        $traces = $this->domainTraces();
        $traces['scenario_contract'] = 'domain_unreviewed';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_scenario_contract');
        $this->service()->evaluate($traces);
    }

    public function test_signed_domain_report_verifies_its_own_category_contract(): void
    {
        $traces = $this->domainTraces();
        $traces['assistant_revenue_minor'] = 10000;
        $traces['revenue_evidence'] = ['source' => 'settled_assistant_billing_ledger', 'verified' => true,
            'amount_minor' => 10000, 'period' => $traces['period'], 'settled_payment_ids' => ['unit-only-payment'],
            'verifier' => 'unit_test_only_ledger', 'evidence_sha256' => hash('sha256', 'unit-only-ledger')];
        $service = $this->service();
        $now = strtotime('2026-09-29T01:00:00Z');
        $approval = $service->approveReport($service->evaluate($traces), 'unit-only-signing-key', $now);
        self::assertSame('domain_v1', $approval['report']['scenario_contract']);
        self::assertTrue($service->verifyApproval($approval, 'unit-only-signing-key', $now));
    }

    public function test_domain_categories_cannot_bypass_legacy_contract_without_explicit_version(): void
    {
        $traces = $this->domainTraces();
        unset($traces['scenario_contract']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_scenario_metadata');
        $this->service()->evaluate($traces);
    }

    public function test_partial_run_blocks_quality_and_signing_even_when_all_other_checks_pass(): void
    {
        $traces = $this->traces();
        $traces['partial_run'] = true;
        $report = $this->service()->evaluate($traces);
        self::assertFalse($report['quality_ready']);
        self::assertFalse($report['ready_for_approval']);
        self::assertTrue($report['partial_run']);
        self::assertContains('partial_run_not_eligible', $report['quality_reasons']);
        $this->expectException(InvalidArgumentException::class);
        $this->service()->approveReport($report, 'unit-test-only-key');
    }

    public function test_distinct_profiles_and_request_ids_do_not_inflate_domain_input_coverage(): void
    {
        $traces = $this->domainTraces();
        foreach ($traces['scenarios'] as &$scenario) {
            unset($scenario['input']['context']['entity_id']);
        }
        unset($scenario);
        $report = $this->service()->evaluate($traces);
        self::assertSame(84, $report['distinct_input_count']);
        self::assertContains('minimum_200_distinct_scenarios_required', $report['quality_reasons']);
    }

    private function domainTraces(): array
    {
        $traces = $this->shadowTraces();
        $template = $traces['scenarios'][0];
        $traces['scenario_contract'] = 'domain_v1';
        $traces['scenarios'] = require dirname(__DIR__, 2).'/Fixtures/AIAssistant/shadow-domain-scenarios.php';
        foreach ($traces['scenarios'] as $index => &$scenario) {
            $metadata = $scenario;
            $scenario = $template;
            foreach (['id', 'category', 'requested_profile', 'input', 'assertions'] as $key) { $scenario[$key] = $metadata[$key]; }
            $scenario['input']['context']['entity_id'] = $index + 1;
            $scenario['execution_evidence_sha256'] = hash('sha256', 'unit-only:'.$scenario['id']);
            foreach ($scenario['assertions'] as &$assertion) {
                $assertion['observed'] = $assertion['expected'];
                $assertion['verifier'] = 'unit_test_only_domain_contract';
                $assertion['evidence_sha256'] = hash('sha256', 'unit-only:'.$scenario['id']);
            }
            unset($assertion);
        }
        unset($scenario);
        return $traces;
    }

    public function test_real_server_read_and_measured_memory_zero_do_not_require_dummy_model_calls(): void
    {
        $traces = $this->shadowTraces();
        $traces['scenarios'][0]['provider_calls'] = [];
        $traces['scenarios'][0]['successful_cost_micro_rub'] = 0;
        $traces['scenarios'][0]['server_read_evidence'] = ['verified' => true, 'verifier' => 'unit_test_only_server_reader', 'evidence_sha256' => hash('sha256', 'source'), 'source_receipts' => [['project_id' => 1]]];
        $traces['background_calls'] = array_values(array_filter($traces['background_calls'], static fn (array $call): bool => $call['kind'] !== 'memory'));
        $traces['cost_coverage']['memory'] = ['mode' => 'no_external_calls', 'verified' => true, 'verifier' => 'unit_test_only_call_collector', 'evidence_sha256' => hash('sha256', 'memory'), 'observed_external_call_count' => 0, 'source_code_sha256' => hash('sha256', 'code'), 'record_snapshot_sha256' => hash('sha256', 'records')];
        self::assertTrue($this->service()->evaluate($traces)['quality_ready']);
        $traces['cost_coverage']['memory']['observed_external_call_count'] = 1;
        self::assertContains('missing_cost_coverage_memory', $this->service()->evaluate($traces)['quality_reasons']);
        $traces['cost_coverage']['memory']['observed_external_call_count'] = 0;
        unset($traces['scenarios'][0]['server_read_evidence']['source_receipts']);
        self::assertContains('completed_without_assistant_call:financial-1-1', $this->service()->evaluate($traces)['quality_reasons']);
    }

    public function test_alias_is_normalized_but_estimated_tokens_cannot_be_provider_evidence(): void
    {
        $traces = $this->shadowTraces();
        $traces['scenarios'][0]['provider_calls'][0]['model'] = 'openai/gpt-6-luna';
        $report = $this->service()->evaluate($traces);
        self::assertTrue($report['quality_ready']);
        self::assertSame(1, $report['provider_models']['gpt-6-luna']['openai/gpt-6-luna']);
        $traces['scenarios'][0]['provider_calls'][0]['usage_source'] = 'tokenizer_fallback';
        self::assertContains('unverified_provider_usage:financial-1-1', $this->service()->evaluate($traces)['quality_reasons']);
        $traces['scenarios'][0]['provider_calls'][0]['raw_model'] = 'other-model';
        self::assertContains('unexpected_raw_generative_model:financial-1-1', $this->service()->evaluate($traces)['quality_reasons']);
    }

    public function test_paid_v2_requires_matching_settled_ledger_evidence(): void
    {
        $traces = $this->shadowTraces();
        $traces['charging_mode'] = 'paid';
        $traces['assistant_revenue_minor'] = 10000;
        foreach ($traces['scenarios'] as &$scenario) { $scenario['charged_minor'] = $scenario['projected_charge_minor']; }
        unset($scenario);
        self::assertFalse($this->service()->evaluate($traces)['ready_for_approval']);
        $traces['revenue_evidence'] = ['source' => 'settled_assistant_billing_ledger', 'verified' => true, 'amount_minor' => 10000, 'period' => $traces['period'], 'settled_payment_ids' => ['unit_test_only_payment'], 'verifier' => 'unit_test_only_ledger', 'evidence_sha256' => hash('sha256', 'ledger')];
        self::assertTrue($this->service()->evaluate($traces)['ready_for_approval']);
        $traces['revenue_evidence']['amount_minor'] = 9999;
        self::assertFalse($this->service()->evaluate($traces)['ready_for_approval']);
    }

    public function test_prelaunch_projection_approves_measured_cost_without_inventing_cash(): void
    {
        $service = $this->service();
        $report = $service->evaluate($this->prelaunchTraces());
        self::assertTrue($report['quality_ready']);
        self::assertTrue($report['economics_ready']);
        self::assertTrue($report['ready_for_approval']);
        self::assertSame(0, $report['assistant_revenue_minor']);
        self::assertNull($report['external_cost_revenue_ratio']);
        self::assertSame(12000, $report['useful_projected_spend_minor']);
        self::assertSame(95_760_000, $report['projected_assistant_revenue_micro_rub']);
        self::assertSame(2_418_000 / 95_760_000, $report['external_cost_projected_revenue_ratio']);
        self::assertSame('awaiting_settled_assistant_revenue', $report['actual_revenue_monitoring']['status']);
        $now = strtotime('2026-09-29T01:00:00Z');
        $approval = $service->approveReport($report, 'unit-test-only-key', $now);
        self::assertTrue($service->verifyApproval($approval, 'unit-test-only-key', $now));
    }

    public function test_prelaunch_background_and_failed_call_cost_can_fail_economics(): void
    {
        $traces = $this->prelaunchTraces();
        $traces['background_calls'][1]['cost_micro_rub'] = 30_000_000;
        $report = $this->service()->evaluate($traces);
        self::assertTrue($report['quality_ready']);
        self::assertFalse($report['economics_ready']);
        self::assertFalse($report['ready_for_approval']);
        self::assertSame(12000, $report['useful_projected_spend_minor']);
        self::assertSame(32_415_000, $report['external_assistant_cost_micro_rub']);
        self::assertContains('external_cost_exceeds_30_percent_of_prelaunch_projection', $report['reasons']);
        $traces = $this->prelaunchTraces();
        $traces['background_calls'][3]['cost_micro_rub'] = 30_000_000;
        self::assertFalse($this->service()->evaluate($traces)['ready_for_approval']);
    }

    public function test_prelaunch_denied_and_nonuseful_answers_do_not_generate_projected_revenue(): void
    {
        $traces = $this->prelaunchTraces();
        $traces['scenarios'][0]['useful_outcome'] = false;
        $traces['scenarios'][1]['outcome'] = 'blocked';
        $traces['scenarios'][1]['useful_outcome'] = false;
        $traces['scenarios'][1]['projected_charge_minor'] = 0;
        $traces['scenarios'][1]['pricing_snapshot'] = null;
        $report = $this->service()->evaluate($traces);
        self::assertTrue($report['ready_for_approval']);
        self::assertSame(11900, $report['useful_projected_spend_minor']);
        self::assertSame(238, $report['useful_scenario_count']);
        self::assertSame(2_418_000, $report['external_assistant_cost_micro_rub']);
        $traces['scenarios'][1]['useful_outcome'] = true;
        self::assertContains('invalid_useful_outcome:'.$traces['scenarios'][1]['id'], $this->service()->evaluate($traces)['quality_reasons']);
    }

    public function test_prelaunch_missing_useful_evidence_and_inflated_unit_charge_fail_quality(): void
    {
        $traces = $this->prelaunchTraces();
        unset($traces['scenarios'][0]['useful_evidence']);
        self::assertFalse($this->service()->evaluate($traces)['quality_ready']);
        $traces = $this->prelaunchTraces();
        $traces['scenarios'][0]['pricing_snapshot']['unit_cost_micro_rub'] = 1;
        $traces['scenarios'][0]['projected_charge_minor'] = 1000000;
        $report = $this->service()->evaluate($traces);
        self::assertFalse($report['quality_ready']);
        self::assertContains('prelaunch_snapshot_policy_mismatch:'.$traces['scenarios'][0]['id'], $report['reasons']);
    }

    public function test_prelaunch_rejects_client_cash_inflated_offer_and_missing_price_proof(): void
    {
        $traces = $this->prelaunchTraces();
        $traces['assistant_revenue_minor'] = 100000;
        self::assertContains('prelaunch_actual_revenue_must_be_zero', $this->service()->evaluate($traces)['reasons']);
        $traces = $this->prelaunchTraces();
        $traces['price_policy_evidence']['subscription']['amount_minor'] = 999000;
        self::assertContains('prelaunch_price_policy_evidence_mismatch', $this->service()->evaluate($traces)['reasons']);
        unset($traces['price_policy_evidence']);
        self::assertFalse($this->service()->evaluate($traces)['ready_for_approval']);
        $policy = $this->policy();
        $policy['packs']['ai-credits-1000']['amount_minor'] = 999000;
        self::assertContains('prelaunch_price_policy_unavailable', (new AICreditReadinessService($policy))->evaluate($traces)['reasons']);
    }

    public function test_prelaunch_missing_fees_and_changed_module_price_invalidate_approval(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shadow-price-');
        self::assertIsString($path);
        try {
            $module = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/ModuleList/addons/ai-assistant.json'), true, 128, JSON_THROW_ON_ERROR);
            file_put_contents($path, json_encode($module, JSON_THROW_ON_ERROR));
            $service = new AICreditReadinessService($this->policy(), $path);
            $traces = $this->prelaunchTraces();
            $traces['price_policy_evidence'] = $service->prelaunchPricePolicyEvidence();
            $now = strtotime('2026-09-29T01:00:00Z');
            $approval = $service->approveReport($service->evaluate($traces), 'unit-test-only-key', $now);
            self::assertTrue($service->verifyApproval($approval, 'unit-test-only-key', $now));
            $module['pricing']['base_price'] = 4990;
            file_put_contents($path, json_encode($module, JSON_THROW_ON_ERROR));
            self::assertFalse($service->verifyApproval($approval, 'unit-test-only-key', $now));
            self::assertContains('prelaunch_price_policy_unavailable', $service->evaluate($traces)['reasons']);
            unset($module['pricing']);
            file_put_contents($path, json_encode($module, JSON_THROW_ON_ERROR));
            self::assertFalse($service->evaluate($traces)['economics_ready']);
            unlink($path);
            self::assertFalse($service->verifyApproval($approval, 'unit-test-only-key', $now));
            self::assertFalse($service->evaluate($traces)['economics_ready']);
        } finally {
            if (is_file($path)) { unlink($path); }
        }
    }

    public function test_prelaunch_postreport_policy_change_and_missing_usage_block_approval(): void
    {
        $service = $this->service();
        $traces = $this->prelaunchTraces();
        $report = $service->evaluate($traces);
        $policy = $this->policy();
        $policy['packs']['ai-credits-10000']['amount_minor'] = 900000;
        $this->expectException(InvalidArgumentException::class);
        (new AICreditReadinessService($policy))->approveReport($report, 'unit-test-only-key', strtotime('2026-09-29T01:00:00Z'));
    }

    public function test_prelaunch_incomplete_usage_or_partial_run_never_qualifies(): void
    {
        $traces = $this->prelaunchTraces();
        $traces['scenarios'][0]['provider_calls'][0]['usage_source'] = 'tokenizer_estimate';
        self::assertFalse($this->service()->evaluate($traces)['ready_for_approval']);
        $traces = $this->prelaunchTraces();
        $traces['partial_run'] = true;
        self::assertFalse($this->service()->evaluate($traces)['ready_for_approval']);
        $traces = $this->prelaunchTraces();
        $traces['cost_coverage']['index'] = false;
        self::assertFalse($this->service()->evaluate($traces)['ready_for_approval']);
    }

    public function test_prelaunch_exact_thirty_percent_boundary_and_no_useful_work(): void
    {
        $traces = $this->prelaunchTraces();
        $traces['background_calls'][1]['cost_micro_rub'] = 26_313_000;
        $report = $this->service()->evaluate($traces);
        self::assertSame(0.30, $report['external_cost_projected_revenue_ratio']);
        self::assertTrue($report['ready_for_approval']);
        $traces['background_calls'][1]['cost_micro_rub']++;
        self::assertFalse($this->service()->evaluate($traces)['ready_for_approval']);
        $traces = $this->prelaunchTraces();
        foreach ($traces['scenarios'] as &$scenario) { $scenario['useful_outcome'] = false; }
        unset($scenario);
        $report = $this->service()->evaluate($traces);
        self::assertSame(0, $report['projected_assistant_revenue_micro_rub']);
        self::assertFalse($report['ready_for_approval']);
    }

    public function test_model_quality_gate_counts_distinct_verified_generations_and_ignores_client_count(): void
    {
        $traces = $this->prelaunchTraces();
        $traces['verified_model_quality_success_count'] = 9999;
        foreach (array_slice(array_keys($traces['scenarios']), 0, 41) as $index) {
            $scenario = &$traces['scenarios'][$index];
            $scenario['provider_calls'] = [];
            $scenario['successful_cost_micro_rub'] = 0;
            $scenario['server_read_evidence'] = ['verified' => true, 'verifier' => 'unit_test_only_server_read',
                'evidence_sha256' => hash('sha256', $scenario['id']), 'source_receipts' => [['entity_id' => $index + 1]]];
            unset($scenario);
        }
        $report = $this->service()->evaluate($traces);
        self::assertSame(240, $report['scenario_count']);
        self::assertSame(199, $report['verified_model_quality_success_count']);
        self::assertContains('minimum_200_verified_model_quality_scenarios_required', $report['quality_reasons']);
        self::assertFalse($report['ready_for_approval']);
        $traces['scenarios'][40]['provider_calls'] = [$this->call('assistant', true, 0) + [
            'usage_source' => 'provider_response', 'provider_evidence_sha256' => hash('sha256', 'unit_test_only_provider')]];
        self::assertSame(200, $this->service()->evaluate($traces)['verified_model_quality_success_count']);
        self::assertTrue($this->service()->evaluate($traces)['ready_for_approval']);
    }

    public function test_multiple_calls_denials_and_unverified_business_results_do_not_inflate_model_scenarios(): void
    {
        $traces = $this->prelaunchTraces();
        $first = $traces['scenarios'][0]['provider_calls'][0];
        $first['cost_micro_rub'] = 0;
        $traces['scenarios'][0]['provider_calls'] = [$first, $first, $first];
        $traces['scenarios'][0]['successful_cost_micro_rub'] = 0;
        self::assertSame(240, $this->service()->evaluate($traces)['verified_model_quality_success_count']);
        $traces['scenarios'][0]['outcome'] = 'blocked';
        $traces['scenarios'][0]['useful_outcome'] = false;
        $traces['scenarios'][0]['projected_charge_minor'] = 0;
        $traces['scenarios'][1]['assertions']['business_quality']['observed'] = false;
        $traces['scenarios'][2]['provider_calls'][0]['input_tokens'] = 0;
        $traces['scenarios'][2]['provider_calls'][0]['output_tokens'] = 0;
        $traces['scenarios'][3]['provider_calls'][0]['provider_usage_available'] = false;
        $report = $this->service()->evaluate($traces);
        self::assertSame(236, $report['verified_model_quality_success_count']);
        self::assertFalse($report['ready_for_approval']);
    }

    public function test_signed_model_scenario_count_below_threshold_is_rejected_even_with_valid_signature(): void
    {
        $service = $this->service();
        $approval = $service->approveReport($service->evaluate($this->prelaunchTraces()), 'unit-test-only-key', strtotime('2026-09-29T01:00:00Z'));
        $hash = new \ReflectionMethod(AICreditReadinessService::class, 'hash');
        foreach ([199, 241, '200'] as $invalid) {
            $changed = $approval;
            $changed['report']['verified_model_quality_success_count'] = $invalid;
            unset($changed['report']['report_hash']);
            $changed['report']['report_hash'] = $hash->invoke($service, $changed['report']);
            unset($changed['signature']);
            $changed['signature'] = hash_hmac('sha256', $hash->invoke($service, $changed), 'unit-test-only-key');
            self::assertFalse($service->verifyApproval($changed, 'unit-test-only-key', strtotime('2026-09-29T01:00:00Z')));
        }
    }

    private function prelaunchTraces(): array
    {
        $traces = $this->shadowTraces();
        $traces['economics_basis'] = 'prelaunch_projection';
        $traces['economics_version'] = 1;
        $traces['assistant_implementation_fingerprint'] = $this->service()->assistantImplementationFingerprint();
        $traces['price_policy_evidence'] = $this->service()->prelaunchPricePolicyEvidence();
        foreach ($traces['scenarios'] as &$scenario) {
            $scenario['useful_outcome'] = true;
            $scenario['useful_evidence'] = ['verified' => true, 'verifier' => 'unit_test_only_useful_verifier', 'evidence_sha256' => hash('sha256', $scenario['id'])];
            $scenario['assertions']['business_quality']['expected'] = true;
            $scenario['assertions']['business_quality']['observed'] = true;
        }
        unset($scenario);
        return $traces;
    }

    public function test_launch_approval_survives_seven_days_and_unrelated_release(): void
    {
        $policy = $this->policy();
        $policy['release_sha'] = str_repeat('a', 40);
        $service = new AICreditReadinessService($policy);
        $now = strtotime('2026-09-29T01:00:00Z');
        $approval = $service->approveLaunchReport($service->evaluate($this->prelaunchTraces()), 'unit-test-only-key', $policy['release_sha'], $now);
        self::assertSame('version_bound_launch', $approval['approval_type']);
        self::assertSame(str_repeat('a', 40), $approval['release_sha']);
        self::assertNull($approval['expires_at']);
        self::assertTrue($service->verifyApproval($approval, 'unit-test-only-key', $now + 604801));
        $policy['release_sha'] = str_repeat('b', 40);
        self::assertTrue((new AICreditReadinessService($policy))->verifyApproval($approval, 'unit-test-only-key', $now + 604801));
        $policy['price_version'] = 3;
        self::assertFalse((new AICreditReadinessService($policy))->verifyApproval($approval, 'unit-test-only-key', $now));
        $approval['report']['scenario_count'] = 999;
        self::assertFalse($service->verifyApproval($approval, 'unit-test-only-key', $now));
    }

    public function test_launch_approval_requires_current_provenance_and_fresh_report_at_creation(): void
    {
        $policy = $this->policy();
        $policy['release_sha'] = str_repeat('a', 40);
        $service = new AICreditReadinessService($policy);
        $report = $service->evaluate($this->prelaunchTraces());
        $now = strtotime('2026-09-29T01:00:00Z');
        foreach ([['release' => str_repeat('b', 40), 'now' => $now], ['release' => 'unknown', 'now' => $now], ['release' => str_repeat('a', 40), 'now' => $now + 604801]] as $invalid) {
            try {
                $service->approveLaunchReport($report, 'unit-test-only-key', $invalid['release'], $invalid['now']);
                self::fail('Launch approval accepted invalid provenance or stale evidence');
            } catch (InvalidArgumentException $exception) { self::assertNotSame('', $exception->getMessage()); }
        }
    }

    public function test_launch_approval_rejects_synthetic_partial_failed_and_changed_implementation(): void
    {
        $policy = $this->policy();
        $policy['release_sha'] = str_repeat('a', 40);
        $service = new AICreditReadinessService($policy);
        $now = strtotime('2026-09-29T01:00:00Z');
        foreach (['synthetic', 'partial', 'failure', 'implementation'] as $case) {
            $traces = $this->prelaunchTraces();
            if ($case === 'synthetic') { $traces['stage'] = 'synthetic'; }
            if ($case === 'partial') { $traces['partial_run'] = true; }
            if ($case === 'failure') { $traces['scenarios'][0]['assertions']['leak']['observed'] = 'foreign_organization'; }
            if ($case === 'implementation') { $traces['assistant_implementation_fingerprint'] = str_repeat('0', 64); }
            $report = $service->evaluate($traces);
            self::assertFalse($report['quality_ready']);
            try {
                $service->approveLaunchReport($report, 'unit-test-only-key', $policy['release_sha'], $now);
                self::fail('Launch approval accepted invalid quality evidence');
            } catch (InvalidArgumentException $exception) { self::assertNotSame('', $exception->getMessage()); }
        }
    }

    public function test_launch_signed_wrong_implementation_and_price_source_are_rejected(): void
    {
        $policy = $this->policy();
        $policy['release_sha'] = str_repeat('a', 40);
        $service = new AICreditReadinessService($policy);
        $now = strtotime('2026-09-29T01:00:00Z');
        $valid = $service->approveLaunchReport($service->evaluate($this->prelaunchTraces()), 'unit-test-only-key', $policy['release_sha'], $now);
        $hash = new \ReflectionMethod(AICreditReadinessService::class, 'hash');
        foreach (['implementation', 'price'] as $case) {
            $approval = $valid;
            if ($case === 'implementation') { $approval['report']['assistant_implementation_fingerprint'] = str_repeat('0', 64); }
            if ($case === 'price') { $approval['report']['price_policy_evidence']['module_source_sha256'] = str_repeat('0', 64); }
            unset($approval['report']['report_hash']);
            $approval['report']['report_hash'] = $hash->invoke($service, $approval['report']);
            unset($approval['signature']);
            $approval['signature'] = hash_hmac('sha256', $hash->invoke($service, $approval), 'unit-test-only-key');
            self::assertFalse($service->verifyApproval($approval, 'unit-test-only-key', $now + 604801));
        }
    }

    public function test_source_normalization_preserves_code_changes_and_bom_but_ignores_newlines(): void
    {
        $service = $this->service();
        $hash = new \ReflectionMethod(AICreditReadinessService::class, 'normalizedSourceHash');
        $lf = "<?php\nreturn 50;\n";
        self::assertSame($hash->invoke($service, $lf), $hash->invoke($service, str_replace("\n", "\r\n", $lf)));
        self::assertSame($hash->invoke($service, $lf), $hash->invoke($service, str_replace("\n", "\r", $lf)));
        self::assertNotSame($hash->invoke($service, $lf), $hash->invoke($service, str_replace('50', '51', $lf)));
        self::assertNotSame($hash->invoke($service, $lf), $hash->invoke($service, "\xEF\xBB\xBF".$lf));
        $path = tempnam(sys_get_temp_dir(), 'shadow-source-');
        self::assertIsString($path);
        try {
            $module = (string) file_get_contents(dirname(__DIR__, 3).'/config/ModuleList/addons/ai-assistant.json');
            $module = str_replace(["\r\n", "\r"], "\n", $module);
            file_put_contents($path, $module);
            $service = new AICreditReadinessService($this->policy(), $path);
            $proof = $service->prelaunchPricePolicyEvidence();
            file_put_contents($path, str_replace("\n", "\r\n", $module));
            self::assertSame($proof, $service->prelaunchPricePolicyEvidence());
            self::assertSame('utf8_lf', $proof['source_normalization']);
        } finally { if (is_file($path)) { unlink($path); } }
    }

    public function test_unknown_economics_versions_and_legacy_paid_launch_conversion_are_rejected(): void
    {
        foreach ([['schema_version' => 1, 'economics_version' => 1, 'economics_basis' => 'prelaunch_projection'], ['schema_version' => 2, 'economics_version' => 2, 'economics_basis' => 'prelaunch_projection'], ['schema_version' => 2, 'economics_version' => 1, 'economics_basis' => 'client_revenue']] as $override) {
            try {
                $this->service()->evaluate(array_replace($this->prelaunchTraces(), $override));
                self::fail('Unknown economics contract accepted');
            } catch (InvalidArgumentException $exception) { self::assertSame('invalid_economics_basis', $exception->getMessage()); }
        }
        $policy = $this->policy();
        $policy['release_sha'] = str_repeat('a', 40);
        $service = new AICreditReadinessService($policy);
        $this->expectException(InvalidArgumentException::class);
        $service->approveLaunchReport($service->evaluate($this->traces()), 'unit-test-only-key', $policy['release_sha'], strtotime('2026-09-29T01:00:00Z'));
    }

    private function shadowTraces(): array
    {
        $traces = $this->traces();
        $traces['schema_version'] = 2;
        $traces['charging_mode'] = 'shadow';
        $traces['assistant_revenue_minor'] = 0;
        foreach ($traces['scenarios'] as &$scenario) {
            $scenario['projected_charge_minor'] = $scenario['charged_minor'];
            $scenario['charged_minor'] = 0;
            $scenario['pricing_snapshot'] = ['unit_minor' => 100, 'unit_cost_micro_rub' => 180000, 'minimum_minor' => 50, 'charge_step_minor' => 50];
            foreach ($scenario['provider_calls'] as &$call) {
                $call['usage_source'] = 'provider_response';
                $call['provider_evidence_sha256'] = hash('sha256', 'unit_test_only_provider');
            }
            unset($call);
        }
        unset($scenario);
        foreach ($traces['background_calls'] as &$call) {
            $call['usage_source'] = 'provider_response';
            $call['provider_evidence_sha256'] = hash('sha256', 'unit_test_only_provider');
        }
        unset($call);
        return $traces;
    }

    private function traces(): array
    {
        $scenarios = require dirname(__DIR__, 2).'/Fixtures/AIAssistant/shadow-scenarios.php';
        foreach ($scenarios as &$scenario) {
            $scenario['outcome'] = 'completed';
            $scenario['execution_evidence_sha256'] = hash('sha256', $scenario['id']);
            $scenario['provider_calls'] = [$this->call('assistant', true, 10_000)];
            $scenario['successful_cost_micro_rub'] = 10_000;
            $scenario['charged_minor'] = 50;
            foreach ($scenario['assertions'] as &$assertion) {
                $assertion['observed'] = $assertion['expected'];
                $assertion['verifier'] = 'unit_test_only';
                $assertion['evidence_sha256'] = hash('sha256', $scenario['id']);
            }
            unset($assertion);
        }
        unset($scenario);
        return ['schema_version' => 1, 'stage' => 'actual', 'policy_hash' => $this->service()->policyFingerprint(), 'period' => ['started_at' => '2026-09-28T00:00:00Z', 'ended_at' => '2026-09-29T00:00:00Z'], 'assistant_revenue_minor' => 10_000, 'cost_coverage' => ['assistant' => true, 'memory' => true, 'index' => true, 'errors' => true, 'ocr' => true], 'scenarios' => $scenarios, 'background_calls' => [$this->call('memory', true, 4_000), $this->call('index', true, 3_000), $this->call('ocr', true, 5_000), $this->call('assistant', false, 6_000), $this->call('estimate_generation', true, 999_000_000)]];
    }

    private function call(string $kind, bool $success, int $cost): array
    {
        return ['kind' => $kind, 'generative' => $kind !== 'index', 'provider' => 'unit_test_provider', 'model' => $kind === 'index' ? 'embedding-test' : 'gpt-6-luna', 'success' => $success, 'cost_micro_rub' => $cost, 'input_tokens' => 100, 'output_tokens' => 20, 'evidence' => 'provider_usage'];
    }

    private function service(): AICreditReadinessService
    {
        return new AICreditReadinessService($this->policy());
    }

    private function policy(): array
    {
        return require dirname(__DIR__, 3).'/config/ai-assistant-credits.php';
    }
}
