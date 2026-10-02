<?php

declare(strict_types=1);

namespace Tests\Support;

final class ShadowScenarioManifest
{
    public static function load(bool $workflow = false, bool $supplemental = false): array
    {
        $source = require dirname(__DIR__).'/Fixtures/AIAssistant/'.($workflow ? 'shadow-scenarios.php' : 'shadow-domain-scenarios.php');
        if (! $workflow) {
            $authorized = [];
            foreach ($source as $candidate) {
                $role = $candidate['input']['context']['role'];
                if (! in_array($role, ['finance_manager', 'viewer'], true)) { continue; }
                $candidate['id'] .= '-assigned-authorized';
                $candidate['input']['context']['role'] = $role === 'finance_manager' ? 'assigned_finance_reader' : 'assigned_reader';
                $candidate['input']['context']['state'] = 'active';
                $candidate['input']['context']['coverage_contract'] = 'current_assigned_project_read_permissions';
                $candidate['input']['request_id'] = sprintf('30000000-0000-4000-8000-%012d', count($authorized) + 1);
                $authorized[] = $candidate;
            }
            $source = array_merge($source, $authorized);
            if ($supplemental) {
                foreach ($source as $candidate) {
                    if ($candidate['category'] !== 'finance_amount' || $candidate['requested_profile'] !== 'normal' || $candidate['input']['context']['role'] !== 'owner') { continue; }
                    $candidate['id'] = 'finance_amount-normal-owner-full4000';
                    $candidate['input']['context']['long_current_query'] = true;
                    $candidate['input']['context']['coverage_contract'] = 'exact_full_4000_current_financial_question_with_critical_tail';
                    $candidate['input']['request_id'] = '40000000-0000-4000-8000-000000000001';
                    $source[] = $candidate;
                    break;
                }
            }
        }
        if (! $workflow && $supplemental) {
            $extras = ShadowDocumentScenario::scenarios();
            $legacy = require dirname(__DIR__).'/Fixtures/AIAssistant/shadow-scenarios.php';
            foreach ($legacy as $candidate) {
                if (in_array($candidate['id'], ['memory-1-1', 'memory-3-1', 'index-1-1', 'index-3-1', 'context-1-1'], true)) {
                    $candidate['input']['supplemental_scope'] = match ($candidate['category']) {
                        'memory' => 'confirmed_private_memory_crud', 'context' => 'exact_4000_character_current_query_preservation',
                        default => 'real_index_checksum_and_lease_recovery',
                    };
                    $extras[] = $candidate;
                }
            }
            foreach ($extras as &$extra) {
                $extra['input']['context'] ??= ['role' => 'owner', 'state' => 'active'];
                $extra['input']['context']['role'] = 'owner';
                $extra['input']['request_id'] ??= sprintf('20000000-0000-4000-8000-%012d', count($source) + 1);
                $extra['assertions'] ??= array_fill_keys(['rights', 'leak', 'unconfirmed_actions', 'factual_amounts', 'business_quality'], ['expected' => true]);
                $source[] = $extra;
            }
            unset($extra);
            $source[] = ShadowProviderErrorScenario::scenario();
        }
        return array_map(static function (array $scenario): array {
            return array_intersect_key($scenario, array_flip(['id', 'category', 'requested_profile', 'input', 'estimated_minor', 'approved_minor', 'actual_attachment_workflow_contract'])) + [
                'expectations' => array_fill_keys(array_keys($scenario['assertions']), true),
                'expectation_descriptions' => array_map(static fn (array $assertion): mixed => $assertion['expected'], $scenario['assertions']),
                'fixture_requirements' => ['real_actor', 'current_permissions', 'current_module_entitlements', 'domain_goldens', 'category_state_transition'],
            ];
        }, $source);
    }
}
