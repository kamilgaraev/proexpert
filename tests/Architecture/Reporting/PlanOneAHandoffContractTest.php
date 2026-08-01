<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDrillDownProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname(__DIR__, 3).'/scripts/reporting/build-plan-1a-evidence.php';

final class PlanOneAHandoffContractTest extends TestCase
{
    public function test_contract_lock_and_sidecar_bind_the_exact_closed_root(): void
    {
        self::assertFileExists($this->lockPath());
        self::assertFileExists($this->hashPath());
        self::assertSame(hash_file('sha256', $this->lockPath())."\n", file_get_contents($this->hashPath()));
        self::assertSame(['plan', 'contract_version', 'resources', 'permissions', 'error_count', 'definition_lifecycle', 'execution_phases', 'binding_lifecycle', 'owner_port_arity', 'route_contract', 'task_4a', 'task_4a2', 'task_4e', 'composer_contract'], array_keys($this->lock()));
    }

    public function test_contract_lock_preserves_resources_permissions_and_errors(): void
    {
        $lock = $this->lock();
        self::assertCount(7, $lock['resources']);
        self::assertSame(['reports.view', 'reports.run', 'reports.export', 'reports.download', 'reports.manage'], $lock['permissions']);
        self::assertSame(20, $lock['error_count']);
        self::assertSame(['artifact_path', 'artifact_sha256', 'status', 'baseline_commit_sha', 'reviewed_commit_sha', 'composer_json_before_sha256', 'composer_lock_before_sha256', 'composer_json_after_sha256', 'composer_lock_after_sha256', 'root_constraint', 'locked_opis_version', 'added_packages', 'content_hash'], array_keys($lock['composer_contract']['evidence']));
    }

    public function test_contract_lock_binds_the_closed_task_four_a_manifest_and_retry_key_matrix(): void
    {
        $task = $this->lock()['task_4a'];

        self::assertSame('fix[reports]: зафиксировать классификацию и печать снимков', $task['subject']);
        self::assertCount(53, $task['tracked_paths']);
        self::assertSame($task['tracked_paths'], array_values(array_unique($task['tracked_paths'])));
        self::assertSame(['cases' => 20, 'requests' => 38, 'assertions' => 120], $task['malformed_matrix']);
        self::assertSame(['tests' => 393, 'assertions' => 3143], $task['contract_command_counts']);
        self::assertSame('REPORT_IDEMPOTENCY_KEY_INVALID', $task['retry_idempotency_error']);
        self::assertSame(['operational', 'official'], $task['snapshot_classifications']);
        self::assertSame(['standard', 'sensitive'], $task['data_classifications']);
        self::assertCount(6, $task['output_classification_methods']);
    }

    public function test_contract_lock_binds_the_forward_only_task_four_a2_lineage(): void
    {
        $task = $this->lock()['task_4a2'];

        self::assertSame([
            'subject',
            'parent_commit_sha',
            'tracked_paths',
            'lineage',
            'identity_violation_reasons',
            'exception_message',
            'contract_command_counts',
        ], array_keys($task));
        self::assertSame('fix[reports]: типизировать нарушения идентичности снимков', $task['subject']);
        self::assertSame('973aabb17516c0ff9bc7d5a87b3ab6eb8732f333', $task['parent_commit_sha']);
        self::assertCount(16, $task['tracked_paths']);
        self::assertSame($task['tracked_paths'], array_values(array_unique($task['tracked_paths'])));
        self::assertSame(['Task 4a exact53', 'Task 4b exact39', 'Task 4a2 exact16'], $task['lineage']);
        self::assertSame(['invalid_kind', 'invalid_id', 'official_seal_required', 'operational_seal_forbidden', 'seal_time_invalid'], $task['identity_violation_reasons']);
        self::assertSame('snapshot_identity_invalid', $task['exception_message']);
    }

    public function test_contract_lock_binds_the_forward_only_task_four_e_lineage_and_exact_manifest(): void
    {
        $task = $this->lock()['task_4e'];

        self::assertSame('feat[reports]: типизировать ресурсы и текущую авторизацию', $task['subject']);
        self::assertSame('1934f947a44aa5221b5aa4cbd8c03963f5f1c005', $task['parent_commit_sha']);
        self::assertCount(78, $task['tracked_paths']);
        self::assertSame($task['tracked_paths'], array_values(array_unique($task['tracked_paths'])));
        self::assertSame(['Task 4a exact53', 'Task 4b exact39', 'Task 4a2 exact16', 'Task 4c exact15', 'Task 4d exact6', 'Task 4e exact78'], $task['lineage']);

        $taskFourE = \PlanOneAEvidence::taskFourEPaths();
        $taskFive = \PlanOneAExecutionPhaseAuthority::taskFivePaths();
        self::assertCount(78, $taskFourE);
        self::assertCount(30, $taskFive);
        self::assertSame([], array_values(array_intersect($taskFourE, $taskFive)));
        $owned = array_values(array_unique([...$taskFourE, ...$taskFive]));
        sort($owned, SORT_STRING);
        self::assertCount(108, $owned);
        $phase = \PlanOneAExecutionPhaseAuthority::trackedContract();
        self::assertCount(13, $phase['task_4f']['tracked_paths']);
        self::assertSame(
            ['tracked_paths' => 9, 'ledger_command_counts' => ['tests' => 504, 'assertions' => 5074]],
            [
                'tracked_paths' => count($phase['task_4j']['tracked_paths']),
                'ledger_command_counts' => $phase['task_4j']['ledger_command_counts'],
            ],
        );
        self::assertSame(108, $phase['ownership']['product_union']);
        self::assertSame(0, $phase['ownership']['product_overlap']);
        self::assertSame(
            ['POST_TASK_4J_PRE_TASK_5', 'POST_TASK_5'],
            array_keys($phase['phases']),
        );
        self::assertSame($phase, $this->lock()['execution_phases']);
        self::assertSame('resources', $task['typed_resources']['scope_key']);
        self::assertTrue($task['queue_authorization']['uncached']);
        self::assertSame('ACCESS EXCLUSIVE', $task['migration_cutover']['lock']);
        self::assertSame('allowed', $task['resource_registry']['empty_registry_empty_scope']);
        self::assertSame('denied', $task['resource_registry']['empty_registry_non_empty_scope']);
    }

    public function test_definition_registries_keep_nominal_wrappers(): void
    {
        self::assertSame(PublishedReportDefinition::class, (new ReflectionClass(ReportDefinitionRegistry::class))->getMethod('published')->getReturnType()?->getName());
        self::assertSame(CandidateReportDefinition::class, (new ReflectionClass(CandidateReportDefinitionRegistry::class))->getMethod('candidate')->getReturnType()?->getName());
    }

    public function test_owner_ports_and_binding_lifecycle_keep_exact_arities(): void
    {
        $methods = [[ReportDataProvider::class, 'materialize'], [ReportDataProvider::class, 'result'], [ReportRowQuery::class, 'page'], [ReportRowQuery::class, 'cursor'], [ReportDrillDownProvider::class, 'drillDown'], [ReportDefinitionBindingAssembler::class, 'register'], [ReportDefinitionBindingAssembler::class, 'assemble'], [ReportDefinitionCandidateValidator::class, 'validate']];
        self::assertSame([3, 2, 5, 4, 3, 1, 1, 2], array_map(static fn (array $method): int => (new ReflectionClass($method[0]))->getMethod($method[1])->getNumberOfParameters(), $methods));
    }

    public function test_all_twenty_stable_error_codes_remain_present(): void
    {
        self::assertCount(20, ReportErrorCode::cases());
        self::assertSame(20, count(array_unique(array_map(static fn (ReportErrorCode $code): string => $code->value, ReportErrorCode::cases()))));
    }

    public function test_completion_fixture_has_only_the_five_plan_one_c_digest_leaves(): void
    {
        $fixture = $this->fixture('plan-1a-completion.valid.json');
        self::assertSame(['plan', 'status', 'commit_sha', 'contract_lock_sha256', 'resource_schema_sha256', 'route_snapshot_sha256', 'commands', 'ci_http_matrices', 'task_4e', 'execution_phase'], array_keys($fixture));
        self::assertSame(6, $this->digestCount($fixture));
        self::assertArrayNotHasKey('command_ledger_sha256', $fixture);
        self::assertSame(['hermetic_http', 'hermetic_http'], [$fixture['ci_http_matrices']['authorization']['verification_mode'], $fixture['ci_http_matrices']['malformed_requests']['verification_mode']]);
        self::assertSame([20, 20], [$fixture['ci_http_matrices']['malformed_requests']['cases'], $fixture['ci_http_matrices']['malformed_requests']['passed']]);
    }

    public function test_gate_schema_has_the_four_closed_discriminated_branches(): void
    {
        $schema = $this->schema();
        self::assertSame(['#/$defs/route', '#/$defs/ledgerExact', '#/$defs/authorization', '#/$defs/malformed'], array_column($schema['oneOf'], '$ref'));
        self::assertFalse($schema['$defs']['routeSources']['additionalProperties']);
        self::assertFalse($schema['$defs']['matrixSources']['additionalProperties']);
        self::assertFalse($schema['$defs']['route']['properties']['routes']['items']);
        self::assertFalse($schema['$defs']['ledgerExact']['properties']['commands']['items']);
    }

    public function test_gate_fixtures_validate_against_the_closed_schema(): void
    {
        foreach (['plan-1a-route-snapshot.valid.json', 'plan-1a-command-ledger.valid.json', 'plan-1a-ci-authorization.valid.json', 'plan-1a-ci-malformed.valid.json'] as $name) {
            self::assertTrue($this->validates($this->fixture($name)), $name);
        }
    }

    public function test_route_schema_rejects_source_route_and_legacy_order_mutations(): void
    {
        $missing = $this->fixture('plan-1a-route-snapshot.valid.json');
        unset($missing['source_files']['routes/api.php']);
        self::assertFalse($this->validates($missing));
        $substituted = $this->fixture('plan-1a-route-snapshot.valid.json');
        $substituted['source_files']['routes/reports.php'] = $substituted['source_files']['routes/api.php'];
        unset($substituted['source_files']['routes/api.php']);
        self::assertFalse($this->validates($substituted));
        $routeOrder = $this->fixture('plan-1a-route-snapshot.valid.json');
        [$routeOrder['routes'][0], $routeOrder['routes'][1]] = [$routeOrder['routes'][1], $routeOrder['routes'][0]];
        self::assertFalse($this->validates($routeOrder));
        $method = $this->fixture('plan-1a-route-snapshot.valid.json');
        $method['routes'][0]['methods'] = ['GET'];
        self::assertFalse($this->validates($method));
        $legacyOrder = $this->fixture('plan-1a-route-snapshot.valid.json');
        [$legacyOrder['legacy_uris'][0], $legacyOrder['legacy_uris'][1]] = [$legacyOrder['legacy_uris'][1], $legacyOrder['legacy_uris'][0]];
        self::assertFalse($this->validates($legacyOrder));
    }

    public function test_matrix_schema_rejects_closed_source_case_and_count_mutations(): void
    {
        $authorization = $this->fixture('plan-1a-ci-authorization.valid.json');
        unset($authorization['source_files']['tests/Feature/Api/V1/Admin/Reporting/ReportingAuthorizationMatrixTest.php']);
        $authorization['source_files']['tests/Feature/Api/V1/Admin/Reporting/ReportingMalformedRequestContractTest.php'] = str_repeat('b', 64);
        self::assertFalse($this->validates($authorization));
        $authorization = $this->fixture('plan-1a-ci-authorization.valid.json');
        [$authorization['cases'][0], $authorization['cases'][1]] = [$authorization['cases'][1], $authorization['cases'][0]];
        self::assertFalse($this->validates($authorization));
        $authorization = $this->fixture('plan-1a-ci-authorization.valid.json');
        $authorization['cases'][0]['status'] = 403;
        self::assertFalse($this->validates($authorization));
        $authorization = $this->fixture('plan-1a-ci-authorization.valid.json');
        $authorization['cases'][10]['response_statuses'] = [200];
        self::assertFalse($this->validates($authorization));
        $authorization = $this->fixture('plan-1a-ci-authorization.valid.json');
        $authorization['cases'][18]['action_calls'] = 0;
        self::assertFalse($this->validates($authorization));
        $malformed = $this->fixture('plan-1a-ci-malformed.valid.json');
        unset($malformed['source_files']['tests/Feature/Api/V1/Admin/Reporting/ReportingMalformedRequestContractTest.php']);
        $malformed['source_files']['tests/Feature/Api/V1/Admin/Reporting/ReportingAuthorizationMatrixTest.php'] = str_repeat('b', 64);
        self::assertFalse($this->validates($malformed));
        $malformed = $this->fixture('plan-1a-ci-malformed.valid.json');
        [$malformed['cases'][0], $malformed['cases'][1]] = [$malformed['cases'][1], $malformed['cases'][0]];
        self::assertFalse($this->validates($malformed));
        $malformed = $this->fixture('plan-1a-ci-malformed.valid.json');
        $malformed['cases'][0]['response_codes'] = [null];
        self::assertFalse($this->validates($malformed));
        $malformed = $this->fixture('plan-1a-ci-malformed.valid.json');
        $malformed['counts']['http_requests'] = 33;
        self::assertFalse($this->validates($malformed));
    }

    public function test_ledger_schema_rejects_command_order_and_tuple_mutations(): void
    {
        $reordered = $this->fixture('plan-1a-command-ledger.valid.json');
        $reordered['commands'] = array_reverse($reordered['commands']);
        self::assertFalse($this->validates($reordered));
        $counts = $this->fixture('plan-1a-command-ledger.valid.json');
        $counts['commands'][0]['assertions'] = 5062;
        self::assertFalse($this->validates($counts));
        $command = $this->fixture('plan-1a-command-ledger.valid.json');
        $command['commands'][1]['command_id'] = 'another_command';
        self::assertFalse($this->validates($command));
    }

    public function test_plan_one_c_compatibility_declares_the_literal_twenty_seven_descriptor_set(): void
    {
        $plan = $this->plan();
        $lock = $this->planOneCLock($plan);
        $actual = $this->planOneCDescriptors($plan, $lock);
        self::assertSame($this->expectedPlanOneCDescriptors(), $actual);
        self::assertCount(27, $actual);
        self::assertSame(27, $lock['required_prerequisites']['bundle_descriptor_count']);
    }

    public function test_plan_one_c_compatibility_preserves_mapping_modes_and_nominal_protocol(): void
    {
        $plan = $this->plan();
        self::assertSame([['contract_lock_sha256', 'plan-1a-contract-lock', 'artifacts/plan-1a-contract-lock.json'], ['resource_schema_sha256', 'plan-1a-resource-schema', 'artifacts/plan-1a-resource-schema.json'], ['route_snapshot_sha256', 'plan-1a-route-snapshot', 'artifacts/plan-1a-route-snapshot.json'], ['ci_http_matrices.authorization.artifact_sha256', 'plan-1a-ci-authorization', 'artifacts/plan-1a-ci-authorization.json'], ['ci_http_matrices.malformed_requests.artifact_sha256', 'plan-1a-ci-malformed', 'artifacts/plan-1a-ci-malformed.json']], $this->planOneCMappings($plan));
        self::assertStringContainsString('both matrix modes remain', $plan);
        self::assertStringContainsString('registry/assembler arity plus the nominal-wrapper protocol are unchanged', $plan);
        self::assertStringContainsString('verification_mode=production_topology_snapshot', $plan);
    }

    private function validates(array $artifact): bool
    {
        return (new CompliantValidator)->validate(json_decode(json_encode($artifact, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR), json_decode(json_encode($this->schema(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR))->isValid();
    }

    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents($this->root().'/tests/Fixtures/Reporting/Evidence/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }

    private function schema(): array
    {
        return json_decode((string) file_get_contents($this->root().'/docs/reports/contracts/plan-1a-gate-evidence.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function plan(): string
    {
        return (string) file_get_contents($this->root().'/docs/superpowers/plans/2026-07-26-reports-plan-1c-catalog-workspace-quality.md');
    }

    private function planOneCLock(string $plan): array
    {
        $tick = chr(96);
        preg_match('/The tracked lock contains exact current symbols, not aliases:\\s*'.$tick.$tick.$tick.'json\\s*(\\{.*?\\})\\s*'.$tick.$tick.$tick.'/s', $plan, $match);
        self::assertArrayHasKey(1, $match);

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    private function planOneCDescriptors(string $plan, array $lock): array
    {
        preg_match_all('/(?m)^- Create: .+Prerequisites\\/artifacts\\/(plan-1b-[^\\x60]+)\\.json.+$/', $plan, $matches);
        $planOneA = [['plan-1a-contract-lock', 'artifacts/plan-1a-contract-lock.json'], ['plan-1a-resource-schema', 'artifacts/plan-1a-resource-schema.json'], ['plan-1a-route-snapshot', 'artifacts/plan-1a-route-snapshot.json'], ['plan-1a-ci-authorization', 'artifacts/plan-1a-ci-authorization.json'], ['plan-1a-ci-malformed', 'artifacts/plan-1a-ci-malformed.json']];
        self::assertCount(20, $matches[1]);
        $planOneB = array_map(static fn (string $id, string $path): array => ['plan-1b:'.$id, 'artifacts/'.$path.'.json'], $lock['required_prerequisites']['plan_1b_required_gate_ids'], $matches[1]);

        return [['plan-1a-completion', 'plan-1a-completion.valid.json'], ...$planOneA, ['plan-1b-completion', 'plan-1b-completion.valid.json'], ...$planOneB];
    }

    private function expectedPlanOneCDescriptors(): array
    {
        $gates = ['plan1a_handoff', 'ownership_boundary', 'run_state_machine', 'run_idempotency', 'snapshot_identity', 'rows_cursor_drill_parity', 'row_stream_shape', 'export_state_machine', 'export_idempotency', 'renderer_parity', 'pdf_renderer_budget', 'streaming_budget', 'file_service_call_graph', 's3_version_race', 'audit_fail_closed', 'retention_exact_version', 'action_bindings', 'error_retryability', 'run_export_observability', 'static_analysis'];

        return [['plan-1a-completion', 'plan-1a-completion.valid.json'], ['plan-1a-contract-lock', 'artifacts/plan-1a-contract-lock.json'], ['plan-1a-resource-schema', 'artifacts/plan-1a-resource-schema.json'], ['plan-1a-route-snapshot', 'artifacts/plan-1a-route-snapshot.json'], ['plan-1a-ci-authorization', 'artifacts/plan-1a-ci-authorization.json'], ['plan-1a-ci-malformed', 'artifacts/plan-1a-ci-malformed.json'], ['plan-1b-completion', 'plan-1b-completion.valid.json'], ...array_map(static fn (string $gate): array => ['plan-1b:'.$gate, 'artifacts/plan-1b-'.str_replace('_', '-', $gate).'.json'], $gates)];
    }

    private function planOneCMappings(string $plan): array
    {
        preg_match('/The Plan 1a completion-to-descriptor mapping is closed and literal:(.*?)PlanOneCPrerequisiteEvidenceValidator/s', $plan, $match);
        self::assertArrayHasKey(1, $match);
        preg_match_all('/(?m)^\\| [^|]+\\| [^|]+\\| [^|]+\\|$/', $match[1], $rows);

        return array_slice(array_map(static fn (string $row): array => array_map(static fn (string $cell): string => trim($cell, ' |'.chr(96)), array_values(array_filter(explode('|', $row), static fn (string $cell): bool => trim($cell) !== ''))), $rows[0]), 1);
    }

    private function digestCount(mixed $value): int
    {
        if (! is_array($value)) {
            return 0;
        }
        $count = 0;
        foreach ($value as $key => $item) {
            $count += (is_string($key) && str_ends_with($key, 'sha256') ? 1 : 0) + $this->digestCount($item);
        }

        return $count;
    }

    private function lock(): array
    {
        return json_decode((string) file_get_contents($this->lockPath()), true, 512, JSON_THROW_ON_ERROR);
    }

    private function lockPath(): string
    {
        return $this->root().'/docs/reports/contracts/plan-1a-contract-lock.json';
    }

    private function hashPath(): string
    {
        return $this->root().'/docs/reports/contracts/plan-1a-contract-lock.sha256';
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
