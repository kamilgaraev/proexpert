<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Tooling;

use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 4).'/scripts/reporting/run-plan-1a-gates.php';

final class RunPlanOneAGatesTest extends TestCase
{
    private const PHP = 'C:/Users/kamilgaraev/AppData/Local/CodexToolchains/most-reports/php-8.2.29-nts-vs16-x64/php.exe';

    private const PHP_DIR = 'C:/Users/kamilgaraev/AppData/Local/CodexToolchains/most-reports/php-8.2.29-nts-vs16-x64';

    private array $temporaryDirectories = [];

    public function test_runner_consumes_the_same_closed_execution_phase_contract_as_the_builder(): void
    {
        $contract = \PlanOneAExecutionPhaseAuthority::trackedContract();

        self::assertSame(
            ['POST_TASK_4I_PRE_TASK_5', 'POST_TASK_5'],
            array_keys($contract['phases']),
        );
        self::assertCount(4, $contract['phases']['POST_TASK_4I_PRE_TASK_5']['dispatch_allowlist']);
        self::assertCount(5, $contract['phases']['POST_TASK_5']['dispatch_allowlist']);
        self::assertSame('pending', $contract['phases']['POST_TASK_4I_PRE_TASK_5']['task_5_state']);
        self::assertSame('present', $contract['phases']['POST_TASK_5']['task_5_state']);
    }

    public function test_runner_declares_the_exact_task_four_a2_subject_parent_manifest_and_lineage(): void
    {
        $constants = (new ReflectionClass(\PlanOneAGates::class))->getConstants();

        self::assertSame('fix[reports]: типизировать нарушения идентичности снимков', $constants['TASK_FOUR_A2_SUBJECT']);
        self::assertSame('973aabb17516c0ff9bc7d5a87b3ab6eb8732f333', $constants['TASK_FOUR_A2_PARENT']);
        self::assertCount(16, $constants['TASK_FOUR_A2_PATHS']);
        self::assertSame($constants['TASK_FOUR_A2_PATHS'], array_values(array_unique($constants['TASK_FOUR_A2_PATHS'])));
        $sorted = $constants['TASK_FOUR_A2_PATHS'];
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $constants['TASK_FOUR_A2_PATHS']);
        self::assertSame(['Task 4a exact53', 'Task 4b exact39', 'Task 4a2 exact16'], $constants['TASK_FOUR_A2_LINEAGE']);
    }

    public function test_runner_declares_the_forward_only_task_four_e_contract(): void
    {
        $constants = (new ReflectionClass(\PlanOneAGates::class))->getConstants();

        self::assertSame('feat[reports]: типизировать ресурсы и текущую авторизацию', $constants['TASK_FOUR_E_SUBJECT']);
        self::assertSame('1934f947a44aa5221b5aa4cbd8c03963f5f1c005', $constants['TASK_FOUR_E_PARENT']);
        self::assertCount(78, $constants['TASK_FOUR_E_PATHS']);
        self::assertSame($constants['TASK_FOUR_E_PATHS'], array_values(array_unique($constants['TASK_FOUR_E_PATHS'])));
        self::assertSame(
            ['Task 4a exact53', 'Task 4b exact39', 'Task 4a2 exact16', 'Task 4c exact15', 'Task 4d exact6', 'Task 4e exact78'],
            $constants['TASK_FOUR_E_LINEAGE'],
        );
    }

    public function test_runner_emits_task_four_e_matrix_inventories_from_real_test_providers(): void
    {
        $inventories = $this->invokeStatic('taskFourEMatrixInventories', []);

        self::assertSame([
            'organization_scope' => 11,
            'project_scope' => 8,
            'current_abac' => 16,
            'typed_resources' => 17,
            'repeatable_read_races' => 8,
        ], array_map('count', $inventories));
        self::assertContains(
            'test_closed_abac_behavior_matrix::one bad assignment does not mask a valid assignment',
            $inventories['current_abac'],
        );
        self::assertContains(
            'test_handler_exceptions_are_normalized_to_scope_forbidden::runtime exception',
            $inventories['typed_resources'],
        );
    }

    public function test_historical_task_four_a_and_four_b_mutation_families_are_rejected(): void
    {
        [$repository, $mutations] = $this->historicalMutationCommits();
        $constants = (new ReflectionClass(\PlanOneAGates::class))->getConstants();
        $cases = [
            'task4a_wrong_parent' => [$constants['TASK_FOUR_A_PARENT'], $constants['TASK_FOUR_A_TREE'], $constants['TASK_FOUR_A_SUBJECT'], $constants['TASK_FOUR_A_PATHS'], 'parent', 'PLAN_1A_GATE_TASK_4A_HISTORY_PARENT_INVALID'],
            'task4a_altered_blob' => [$constants['TASK_FOUR_A_PARENT'], $constants['TASK_FOUR_A_TREE'], $constants['TASK_FOUR_A_SUBJECT'], $constants['TASK_FOUR_A_PATHS'], 'tree', 'PLAN_1A_GATE_TASK_4A_HISTORY_TREE_INVALID'],
            'task4b_wrong_subject' => [$constants['TASK_FOUR_A_COMMIT'], $constants['TASK_FOUR_B_TREE'], $constants['TASK_FOUR_B_SUBJECT'], $constants['TASK_FOUR_B_PATHS'], 'subject', 'PLAN_1A_GATE_TASK_4B_HISTORY_SUBJECT_INVALID'],
            'task4b_same_count_different_paths' => [$constants['TASK_FOUR_A_COMMIT'], $this->git($repository, ['show', '-s', '--format=%T', $mutations['task4b_same_count_different_paths']]), $constants['TASK_FOUR_B_SUBJECT'], $constants['TASK_FOUR_B_PATHS'], 'paths', 'PLAN_1A_GATE_TASK_4B_HISTORY_PATHS_INVALID'],
            'task4b_altered_blob' => [$constants['TASK_FOUR_A_COMMIT'], $constants['TASK_FOUR_B_TREE'], $constants['TASK_FOUR_B_SUBJECT'], $constants['TASK_FOUR_B_PATHS'], 'tree', 'PLAN_1A_GATE_TASK_4B_HISTORY_TREE_INVALID'],
        ];

        foreach ($cases as $name => [$parent, $tree, $subject, $paths, $boundary, $message]) {
            $arguments = [$repository, $mutations[$name], $mutations[$name], $parent, $tree, $subject, $paths, str_starts_with($name, 'task4a_') ? 'PLAN_1A_GATE_TASK_4A_HISTORY' : 'PLAN_1A_GATE_TASK_4B_HISTORY'];
            self::assertTrue($this->historicalMutationRejected($arguments, $message), $name);
            $this->setStaticProperty('historicalPredicateOverride', static fn (string $actualBoundary, bool $actual): bool => $actualBoundary === $boundary ? true : $actual);
            self::assertFalse($this->historicalMutationRejected($arguments, $message), $name.' predicate bypass was not detected');
            $this->setStaticProperty('historicalPredicateOverride', null);
        }

        self::assertTrue($this->historicalWrapperRejected($repository, 'validateHistoricalTaskFourACommit', $mutations['task4a_wrong_parent'], 'PLAN_1A_GATE_TASK_4A_HISTORY_COMMIT_INVALID'));
        self::assertTrue($this->historicalWrapperRejected($repository, 'validateHistoricalTaskFourBCommit', $mutations['task4b_wrong_subject'], 'PLAN_1A_GATE_TASK_4B_HISTORY_COMMIT_INVALID'));
        $this->git($repository, ['replace', '-f', '0b581469a3ad39d4ce5eff5c41072f5ef3f745f7', $mutations['task4a_wrong_parent']]);
        $this->git($repository, ['replace', '-f', '973aabb17516c0ff9bc7d5a87b3ab6eb8732f333', $mutations['task4b_wrong_subject']]);
        $this->invokeStatic('validateHistoricalTaskLineage', [$repository]);
        self::assertCount(2, $this->gitPaths($repository, ['replace', '-l']));
    }

    private function historicalMutationRejected(array $arguments, string $message): bool
    {
        try {
            $this->invokeStatic('validateHistoricalCommit', $arguments);
        } catch (\PlanOneAGatesFailure $failure) {
            return $failure->getMessage() === $message;
        }

        return false;
    }

    private function historicalWrapperRejected(string $repository, string $method, string $commit, string $message): bool
    {
        try {
            $this->invokeStatic($method, [$repository, $commit]);
        } catch (\PlanOneAGatesFailure $failure) {
            return $failure->getMessage() === $message;
        }

        return false;
    }

    protected function tearDown(): void
    {
        foreach (['processOverride', 'topologyOverride', 'harnessOverride', 'faultOverride', 'phpHashOverride', 'phpVersionOverride', 'branchOverride', 'historicalPredicateOverride', 'executionPhase', 'cleanHead'] as $property) {
            $this->setStaticProperty($property, null);
        }
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeTree($directory);
        }
    }

    public function test_four_gate_fixtures_validate_against_closed_schema(): void
    {
        $this->assertStaticPathsCoverExactTaskFourAChangedPhpTests();

        foreach ($this->gateFixtureNames() as $file) {
            self::assertTrue($this->validates($this->decode($file)));
        }
    }

    public function test_schema_rejects_route_source_key_substitution(): void
    {
        $fixture = $this->decode('plan-1a-route-snapshot.valid.json');
        $value = array_shift($fixture['source_files']);
        $fixture['source_files']['foreign.php'] = $value;

        self::assertFalse($this->validates($fixture));
    }

    public function test_schema_rejects_reordered_routes(): void
    {
        $fixture = $this->decode('plan-1a-route-snapshot.valid.json');
        [$fixture['routes'][0], $fixture['routes'][1]] = [$fixture['routes'][1], $fixture['routes'][0]];

        self::assertFalse($this->validates($fixture));
    }

    public function test_schema_rejects_route_method_drift(): void
    {
        $fixture = $this->decode('plan-1a-route-snapshot.valid.json');
        $fixture['routes'][0]['methods'] = ['GET'];

        self::assertFalse($this->validates($fixture));
    }

    public function test_schema_rejects_route_middleware_drift(): void
    {
        $fixture = $this->decode('plan-1a-route-snapshot.valid.json');
        $fixture['routes'][0]['middleware'][1] = 'reports';

        self::assertFalse($this->validates($fixture));
    }

    public function test_schema_rejects_authorization_source_substitution(): void
    {
        $fixture = $this->decode('plan-1a-ci-authorization.valid.json');
        $value = array_shift($fixture['source_files']);
        $fixture['source_files']['foreign.php'] = $value;

        self::assertFalse($this->validates($fixture));
    }

    public function test_schema_rejects_reordered_authorization_cases(): void
    {
        $fixture = $this->decode('plan-1a-ci-authorization.valid.json');
        [$fixture['cases'][0], $fixture['cases'][1]] = [$fixture['cases'][1], $fixture['cases'][0]];

        self::assertFalse($this->validates($fixture));
    }

    public function test_schema_rejects_authorization_execution_record_drift(): void
    {
        $fixture = $this->decode('plan-1a-ci-authorization.valid.json');
        $fixture['cases'][0]['status'] = 403;

        self::assertFalse($this->validates($fixture));
    }

    public function test_schema_rejects_malformed_case_id_drift(): void
    {
        $fixture = $this->decode('plan-1a-ci-malformed.valid.json');
        $fixture['cases'][0]['case_id'] = 'foreign_case';

        self::assertFalse($this->validates($fixture));
    }

    public function test_schema_rejects_malformed_execution_record_drift(): void
    {
        $fixture = $this->decode('plan-1a-ci-malformed.valid.json');
        $fixture['cases'][0]['response_codes'][0] = 'FOREIGN_CODE';

        self::assertFalse($this->validates($fixture));
    }

    public function test_schema_accepts_the_closed_twenty_case_retry_key_matrix(): void
    {
        $fixture = $this->decode('plan-1a-ci-malformed.valid.json');
        self::assertSame([
            'invalid_run_show_ulid',
            'invalid_run_rows_ulid',
            'invalid_run_drill_down_ulid',
            'invalid_run_retry_ulid',
            'missing_run_retry_idempotency_key',
            'invalid_run_retry_idempotency_key',
            'invalid_run_cancel_ulid',
            'invalid_export_create_run_ulid',
            'invalid_export_show_ulid',
            'invalid_export_retry_ulid',
            'missing_export_retry_idempotency_key',
            'invalid_export_retry_idempotency_key',
            'invalid_export_cancel_ulid',
            'invalid_export_download_ulid',
            'missing_run_as_of',
            'rows_limit_101',
            'missing_drill_down_token',
            'invalid_export_format',
            'unexpected_download_body',
            'legacy_routes_absent',
        ], array_column($fixture['cases'], 'case_id'));
        self::assertSame([
            'cases' => 20,
            'passed' => 20,
            'validation_cases' => 19,
            'legacy_absence_cases' => 1,
            'legacy_uri_count' => 19,
            'http_requests' => 38,
            'assertions' => 120,
        ], $fixture['counts']);
        self::assertTrue($this->validates($fixture));
    }

    public function test_schema_rejects_ledger_command_reordering(): void
    {
        $fixture = $this->decode('plan-1a-command-ledger.valid.json');
        [$fixture['commands'][0], $fixture['commands'][1]] = [$fixture['commands'][1], $fixture['commands'][0]];

        self::assertFalse($this->validates($fixture));
    }

    public function test_schema_rejects_ledger_count_drift(): void
    {
        $fixture = $this->decode('plan-1a-command-ledger.valid.json');
        $fixture['commands'][0]['tests'] = 503;

        self::assertFalse($this->validates($fixture));
    }

    public function test_route_contract_rejects_every_topology_mutation_family(): void
    {
        $mutations = [
            static function (array &$value): void {
                $value['routes'][] = $value['routes'][0];
            },
            static function (array &$value): void {
                array_pop($value['routes']);
            },
            static function (array &$value): void {
                $value['routes'][0]['uri'] .= '/drift';
            },
            static function (array &$value): void {
                $value['routes'][0]['methods'] = ['GET'];
            },
            static function (array &$value): void {
                $value['routes'][1]['methods'] = ['POST', 'OPTIONS'];
            },
            static function (array &$value): void {
                $value['counts']['provider_registrations'] = 2;
            },
            static function (array &$value): void {
                $value['counts']['legacy_routes'] = 1;
            },
            static function (array &$value): void {
                $value['legacy_uris'][0] .= '/present';
            },
        ];

        $this->assertEveryMutationRejected('plan-1a-route-snapshot.valid.json', $mutations);
    }

    public function test_authorization_contract_rejects_case_shape_status_and_count_mutations(): void
    {
        $mutations = [
            static function (array &$value): void {
                array_pop($value['cases']);
            },
            static function (array &$value): void {
                $value['cases'][] = $value['cases'][0];
            },
            static function (array &$value): void {
                $value['counts']['passed']--;
            },
            static function (array &$value): void {
                [$value['cases'][5], $value['cases'][6]] = [$value['cases'][6], $value['cases'][5]];
            },
            static function (array &$value): void {
                $value['status'] = 'failed';
            },
            static function (array &$value): void {
                $value['cases'][4]['status'] = 201;
            },
            static function (array &$value): void {
                $value['counts']['cases'] = 21;
            },
            static function (array &$value): void {
                $value['counts']['http_requests'] = 27;
            },
        ];

        $this->assertEveryMutationRejected('plan-1a-ci-authorization.valid.json', $mutations);
    }

    public function test_authorization_contract_rejects_cache_reuse_and_indistinguishability_mutations(): void
    {
        $mutations = [
            static function (array &$value): void {
                $value['cases'][20]['actor_loads'] = 1;
            },
            static function (array &$value): void {
                $value['cases'][21]['actor_loads'] = 1;
            },
            static function (array &$value): void {
                $value['cases'][18]['response_statuses'][1] = 404;
            },
            static function (array &$value): void {
                $value['cases'][18]['response_codes'][1] = 'REPORT_SCOPE_FORBIDDEN';
            },
            static function (array &$value): void {
                $value['cases'][19]['response_statuses'][1] = 404;
            },
            static function (array &$value): void {
                $value['cases'][19]['response_codes'][1] = 'REPORT_SCOPE_FORBIDDEN';
            },
        ];

        $this->assertEveryMutationRejected('plan-1a-ci-authorization.valid.json', $mutations);
    }

    public function test_http_contract_rejects_every_forbidden_side_effect_sentinel(): void
    {
        $mutations = [];
        foreach (['db_writes', 'network_calls', 'action_dispatches', 'queue_jobs', 'mail_sends', 'storage_writes'] as $sentinel) {
            $mutations[] = static function (array &$value) use ($sentinel): void {
                $value['cases'][0][$sentinel] = 1;
            };
        }

        $this->assertEveryMutationRejected('plan-1a-ci-authorization.valid.json', $mutations);
    }

    public function test_hermetic_boundary_sentinels_fail_closed_for_every_external_side_effect_family(): void
    {
        foreach (['database', 'network', 'queue', 'mail', 'storage', 'filesystem'] as $boundary) {
            $ledger = new \Tests\Support\Reporting\HermeticBoundaryLedger;

            try {
                $ledger->breach($boundary);
                self::fail('Expected '.$boundary.' boundary breach');
            } catch (\LogicException $failure) {
                self::assertSame('REPORT_HERMETIC_'.strtoupper($boundary).'_ACCESS_FORBIDDEN', $failure->getMessage());
                self::assertSame([$boundary], $ledger->breaches());
            }
        }
    }

    public function test_case_validator_rejects_missing_extra_reordered_and_count_drift_with_stable_failures(): void
    {
        $records = $this->decode('plan-1a-ci-authorization.valid.json')['cases'];
        $ids = array_column($records, 'case_id');
        $mutations = [
            'PLAN_1A_GATE_CASE_ORDER_DRIFT' => [
                static function (array $value): array {
                    array_pop($value);

                    return $value;
                },
                static function (array $value): array {
                    $value[] = $value[0];

                    return $value;
                },
                static function (array $value): array {
                    [$value[0], $value[1]] = [$value[1], $value[0]];

                    return $value;
                },
            ],
            'PLAN_1A_GATE_REQUEST_COUNT_DRIFT' => [
                static function (array $value): array {
                    $value[0]['request_count']++;

                    return $value;
                },
            ],
            'PLAN_1A_GATE_ASSERTION_COUNT_DRIFT' => [
                static function (array $value): array {
                    $value[0]['assertions']--;

                    return $value;
                },
            ],
        ];

        foreach ($mutations as $message => $family) {
            foreach ($family as $mutate) {
                try {
                    $this->invokeStatic('validateCases', [$mutate($records), $ids, 28, 132]);
                    self::fail('Expected '.$message);
                } catch (\PlanOneAGatesFailure $failure) {
                    self::assertSame($message, $failure->getMessage());
                    self::assertSame(4, $failure->exitStatus);
                }
            }
        }
    }

    public function test_ledger_rejects_command_result_summary_and_aggregate_mutations(): void
    {
        $mutations = [
            static function (array &$value): void {
                $value['commands'][0]['exit_code'] = 1;
            },
            static function (array &$value): void {
                $value['commands'][0]['status'] = 'skipped';
            },
            static function (array &$value): void {
                $value['commands'][0]['status'] = 'risky';
            },
            static function (array &$value): void {
                unset($value['commands'][0]['tests']);
            },
            static function (array &$value): void {
                $value['commands'][] = $value['commands'][0];
            },
            static function (array &$value): void {
                $value['commands'][1] = $value['commands'][0];
            },
            static function (array &$value): void {
                $value['commands'][0]['command'] .= ' --wrong-summary';
            },
            static function (array &$value): void {
                $value['commands'][0]['tests'] = 40;
            },
            static function (array &$value): void {
                $value['commands'][0]['tests'] = 42;
            },
            static function (array &$value): void {
                array_pop($value['commands']);
            },
        ];

        $this->assertEveryMutationRejected('plan-1a-command-ledger.valid.json', $mutations);
    }

    public function test_real_contract_aggregate_matches_authority_and_rejects_every_exit_and_summary_mutation(): void
    {
        $constant = (new ReflectionClass(\PlanOneAGates::class))->getReflectionConstant('CONTRACT_TESTS');
        self::assertNotFalse($constant);
        $process = new Process(
            [self::PHP, '-c', self::PHP_DIR, 'vendor/bin/phpunit', ...$constant->getValue(), '--colors=never'],
            $this->root(),
        );
        $process->setTimeout(180);
        $process->mustRun();
        $combined = $process->getOutput().$process->getErrorOutput();
        self::assertSame(
            1,
            preg_match_all('/OK \\(([1-9][0-9]*) tests?, ([1-9][0-9]*) assertions?\\)/', $combined, $matches),
        );
        $ledger = $this->decode('plan-1a-command-ledger.valid.json');
        $completion = $this->decode('plan-1a-completion.valid.json');
        $actual = ['tests' => (int) $matches[1][0], 'assertions' => (int) $matches[2][0]];
        self::assertSame(
            ['tests' => $ledger['commands'][0]['tests'], 'assertions' => $ledger['commands'][0]['assertions']],
            $actual,
        );
        self::assertSame(
            ['tests' => $completion['commands'][0]['tests'], 'assertions' => $completion['commands'][0]['assertions']],
            $actual,
        );

        $mutations = [
            ['', '', 1, 'PLAN_1A_GATE_CONTRACT_COMMAND_FAILED'],
            ['OK (400 tests, 3000 assertions) Skipped: 1', '', 0, 'PLAN_1A_GATE_CONTRACT_NON_PASS'],
            ['OK (400 tests, 3000 assertions) Risky: 1', '', 0, 'PLAN_1A_GATE_CONTRACT_NON_PASS'],
            ['', '', 0, 'PLAN_1A_GATE_CONTRACT_COUNT_DRIFT'],
            ['OK (400 tests, 3000 assertions) OK (400 tests, 3000 assertions)', '', 0, 'PLAN_1A_GATE_CONTRACT_COUNT_DRIFT'],
        ];

        foreach ($mutations as [$stdout, $stderr, $exit, $message]) {
            $this->setProcessResults([[$stdout, $stderr, $exit]]);

            try {
                $this->invokeStatic('runCommands', [$this->root(), '2026-07-26T00:00:00Z']);
                self::fail('Expected '.$message);
            } catch (\PlanOneAGatesFailure $failure) {
                self::assertSame($message, $failure->getMessage());
                self::assertSame(5, $failure->exitStatus);
            }
        }
    }

    public function test_static_command_rejects_nonzero_absent_duplicate_and_wrong_summaries(): void
    {
        $mutations = [
            ['', '', 1],
            ['', '', 0],
            ['[OK] No errors [OK] No errors', '', 0],
            ['[OK] One error', '', 0],
        ];

        foreach ($mutations as $staticResult) {
            $this->setProcessResults([
                ['OK (400 tests, 3000 assertions)', '', 0],
                $staticResult,
            ]);

            try {
                $this->invokeStatic('runCommands', [$this->root(), '2026-07-26T00:00:00Z']);
                self::fail('Expected static command rejection');
            } catch (\PlanOneAGatesFailure $failure) {
                self::assertSame('PLAN_1A_GATE_STATIC_COMMAND_FAILED', $failure->getMessage());
                self::assertSame(5, $failure->exitStatus);
            }
        }
    }

    public function test_process_override_rejects_caller_authored_result_shape(): void
    {
        $this->setStaticProperty('processOverride', static fn (): array => ['forged']);

        try {
            $this->invokeStatic('runCommands', [$this->root(), '2026-07-26T00:00:00Z']);
            self::fail('Expected invalid process result rejection');
        } catch (\PlanOneAGatesFailure $failure) {
            self::assertSame('PLAN_1A_GATE_PROCESS_OVERRIDE_INVALID', $failure->getMessage());
            self::assertSame(3, $failure->exitStatus);
        }
    }

    public function test_static_topology_substitution_is_rejected_by_closed_schema(): void
    {
        $this->setStaticProperty('topologyOverride', static fn (): array => [
            'global_middleware' => ['StaticSubstitute'],
        ]);

        try {
            $this->invokeStatic('build', [
                $this->root(),
                $this->git($this->root(), ['rev-parse', 'HEAD']),
                '2026-07-26T00:00:00Z',
            ]);
            self::fail('Expected substituted topology rejection');
        } catch (\PlanOneAGatesFailure $failure) {
            self::assertSame('PLAN_1A_GATE_ROUTE_SCHEMA_INVALID', $failure->getMessage());
            self::assertSame(3, $failure->exitStatus);
        }
    }

    public function test_reflection_harness_substitution_is_rejected_before_execution(): void
    {
        $this->setStaticProperty('harnessOverride', static fn (): \stdClass => new \stdClass);

        try {
            $this->invokeStatic('build', [
                $this->root(),
                $this->git($this->root(), ['rev-parse', 'HEAD']),
                '2026-07-26T00:00:00Z',
            ]);
            self::fail('Expected substituted harness rejection');
        } catch (\PlanOneAGatesFailure $failure) {
            self::assertSame('PLAN_1A_GATE_HARNESS_INVALID', $failure->getMessage());
            self::assertSame(4, $failure->exitStatus);
        }
    }

    public function test_contract_rejects_caller_authored_mode_status_count_and_result_inputs(): void
    {
        $mutations = [
            static function (array &$value): void {
                $value['verification_mode'] = 'caller_authored';
            },
            static function (array &$value): void {
                $value['status'] = 'passed_by_caller';
            },
            static function (array &$value): void {
                $value['counts']['assertions'] = 1;
            },
            static function (array &$value): void {
                $value['cases'][0]['action_calls'] = 1;
            },
        ];

        $this->assertEveryMutationRejected('plan-1a-ci-authorization.valid.json', $mutations);
    }

    public function test_normal_cli_requires_canonical_timestamp(): void
    {
        $options = \PlanOneAGates::parse($this->arguments('2026-07-26T00:00:00Z'));

        self::assertSame('normal', $options['mode']);
        self::assertSame('2026-07-26T00:00:00Z', $options['executed-at']);
    }

    public function test_check_cli_is_closed(): void
    {
        $options = \PlanOneAGates::parse([...$this->arguments('2026-07-26T00:00:00Z'), '--check']);

        self::assertSame('check', $options['mode']);
    }

    public function test_verify_existing_rejects_caller_timestamp(): void
    {
        $this->expectException(\PlanOneAGatesFailure::class);

        \PlanOneAGates::parse([...$this->arguments('2026-07-26T00:00:00Z'), '--verify-existing']);
    }

    public function test_unknown_cli_argument_is_rejected(): void
    {
        $this->expectException(\PlanOneAGatesFailure::class);

        \PlanOneAGates::parse(['--unknown=value']);
    }

    public function test_duplicate_cli_argument_is_rejected(): void
    {
        $this->expectException(\PlanOneAGatesFailure::class);

        \PlanOneAGates::parse([...$this->arguments('2026-07-26T00:00:00Z'), '--commit-sha='.str_repeat('a', 40)]);
    }

    public function test_check_with_missing_build_directory_performs_no_write(): void
    {
        $repository = $this->repository();
        $before = $this->snapshot($repository);
        $process = $this->runCli($repository, '--check');

        self::assertSame(6, $process->getExitCode());
        self::assertStringContainsString('PLAN_1A_GATE_OUTPUT_CREATE_FAILED', $process->getErrorOutput());
        self::assertSame($before, $this->snapshot($repository));
        self::assertDirectoryDoesNotExist($repository.'/build');
    }

    public function test_normal_dirty_preflight_leaves_stale_outputs_untouched(): void
    {
        $repository = $this->repository();
        $this->createStaleGateOutputs($repository);
        $before = $this->outputSnapshot($repository);
        file_put_contents($repository.'/seed.txt', 'dirty');
        $this->git($repository, ['add', 'seed.txt']);
        $process = $this->runCli($repository);

        self::assertSame(3, $process->getExitCode());
        self::assertStringContainsString('PLAN_1A_GATE_WORKTREE_DIRTY', $process->getErrorOutput());
        self::assertSame($before, $this->outputSnapshot($repository));
    }

    public function test_normal_head_mismatch_preflight_leaves_stale_outputs_untouched(): void
    {
        [$repository] = $this->precommitRepository();
        $this->createStaleGateOutputs($repository);
        $before = $this->outputSnapshot($repository);

        $exit = $this->executeDirect($repository, str_repeat('f', 40));

        self::assertSame(3, $exit);
        self::assertSame($before, $this->outputSnapshot($repository));
    }

    public function test_normal_cleans_stale_outputs_on_branch_mismatch(): void
    {
        [$repository, $head] = $this->executablePrecommitRepository();
        $this->createStaleGateOutputs($repository);
        $this->setStaticProperty('branchOverride', static fn (): string => 'feat/foreign-branch');

        $exit = $this->executeDirect($repository, $head);

        self::assertSame(3, $exit);
        self::assertSame([], $this->gateOutputFiles($repository));
        self::assertSame([], glob($repository.'/build/reports/.plan-1a-*.tmp') ?: []);
    }

    public function test_normal_cleans_stale_outputs_on_detached_head(): void
    {
        [$repository, $head] = $this->executablePrecommitRepository();
        $this->git($repository, ['checkout', '--detach', $head]);
        $this->createStaleGateOutputs($repository);

        $exit = $this->executeDirect($repository, $head);

        self::assertSame(3, $exit);
        self::assertSame([], $this->gateOutputFiles($repository));
        self::assertSame([], glob($repository.'/build/reports/.plan-1a-*.tmp') ?: []);
    }

    public function test_normal_cleans_stale_outputs_on_php_hash_mismatch(): void
    {
        [$repository, $head] = $this->executablePrecommitRepository();
        $this->createStaleGateOutputs($repository);
        $this->setStaticProperty('phpHashOverride', static fn (): string => str_repeat('0', 64));

        $exit = $this->executeDirect($repository, $head);

        self::assertSame(2, $exit);
        self::assertSame([], $this->gateOutputFiles($repository));
        self::assertSame([], glob($repository.'/build/reports/.plan-1a-*.tmp') ?: []);
    }

    public function test_normal_cleans_stale_outputs_on_schema_and_bootstrap_failures(): void
    {
        $failures = [
            static fn (): array => ['global_middleware' => ['substituted']],
            static function (): never {
                throw new \LogicException('bootstrap failed');
            },
        ];

        foreach ($failures as $failure) {
            [$repository, $head] = $this->executablePrecommitRepository();
            $this->createStaleGateOutputs($repository);
            $this->setStaticProperty('topologyOverride', $failure);

            $exit = $this->executeDirect($repository, $head);

            self::assertSame(3, $exit);
            self::assertSame([], $this->gateOutputFiles($repository));
            self::assertSame([], glob($repository.'/build/reports/.plan-1a-*.tmp') ?: []);
            $this->setStaticProperty('topologyOverride', null);
        }
    }

    public function test_check_rejects_staged_state_without_mutating_outputs(): void
    {
        $repository = $this->repository();
        $this->createStaleGateOutputs($repository);
        $before = $this->outputSnapshot($repository);
        file_put_contents($repository.'/seed.txt', 'dirty');
        $this->git($repository, ['add', 'seed.txt']);
        $process = $this->runCli($repository, '--check');

        self::assertSame(3, $process->getExitCode());
        self::assertStringContainsString('PLAN_1A_GATE_WORKTREE_DIRTY', $process->getErrorOutput());
        self::assertSame($before, $this->outputSnapshot($repository));
    }

    public function test_verify_existing_rejects_timestamp_divergence_without_writes(): void
    {
        [$repository] = $this->executablePrecommitRepository();
        mkdir($repository.'/build/reports', 0777, true);
        $timestamp = '2026-07-26T00:00:00Z';
        foreach (array_keys($this->bundle()) as $file) {
            $value = [
                'executed_at' => $timestamp,
                'commands' => $file === 'plan-1a-command-ledger.json'
                    ? [['executed_at' => '2026-07-26T00:00:01Z']]
                    : [],
            ];
            file_put_contents(
                $repository.'/build/reports/'.$file,
                json_encode($value, JSON_THROW_ON_ERROR)."\n",
            );
        }
        $before = $this->outputSnapshot($repository);
        $process = $this->runCli($repository, '--verify-existing');

        self::assertSame(6, $process->getExitCode());
        self::assertStringContainsString('PLAN_1A_GATE_TIMESTAMP_DIVERGENCE', $process->getErrorOutput());
        self::assertSame($before, $this->outputSnapshot($repository));
    }

    public function test_publish_writes_exact_bundle_with_ledger_last(): void
    {
        $directory = $this->temporaryDirectory().'/reports';
        mkdir($directory);
        $bundle = $this->bundle();

        $this->invokeStatic('publish', [$directory, $bundle]);

        self::assertSame(array_keys($bundle), array_map('basename', $this->gateOutputFilesFromDirectory($directory)));
        foreach ($bundle as $file => $bytes) {
            self::assertSame($bytes, file_get_contents($directory.'/'.$file));
        }
        self::assertFileExists($directory.'/plan-1a-command-ledger.json');
        self::assertSame([], glob($directory.'/.plan-1a-*.tmp') ?: []);
    }

    public function test_publish_failure_removes_every_output_and_temp(): void
    {
        $directory = $this->temporaryDirectory().'/reports';
        mkdir($directory);
        mkdir($directory.'/plan-1a-ci-authorization.json');

        try {
            $this->invokeStatic('publish', [$directory, $this->bundle()]);
            self::fail('Expected publication failure');
        } catch (\PlanOneAGatesFailure $failure) {
            self::assertSame(6, $failure->exitStatus);
        }

        self::assertSame([], $this->gateOutputFilesFromDirectory($directory));
        self::assertSame([], glob($directory.'/.plan-1a-*.tmp') ?: []);
    }

    public function test_every_publish_write_and_reread_fault_cleans_all_outputs_and_temps(): void
    {
        $boundaries = [];
        foreach (array_keys($this->bundle()) as $file) {
            foreach (['after_temporary_write', 'after_publish', 'after_reread'] as $stage) {
                $boundaries[] = $stage.':'.$file;
            }
        }

        foreach ($boundaries as $boundary) {
            $directory = $this->temporaryDirectory().'/reports';
            mkdir($directory);
            $this->setStaticProperty('faultOverride', static function (string $actual) use ($boundary): void {
                if ($actual === $boundary) {
                    throw new \PlanOneAGatesFailure(6, 'PLAN_1A_GATE_INJECTED_'.$boundary);
                }
            });

            try {
                $this->invokeStatic('publish', [$directory, $this->bundle()]);
                self::fail('Expected '.$boundary);
            } catch (\PlanOneAGatesFailure $failure) {
                self::assertSame('PLAN_1A_GATE_INJECTED_'.$boundary, $failure->getMessage());
                self::assertSame(6, $failure->exitStatus);
            }

            self::assertSame([], $this->gateOutputFilesFromDirectory($directory));
            self::assertSame([], glob($directory.'/.plan-1a-*.tmp') ?: []);
            $this->setStaticProperty('faultOverride', null);
        }
    }

    public function test_precommit_exact_unstaged_task_four_a2_set_is_rejected_globally(): void
    {
        [$repository, $head] = $this->precommitRepository();

        $this->expectException(\PlanOneAGatesFailure::class);
        $this->expectExceptionMessage('PLAN_1A_GATE_WORKTREE_DIRTY');
        $this->invokeStatic('validateGitState', [$repository, $head]);
    }

    public function test_precommit_partial_staged_task_four_a2_set_is_rejected(): void
    {
        [$repository, $head] = $this->precommitRepository();
        $this->git($repository, ['add', $this->taskFourA2Paths()[0]]);

        $this->expectException(\PlanOneAGatesFailure::class);

        $this->invokeStatic('validateGitState', [$repository, $head]);
    }

    public function test_precommit_extra_untracked_path_is_rejected(): void
    {
        [$repository, $head] = $this->precommitRepository();
        file_put_contents($repository.'/composer.json', "\n", FILE_APPEND);

        $this->expectException(\PlanOneAGatesFailure::class);

        $this->invokeStatic('validateGitState', [$repository, $head]);
    }

    public function test_clean_historical_task_four_a2_commit_is_not_a_supported_execution_phase(): void
    {
        [$repository, $commit] = $this->canonicalRepository();

        $this->expectException(\PlanOneAGatesFailure::class);
        $this->expectExceptionMessage('PLAN_1A_EXECUTION_PHASE_INVALID');
        $this->invokeStatic('validateGitState', [$repository, $commit]);
    }

    public function test_wrong_canonical_subject_is_rejected(): void
    {
        [$repository, $commit] = $this->canonicalRepository('wrong subject');

        $this->expectException(\PlanOneAGatesFailure::class);

        $this->invokeStatic('validateGitState', [$repository, $commit]);
    }

    public function test_canonical_worktree_byte_drift_is_rejected(): void
    {
        [$repository, $commit] = $this->canonicalRepository();
        file_put_contents($repository.'/scripts/reporting/run-plan-1a-gates.php', 'drift');

        $this->expectException(\PlanOneAGatesFailure::class);

        $this->invokeStatic('validateCanonicalTaskFourA2Commit', [$repository, $commit]);
    }

    public function test_canonical_tracked_schema_byte_drift_is_rejected(): void
    {
        [$repository, $commit] = $this->canonicalRepository();
        file_put_contents($repository.'/docs/reports/contracts/plan-1a-gate-evidence.schema.json', 'drift');

        $this->expectException(\PlanOneAGatesFailure::class);

        $this->invokeStatic('validateCanonicalTaskFourA2Commit', [$repository, $commit]);
    }

    public function test_canonical_tracked_output_is_rejected(): void
    {
        [$repository, $commit] = $this->canonicalRepository();
        mkdir($repository.'/build/reports', 0777, true);
        file_put_contents($repository.'/build/reports/plan-1a-route-snapshot.json', 'tracked');
        $this->git($repository, ['add', '-f', 'build/reports/plan-1a-route-snapshot.json']);
        $this->git($repository, ['commit', '-m', 'track forbidden output']);
        $trackedCommit = $this->git($repository, ['rev-parse', 'HEAD']);

        try {
            $this->invokeStatic('validateCanonicalTaskFourA2Commit', [$repository, $trackedCommit]);
            self::fail('Expected tracked output rejection');
        } catch (\PlanOneAGatesFailure $failure) {
            self::assertSame(3, $failure->exitStatus);
        }

        self::assertNotSame($commit, $trackedCommit);
    }

    public function test_junction_escape_is_rejected_without_touching_target(): void
    {
        $repository = $this->repository();
        $outside = $this->temporaryDirectory();
        $junction = $repository.'/build';
        $process = new Process([
            'powershell',
            '-NoProfile',
            '-Command',
            "New-Item -ItemType Junction -Path '".$junction."' -Target '".$outside."' | Out-Null",
        ]);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $before = $this->snapshot($outside);
        try {
            $result = $this->runCli($repository, '--check');

            self::assertSame(2, $result->getExitCode());
            self::assertStringContainsString('PLAN_1A_GATE_OUTPUT_PATH_INVALID', $result->getErrorOutput());
            self::assertSame($before, $this->snapshot($outside));
        } finally {
            if (is_dir($junction)) {
                rmdir($junction);
            }
        }
    }

    private function validates(array $value): bool
    {
        $schema = json_decode((string) file_get_contents($this->root().'/docs/reports/contracts/plan-1a-gate-evidence.schema.json'));

        return (new CompliantValidator)->validate(json_decode(json_encode($value, JSON_THROW_ON_ERROR)), $schema)->isValid();
    }

    private function assertEveryMutationRejected(string $fixture, array $mutations): void
    {
        foreach ($mutations as $index => $mutate) {
            $value = $this->decode($fixture);
            $mutate($value);

            self::assertFalse($this->validates($value), $fixture.' mutation '.$index.' was accepted');
        }
    }

    private function decode(string $file): array
    {
        return json_decode((string) file_get_contents($this->fixtureDirectory().'/'.$file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function invokeStatic(string $method, array $arguments): mixed
    {
        return (new ReflectionClass(\PlanOneAGates::class))->getMethod($method)->invoke(null, ...$arguments);
    }

    private function setStaticProperty(string $property, mixed $value): void
    {
        (new ReflectionClass(\PlanOneAGates::class))->getProperty($property)->setValue(null, $value);
    }

    private function setProcessResults(array $results): void
    {
        $this->setStaticProperty('processOverride', static function () use (&$results): array {
            $result = array_shift($results);
            self::assertIsArray($result);

            return $result;
        });
    }

    private function arguments(string $timestamp): array
    {
        return [
            '--repository-root='.$this->root(),
            '--commit-sha='.str_repeat('a', 40),
            '--output-directory=build/reports',
            '--executed-at='.$timestamp,
        ];
    }

    private function runCli(string $repository, string ...$extra): Process
    {
        $arguments = [
            self::PHP,
            '-c',
            self::PHP_DIR,
            $this->root().'/scripts/reporting/run-plan-1a-gates.php',
            '--repository-root='.$repository,
            '--commit-sha='.$this->git($repository, ['rev-parse', 'HEAD']),
            '--output-directory=build/reports',
        ];
        if (! in_array('--verify-existing', $extra, true)) {
            $arguments[] = '--executed-at=2026-07-26T00:00:00Z';
        }
        $arguments = [...$arguments, ...$extra];
        $process = new Process($arguments, $this->root());
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    private function executeDirect(string $repository, string $commit): int
    {
        $this->setStaticProperty('phpVersionOverride', static fn (): string => '8.2.29');

        return \PlanOneAGates::execute([
            'run-plan-1a-gates.php',
            '--repository-root='.$repository,
            '--commit-sha='.$commit,
            '--output-directory=build/reports',
            '--executed-at=2026-07-26T00:00:00Z',
        ]);
    }

    private function repository(): string
    {
        $repository = $this->temporaryDirectory();
        $this->git($repository, ['init']);
        $this->git($repository, ['checkout', '-b', 'feat/reports-canonical-backend']);
        $this->git($repository, ['config', 'user.email', 'reports@example.test']);
        $this->git($repository, ['config', 'user.name', 'Reports Test']);
        file_put_contents($repository.'/seed.txt', 'seed');
        file_put_contents($repository.'/.gitignore', $this->reportIgnoreRules());
        $this->git($repository, ['add', 'seed.txt', '.gitignore']);
        $this->git($repository, ['commit', '-m', 'base']);

        return $repository;
    }

    private function precommitRepository(): array
    {
        $repository = $this->temporaryDirectory().'/repository';
        $process = new Process(['git', 'clone', '--no-hardlinks', '--quiet', $this->root(), $repository]);
        $process->setTimeout(30);
        $process->mustRun();
        $this->git($repository, ['config', 'user.email', 'reports@example.test']);
        $this->git($repository, ['config', 'user.name', 'Reports Test']);
        foreach ($this->taskFourA2Paths() as $path) {
            $this->write($repository.'/'.$path, (string) file_get_contents($this->root().'/'.$path));
        }

        return [$repository, $this->git($repository, ['rev-parse', 'HEAD'])];
    }

    private function executablePrecommitRepository(): array
    {
        $repository = $this->temporaryDirectory().'/repository';
        $process = new Process(['git', 'clone', '--no-hardlinks', '--quiet', $this->root(), $repository]);
        $process->setTimeout(30);
        $process->mustRun();
        $this->git($repository, ['config', 'user.email', 'reports@example.test']);
        $this->git($repository, ['config', 'user.name', 'Reports Test']);
        $this->git($repository, ['checkout', '--detach', '470fecd5733021421dbc9b36c1d2a410ef27cc42']);
        foreach (\PlanOneAExecutionPhaseAuthority::taskFourGPaths() as $path) {
            $this->write($repository.'/'.$path, (string) file_get_contents($this->root().'/'.$path));
        }
        $this->git($repository, ['add', '--', ...\PlanOneAExecutionPhaseAuthority::taskFourGPaths()]);
        $this->git($repository, ['commit', '-m', 'fix[reports]: изолировать HTTP evidence от базы']);
        $this->git($repository, ['branch', '-f', 'feat/reports-canonical-backend', 'HEAD']);
        $this->git($repository, ['checkout', 'feat/reports-canonical-backend']);

        return [$repository, $this->git($repository, ['rev-parse', 'HEAD'])];
    }

    private function canonicalRepository(string $subject = 'fix[reports]: типизировать нарушения идентичности снимков'): array
    {
        [$repository] = $this->precommitRepository();
        $this->git($repository, ['add', '--', ...$this->taskFourA2Paths()]);
        $this->git($repository, ['commit', '-m', $subject]);

        return [$repository, $this->git($repository, ['rev-parse', 'HEAD'])];
    }

    private function historicalMutationCommits(): array
    {
        $repository = $this->temporaryDirectory().'/history-mutations';
        $process = new Process(['git', 'clone', '--no-hardlinks', '--quiet', $this->root(), $repository]);
        $process->setTimeout(30);
        $process->mustRun();
        $this->git($repository, ['config', 'user.email', 'reports@example.test']);
        $this->git($repository, ['config', 'user.name', 'Reports Test']);
        $taskFourA = '0b581469a3ad39d4ce5eff5c41072f5ef3f745f7';
        $taskFourAParent = '786e5f3433d04baf35c81789178e1e83012e0916';
        $taskFourB = '973aabb17516c0ff9bc7d5a87b3ab6eb8732f333';
        $taskFourATree = $this->git($repository, ['show', '-s', '--format=%T', $taskFourA]);
        $taskFourBTree = $this->git($repository, ['show', '-s', '--format=%T', $taskFourB]);
        $wrongParent = $this->git($repository, ['commit-tree', $taskFourATree, '-p', $taskFourAParent.'^', '-m', 'fix[reports]: зафиксировать классификацию и печать снимков']);
        $wrongSubject = $this->git($repository, ['commit-tree', $taskFourBTree, '-p', $taskFourA, '-m', 'wrong subject']);

        $this->git($repository, ['checkout', '--detach', $taskFourA]);
        file_put_contents($repository.'/app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotRef.php', "\n", FILE_APPEND);
        $this->git($repository, ['add', 'app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotRef.php']);
        $alteredTaskFourATree = $this->git($repository, ['write-tree']);
        $alteredTaskFourABlob = $this->git($repository, ['commit-tree', $alteredTaskFourATree, '-p', $taskFourAParent, '-m', 'fix[reports]: зафиксировать классификацию и печать снимков']);

        $this->git($repository, ['reset', '--hard', $taskFourB]);
        file_put_contents($repository.'/app/BusinessModules/Core/Reporting/Application/Dispatch/ReportAuditIntent.php', "\n", FILE_APPEND);
        $this->git($repository, ['add', 'app/BusinessModules/Core/Reporting/Application/Dispatch/ReportAuditIntent.php']);
        $alteredTaskFourBTree = $this->git($repository, ['write-tree']);
        $alteredTaskFourBBlob = $this->git($repository, ['commit-tree', $alteredTaskFourBTree, '-p', $taskFourA, '-m', 'feat[reports]: добавить надежную доставку заданий отчетов']);

        $this->git($repository, ['reset', '--hard', $taskFourB]);
        $this->git($repository, ['rm', '--cached', '--', 'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditDispatcher.php']);
        unlink($repository.'/app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportAuditDispatcher.php');
        file_put_contents($repository.'/composer.json', "\n", FILE_APPEND);
        $this->git($repository, ['add', 'composer.json']);
        $differentPathsTree = $this->git($repository, ['write-tree']);
        $differentPaths = $this->git($repository, ['commit-tree', $differentPathsTree, '-p', $taskFourA, '-m', 'feat[reports]: добавить надежную доставку заданий отчетов']);

        return [$repository, [
            'task4a_wrong_parent' => $wrongParent,
            'task4a_altered_blob' => $alteredTaskFourABlob,
            'task4b_wrong_subject' => $wrongSubject,
            'task4b_same_count_different_paths' => $differentPaths,
            'task4b_altered_blob' => $alteredTaskFourBBlob,
        ]];
    }

    private function taskFourA2Paths(): array
    {
        $constant = (new ReflectionClass(\PlanOneAGates::class))->getReflectionConstant('TASK_FOUR_A2_PATHS');
        self::assertNotFalse($constant);

        return $constant->getValue();
    }

    private function assertStaticPathsCoverExactTaskFourAChangedPhpTests(): void
    {
        $reflection = new ReflectionClass(\PlanOneAGates::class);
        $staticPaths = $reflection->getReflectionConstant('STATIC_PATHS');
        self::assertNotFalse($staticPaths);

        $expected = array_values(array_filter(
            $this->taskFourA2Paths(),
            static fn (string $path): bool => (
                str_starts_with($path, 'tests/Architecture/')
                || str_starts_with($path, 'tests/Feature/')
                || str_starts_with($path, 'tests/Unit/')
            ) && str_ends_with($path, '.php'),
        ));
        $actual = array_values(array_intersect($staticPaths->getValue(), $expected));
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);

        self::assertSame($expected, $actual);
    }

    private function bundle(): array
    {
        return [
            'plan-1a-route-snapshot.json' => "route\n",
            'plan-1a-ci-authorization.json' => "authorization\n",
            'plan-1a-ci-malformed.json' => "malformed\n",
            'plan-1a-command-ledger.json' => "ledger\n",
        ];
    }

    private function createStaleGateOutputs(string $repository): void
    {
        mkdir($repository.'/build/reports', 0777, true);
        foreach ($this->bundle() as $file => $bytes) {
            file_put_contents($repository.'/build/reports/'.$file, $bytes);
        }
    }

    private function gateOutputFiles(string $repository): array
    {
        return $this->gateOutputFilesFromDirectory($repository.'/build/reports');
    }

    private function gateOutputFilesFromDirectory(string $directory): array
    {
        $files = [];
        foreach (array_keys($this->bundle()) as $file) {
            if (is_file($directory.'/'.$file)) {
                $files[] = $directory.'/'.$file;
            }
        }

        return $files;
    }

    private function outputSnapshot(string $repository): array
    {
        $snapshot = [];
        foreach ($this->gateOutputFiles($repository) as $path) {
            $snapshot[basename($path)] = [hash_file('sha256', $path), filemtime($path)];
        }

        return $snapshot;
    }

    private function snapshot(string $directory): array
    {
        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($directory) + 1));
            $snapshot[$relative] = $item->isFile() ? hash_file('sha256', $item->getPathname()) : 'directory';
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    private function git(string $repository, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $repository);
        $process->mustRun();

        return trim($process->getOutput());
    }

    private function gitPaths(string $repository, array $arguments): array
    {
        $output = $this->git($repository, $arguments);

        return $output === '' ? [] : preg_split('/\R/', $output);
    }

    private function write(string $path, string $bytes): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $bytes);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/most-plan1a-runner-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                chmod($item->getPathname(), 0777);
                rmdir($item->getPathname());
            } else {
                chmod($item->getPathname(), 0666);
                unlink($item->getPathname());
            }
        }
        chmod($directory, 0777);
        rmdir($directory);
    }

    private function gateFixtureNames(): array
    {
        return [
            'plan-1a-route-snapshot.valid.json',
            'plan-1a-command-ledger.valid.json',
            'plan-1a-ci-authorization.valid.json',
            'plan-1a-ci-malformed.valid.json',
        ];
    }

    private function reportIgnoreRules(): string
    {
        return implode("\n", [
            '/build/reports/task-7-composer-evidence.json',
            '/build/reports/plan-1a-route-snapshot.json',
            '/build/reports/plan-1a-command-ledger.json',
            '/build/reports/plan-1a-ci-authorization.json',
            '/build/reports/plan-1a-ci-malformed.json',
            '/build/reports/plan-1a-completion.json',
            '',
        ]);
    }

    private function fixtureDirectory(): string
    {
        return $this->root().'/tests/Fixtures/Reporting/Evidence';
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
