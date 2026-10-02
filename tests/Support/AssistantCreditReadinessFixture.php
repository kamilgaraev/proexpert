<?php

declare(strict_types=1);

namespace Tests\Support;

final class AssistantCreditReadinessFixture
{
    public static function write(string $path, array $policy, string $signingKey): void
    {
        if (realpath(dirname($path)) !== realpath(sys_get_temp_dir())) { throw new \InvalidArgumentException('Readiness test fixture must remain in the temporary directory.'); }
        $readiness = new \App\Services\Credits\AICreditReadinessService($policy);
        $scenarios = require base_path('tests/Fixtures/AIAssistant/shadow-scenarios.php');
        foreach ($scenarios as &$scenario) {
            $scenario['outcome'] = 'completed';
            $scenario['provider_calls'] = [['kind' => 'assistant', 'success' => true, 'generative' => true, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_micro_rub' => 1000, 'evidence' => 'provider_usage']];
            $scenario['successful_cost_micro_rub'] = 1000;
            $scenario['charged_minor'] = 50;
            $scenario['execution_evidence_sha256'] = hash('sha256', 'wallet-test-fixture:'.$scenario['id']);
            foreach ($scenario['assertions'] as &$assertion) {
                $assertion['observed'] = $assertion['expected'];
                $assertion['verifier'] = 'wallet-test-fixture';
                $assertion['evidence_sha256'] = hash('sha256', $scenario['id'].':'.$assertion['expected']);
            }
            unset($assertion);
        }
        unset($scenario);
        $background = [];
        foreach (['memory', 'index', 'ocr'] as $kind) {
            $background[] = ['kind' => $kind, 'success' => true, 'generative' => $kind !== 'index', 'provider' => 'test-fixture', 'model' => $kind === 'index' ? 'text-embedding-3-small' : 'gpt-6-luna', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_micro_rub' => 1000, 'evidence' => 'provider_usage'];
        }
        $background[] = ['kind' => 'assistant', 'success' => false, 'generative' => true, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna', 'input_tokens' => 1, 'output_tokens' => 0, 'cost_micro_rub' => 1000, 'evidence' => 'provider_usage'];
        $report = $readiness->evaluate(['schema_version' => 1, 'stage' => 'actual', 'policy_hash' => $readiness->policyFingerprint(), 'period' => ['started_at' => date(DATE_ATOM, time() - 8 * 86400), 'ended_at' => date(DATE_ATOM, time() - 1)], 'assistant_revenue_minor' => 1000000, 'cost_coverage' => array_fill_keys(['assistant', 'memory', 'index', 'errors', 'ocr'], true), 'scenarios' => $scenarios, 'background_calls' => $background]);
        if (! $report['ready_for_approval']) { throw new \RuntimeException(json_encode($report['reasons'], JSON_THROW_ON_ERROR)); }
        $approval = $readiness->approveReport($report, $signingKey);
        file_put_contents($path, json_encode($approval, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
