<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Tooling;

use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 4).'/scripts/reporting/build-plan-1a-evidence.php';

final class BuildPlanOneAEvidenceTest extends TestCase
{
    private const PHP = 'C:/Users/kamilgaraev/AppData/Local/CodexToolchains/most-reports/php-8.2.29-nts-vs16-x64/php.exe';

    private const PHP_DIR = 'C:/Users/kamilgaraev/AppData/Local/CodexToolchains/most-reports/php-8.2.29-nts-vs16-x64';

    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setStaticOverride('phpVersionOverride', static fn (): string => '8.2.29');
    }

    protected function tearDown(): void
    {
        foreach (['faultOverride', 'phpHashOverride', 'phpVersionOverride', 'branchOverride', 'errorOverride', 'taskSevenRegenerationOverride', 'taskSevenProvenanceOverride', 'historicalPredicateOverride'] as $property) {
            $this->setStaticOverride($property, null);
        }
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeTree($directory);
        }
    }

    public function test_completion_fixture_validates_against_schema(): void
    {
        self::assertTrue($this->completionValidates($this->completion()));
    }

    public function test_completion_schema_rejects_extra_digest(): void
    {
        $completion = $this->completion();
        $completion['command_ledger_sha256'] = str_repeat('a', 64);

        self::assertFalse($this->completionValidates($completion));
    }

    public function test_completion_schema_rejects_command_reordering(): void
    {
        $completion = $this->completion();
        [$completion['commands'][0], $completion['commands'][1]] = [$completion['commands'][1], $completion['commands'][0]];

        self::assertFalse($this->completionValidates($completion));
    }

    public function test_completion_schema_rejects_caller_authored_status_scalar(): void
    {
        $completion = $this->completion();
        $completion['status'] = 'passed_by_caller';

        self::assertFalse($this->completionValidates($completion));
    }

    public function test_completion_has_exact_five_digest_leaves(): void
    {
        self::assertSame(5, $this->digestCount($this->completion()));
    }

    public function test_builder_cli_accepts_three_closed_modes(): void
    {
        self::assertSame('contract-lock', $this->parse('contract-lock', 'docs/reports/contracts/plan-1a-contract-lock.json', false)['mode']);
        self::assertSame('task-7', $this->parse('task-7', 'build/reports/task-7-composer-evidence.json', false)['mode']);
        self::assertSame('completion', $this->parse('completion', 'build/reports/plan-1a-completion.json', false)['mode']);
    }

    public function test_builder_declares_the_exact_task_four_a_subject_and_manifest(): void
    {
        $constants = (new ReflectionClass(\PlanOneAEvidence::class))->getConstants();
        $paths = $constants['TASK_FOUR_A_PATHS'];
        sort($paths, SORT_STRING);

        self::assertSame('fix[reports]: зафиксировать классификацию и печать снимков', $constants['TASK_FOUR_A_SUBJECT']);
        self::assertCount(53, $constants['TASK_FOUR_A_PATHS']);
        self::assertSame($constants['TASK_FOUR_A_PATHS'], array_values(array_unique($constants['TASK_FOUR_A_PATHS'])));
        self::assertSame($paths, $constants['TASK_FOUR_A_PATHS']);
    }

    public function test_builder_declares_the_exact_task_four_a2_subject_parent_manifest_and_lineage(): void
    {
        $constants = (new ReflectionClass(\PlanOneAEvidence::class))->getConstants();

        self::assertSame('fix[reports]: типизировать нарушения идентичности снимков', $constants['TASK_FOUR_A2_SUBJECT']);
        self::assertSame('973aabb17516c0ff9bc7d5a87b3ab6eb8732f333', $constants['TASK_FOUR_A2_PARENT']);
        self::assertCount(16, $constants['TASK_FOUR_A2_PATHS']);
        self::assertSame($constants['TASK_FOUR_A2_PATHS'], array_values(array_unique($constants['TASK_FOUR_A2_PATHS'])));
        $sorted = $constants['TASK_FOUR_A2_PATHS'];
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $constants['TASK_FOUR_A2_PATHS']);
        self::assertSame(['Task 4a exact53', 'Task 4b exact39', 'Task 4a2 exact16'], $constants['TASK_FOUR_A2_LINEAGE']);
    }

    public function test_builder_declares_the_forward_only_task_four_e_contract(): void
    {
        $constants = (new ReflectionClass(\PlanOneAEvidence::class))->getConstants();

        self::assertSame('feat[reports]: типизировать ресурсы и текущую авторизацию', $constants['TASK_FOUR_E_SUBJECT']);
        self::assertSame('1934f947a44aa5221b5aa4cbd8c03963f5f1c005', $constants['TASK_FOUR_E_PARENT']);
        self::assertCount(78, $constants['TASK_FOUR_E_PATHS']);
        self::assertSame($constants['TASK_FOUR_E_PATHS'], array_values(array_unique($constants['TASK_FOUR_E_PATHS'])));
        self::assertSame(
            ['Task 4a exact53', 'Task 4b exact39', 'Task 4a2 exact16', 'Task 4c exact15', 'Task 4d exact6', 'Task 4e exact78'],
            $constants['TASK_FOUR_E_LINEAGE'],
        );

        $lock = json_decode((string) file_get_contents($this->root().'/docs/reports/contracts/plan-1a-contract-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $builder = new \PlanOneAEvidence($this->repository());
        $this->invoke($builder, 'validateTaskFourELock', [$lock]);

        foreach (['subject', 'parent_commit_sha', 'tracked_paths', 'lineage', 'typed_resources', 'typed_decisions', 'queue_authorization', 'prohibitions', 'migration_cutover', 'resource_registry', 'authorization_matrices', 'ownership_audit'] as $field) {
            $candidate = $lock;
            unset($candidate['task_4e'][$field]);
            try {
                $this->invoke($builder, 'validateTaskFourELock', [$candidate]);
                self::fail('Missing Task 4e field was accepted: '.$field);
            } catch (\PlanOneAEvidenceFailure $failure) {
                self::assertSame('PLAN_1A_TASK_4E_LOCK_INVALID', $failure->getMessage());
            }
        }
    }

    public function test_completion_requires_closed_task_four_e_evidence(): void
    {
        $completion = $this->completion();
        self::assertArrayHasKey('task_4e', $completion);
        self::assertTrue($this->completionValidates($completion));

        unset($completion['task_4e']['authorization_matrices']['repeatable_read_races']);
        self::assertFalse($this->completionValidates($completion));
    }

    public function test_task_four_e_matrix_inventories_are_derived_from_real_test_methods_and_providers(): void
    {
        $builder = new \PlanOneAEvidence($this->repository());
        $inventories = $this->invoke($builder, 'taskFourEMatrixInventories', []);

        self::assertSame([
            'organization_scope' => 11,
            'project_scope' => 8,
            'current_abac' => 16,
            'typed_resources' => 17,
            'repeatable_read_races' => 8,
        ], array_map('count', $inventories));
        self::assertContains(
            'test_closed_abac_behavior_matrix::current organization role',
            $inventories['current_abac'],
        );
        self::assertContains(
            'test_each_decision_identity_mutation_is_normalized_to_scope_forbidden::actor id',
            $inventories['typed_resources'],
        );
        self::assertContains(
            'test_system_role_change_is_snapshot_consistent_and_next_invocation_denies',
            $inventories['repeatable_read_races'],
        );
    }

    public function test_task_four_a2_lock_rejects_every_closed_contract_mutation(): void
    {
        $lock = json_decode((string) file_get_contents($this->root().'/docs/reports/contracts/plan-1a-contract-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $builder = new \PlanOneAEvidence($this->repository());
        $this->invoke($builder, 'validateTaskFourA2Lock', [$lock]);
        $mutations = [
            static function (array &$value): void {
                unset($value['task_4a2']);
            },
            static function (array &$value): void {
                $value['task_4a2']['extra'] = true;
            },
            static function (array &$value): void {
                [$value['task_4a2']['tracked_paths'][0], $value['task_4a2']['tracked_paths'][1]] = [$value['task_4a2']['tracked_paths'][1], $value['task_4a2']['tracked_paths'][0]];
            },
            static function (array &$value): void {
                $value['task_4a2']['subject'] = 'wrong';
            },
            static function (array &$value): void {
                $value['task_4a2']['parent_commit_sha'] = str_repeat('0', 40);
            },
            static function (array &$value): void {
                array_pop($value['task_4a2']['identity_violation_reasons']);
            },
            static function (array &$value): void {
                $value['task_4a2']['exception_message'] = 'reason_in_message';
            },
            static function (array &$value): void {
                $value['task_4a']['tracked_paths'] = $value['task_4a2']['tracked_paths'];
            },
        ];

        foreach ($mutations as $index => $mutate) {
            $candidate = $lock;
            $mutate($candidate);
            try {
                $this->invoke($builder, 'validateTaskFourA2Lock', [$candidate]);
                self::fail('Mutation '.$index.' was accepted');
            } catch (\PlanOneAEvidenceFailure $failure) {
                self::assertSame('PLAN_1A_TASK_4A2_LOCK_INVALID', $failure->getMessage());
            }
        }
    }

    public function test_historical_task_four_a_and_four_b_mutation_families_are_rejected(): void
    {
        [$repository, $mutations] = $this->historicalMutationCommits();
        $builder = new \PlanOneAEvidence($repository);
        $constants = (new ReflectionClass(\PlanOneAEvidence::class))->getConstants();
        $cases = [
            'task4a_wrong_parent' => [$constants['TASK_FOUR_A_PARENT'], $constants['TASK_FOUR_A_TREE'], $constants['TASK_FOUR_A_SUBJECT'], $constants['TASK_FOUR_A_PATHS'], 'parent', 'PLAN_1A_EVIDENCE_TASK_4A_HISTORY_PARENT_INVALID'],
            'task4a_altered_blob' => [$constants['TASK_FOUR_A_PARENT'], $constants['TASK_FOUR_A_TREE'], $constants['TASK_FOUR_A_SUBJECT'], $constants['TASK_FOUR_A_PATHS'], 'tree', 'PLAN_1A_EVIDENCE_TASK_4A_HISTORY_TREE_INVALID'],
            'task4b_wrong_subject' => [$constants['TASK_FOUR_A_COMMIT'], $constants['TASK_FOUR_B_TREE'], $constants['TASK_FOUR_B_SUBJECT'], $constants['TASK_FOUR_B_PATHS'], 'subject', 'PLAN_1A_EVIDENCE_TASK_4B_HISTORY_SUBJECT_INVALID'],
            'task4b_same_count_different_paths' => [$constants['TASK_FOUR_A_COMMIT'], $this->git($repository, ['show', '-s', '--format=%T', $mutations['task4b_same_count_different_paths']]), $constants['TASK_FOUR_B_SUBJECT'], $constants['TASK_FOUR_B_PATHS'], 'paths', 'PLAN_1A_EVIDENCE_TASK_4B_HISTORY_PATHS_INVALID'],
            'task4b_altered_blob' => [$constants['TASK_FOUR_A_COMMIT'], $constants['TASK_FOUR_B_TREE'], $constants['TASK_FOUR_B_SUBJECT'], $constants['TASK_FOUR_B_PATHS'], 'tree', 'PLAN_1A_EVIDENCE_TASK_4B_HISTORY_TREE_INVALID'],
        ];

        foreach ($cases as $name => [$parent, $tree, $subject, $paths, $boundary, $message]) {
            $arguments = [$mutations[$name], $mutations[$name], $parent, $tree, $subject, $paths, str_starts_with($name, 'task4a_') ? 'PLAN_1A_EVIDENCE_TASK_4A_HISTORY' : 'PLAN_1A_EVIDENCE_TASK_4B_HISTORY'];
            self::assertTrue($this->historicalMutationRejected($builder, $arguments, $message), $name);
            $this->setStaticOverride('historicalPredicateOverride', static fn (string $actualBoundary, bool $actual): bool => $actualBoundary === $boundary ? true : $actual);
            self::assertFalse($this->historicalMutationRejected($builder, $arguments, $message), $name.' predicate bypass was not detected');
            $this->setStaticOverride('historicalPredicateOverride', null);
        }

        self::assertTrue($this->historicalWrapperRejected($builder, 'validateHistoricalTaskFourACommit', $mutations['task4a_wrong_parent'], 'PLAN_1A_EVIDENCE_TASK_4A_HISTORY_COMMIT_INVALID'));
        self::assertTrue($this->historicalWrapperRejected($builder, 'validateHistoricalTaskFourBCommit', $mutations['task4b_wrong_subject'], 'PLAN_1A_EVIDENCE_TASK_4B_HISTORY_COMMIT_INVALID'));
        $this->git($repository, ['replace', '-f', '0b581469a3ad39d4ce5eff5c41072f5ef3f745f7', $mutations['task4a_wrong_parent']]);
        $this->git($repository, ['replace', '-f', '973aabb17516c0ff9bc7d5a87b3ab6eb8732f333', $mutations['task4b_wrong_subject']]);
        $this->invoke($builder, 'validateHistoricalTaskLineage', []);
        self::assertCount(2, $this->gitPaths($repository, ['replace', '-l']));
    }

    private function historicalMutationRejected(\PlanOneAEvidence $builder, array $arguments, string $message): bool
    {
        try {
            $this->invoke($builder, 'validateHistoricalCommit', $arguments);
        } catch (\PlanOneAEvidenceFailure $failure) {
            return $failure->getMessage() === $message;
        }

        return false;
    }

    private function historicalWrapperRejected(\PlanOneAEvidence $builder, string $method, string $commit, string $message): bool
    {
        try {
            $this->invoke($builder, $method, [$commit]);
        } catch (\PlanOneAEvidenceFailure $failure) {
            return $failure->getMessage() === $message;
        }

        return false;
    }

    public function test_contract_lock_check_is_accepted_for_non_mutating_precommit_replay(): void
    {
        self::assertTrue($this->parse('contract-lock', 'docs/reports/contracts/plan-1a-contract-lock.json', true)['check']);
    }

    public function test_wrong_fixed_output_is_rejected(): void
    {
        $this->expectException(\PlanOneAEvidenceFailure::class);

        $this->parse('completion', 'foreign.json', false);
    }

    public function test_check_with_missing_build_directory_performs_no_write(): void
    {
        $repository = $this->repository();
        $before = $this->snapshot($repository);
        $process = $this->runCli(
            $repository,
            'task-7',
            'build/reports/task-7-composer-evidence.json',
            '--check',
        );

        self::assertSame(2, $process->getExitCode());
        self::assertStringContainsString('PLAN_1A_EVIDENCE_OUTPUT_PATH_INVALID', $process->getErrorOutput());
        self::assertSame($before, $this->snapshot($repository));
        self::assertDirectoryDoesNotExist($repository.'/build');
    }

    public function test_contract_lock_staged_failure_cleans_all_mode_outputs(): void
    {
        [$repository] = $this->precommitRepository();
        mkdir($repository.'/build/reports', 0777, true);
        file_put_contents($repository.'/build/reports/task-7-composer-evidence.json', 'stale');
        file_put_contents($repository.'/docs/reports/contracts/plan-1a-contract-lock.sha256', 'stale');
        $this->git($repository, ['add', $this->taskFourA2PreGenerationPaths()[0]]);
        $process = $this->runCli(
            $repository,
            'contract-lock',
            'docs/reports/contracts/plan-1a-contract-lock.json',
        );

        self::assertSame(3, $process->getExitCode());
        self::assertStringContainsString('PLAN_1A_EVIDENCE_PRECOMMIT_STATE_INVALID', $process->getErrorOutput());
        self::assertFileDoesNotExist($repository.'/build/reports/task-7-composer-evidence.json');
        self::assertFileDoesNotExist($repository.'/docs/reports/contracts/plan-1a-contract-lock.json');
        self::assertFileDoesNotExist($repository.'/docs/reports/contracts/plan-1a-contract-lock.sha256');
        self::assertSame([], glob($repository.'/build/reports/.plan-1a-*.tmp') ?: []);
    }

    public function test_publish_group_writes_and_rereads_atomic_pair(): void
    {
        $repository = $this->repository();
        $builder = new \PlanOneAEvidence($repository);
        $directory = $repository.'/docs/reports/contracts';
        mkdir($directory, 0777, true);
        $outputs = [
            $directory.'/lock.json' => "lock\n",
            $directory.'/lock.sha256' => "hash\n",
        ];

        $this->invoke($builder, 'publishGroup', [$outputs]);

        self::assertSame("lock\n", file_get_contents($directory.'/lock.json'));
        self::assertSame("hash\n", file_get_contents($directory.'/lock.sha256'));
        self::assertSame([], glob($directory.'/.plan-1a-*.tmp') ?: []);
    }

    public function test_publish_group_failure_removes_partial_pair_and_temps(): void
    {
        $repository = $this->repository();
        $builder = new \PlanOneAEvidence($repository);
        $directory = $repository.'/docs/reports/contracts';
        mkdir($directory, 0777, true);
        mkdir($directory.'/lock.sha256');

        try {
            $this->invoke($builder, 'publishGroup', [[
                $directory.'/lock.json' => "lock\n",
                $directory.'/lock.sha256' => "hash\n",
            ]]);
            self::fail('Expected publication failure');
        } catch (\PlanOneAEvidenceFailure $failure) {
            self::assertSame(6, $failure->exitStatus);
        }

        self::assertFileDoesNotExist($directory.'/lock.json');
        self::assertSame([], glob($directory.'/.plan-1a-*.tmp') ?: []);
    }

    #[DataProvider('publicationFaultBoundaries')]
    public function test_publish_group_fault_boundaries_leave_no_partial_output_or_temp(string $boundary): void
    {
        $repository = $this->repository();
        $builder = new \PlanOneAEvidence($repository);
        $directory = $repository.'/docs/reports/contracts';
        mkdir($directory, 0777, true);
        $this->setBuilderFault(static function (string $actual) use ($boundary): void {
            if ($actual === $boundary) {
                throw new \RuntimeException('fault '.$boundary);
            }
        });

        try {
            $this->invoke($builder, 'publishGroup', [[
                $directory.'/lock.json' => "lock\n",
                $directory.'/lock.sha256' => "hash\n",
            ]]);
            self::fail('Expected injected publication fault');
        } catch (\RuntimeException $failure) {
            self::assertSame('fault '.$boundary, $failure->getMessage());
        } finally {
            $this->setBuilderFault(null);
        }

        self::assertFileDoesNotExist($directory.'/lock.json');
        self::assertFileDoesNotExist($directory.'/lock.sha256');
        self::assertSame([], glob($directory.'/.plan-1a-*.tmp') ?: []);
    }

    public static function publicationFaultBoundaries(): array
    {
        return array_merge(array_map(
            static fn (string $basename): array => [
                'after_temporary_write:'.$basename,
            ],
            ['lock.json', 'lock.sha256'],
        ), array_map(
            static fn (string $basename): array => [
                'after_publish:'.$basename,
            ],
            ['lock.json', 'lock.sha256'],
        ), array_map(
            static fn (string $basename): array => [
                'after_reread:'.$basename,
            ],
            ['lock.json', 'lock.sha256'],
        ));
    }

    #[DataProvider('gateFiles')]
    public function test_each_completion_gate_is_required(string $file): void
    {
        $repository = $this->repository();
        mkdir($repository.'/build/reports', 0777, true);
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_GATE_ARTIFACT_MISSING');

        $this->invoke($builder, 'readGate', [$file]);
    }

    public static function gateFiles(): array
    {
        return array_map(static fn (string $file): array => [$file], [
            'plan-1a-route-snapshot.json',
            'plan-1a-command-ledger.json',
            'plan-1a-ci-authorization.json',
            'plan-1a-ci-malformed.json',
        ]);
    }

    #[DataProvider('gateMutationCases')]
    public function test_builder_schema_rejects_each_gate_mutation(string $fixture, string $mutation): void
    {
        $artifact = json_decode((string) file_get_contents($this->root().'/tests/Fixtures/Reporting/Evidence/'.$fixture), true, 512, JSON_THROW_ON_ERROR);
        match ($mutation) {
            'ledger_order' => $artifact['commands'] = array_reverse($artifact['commands']),
            'ledger_skipped' => $artifact['commands'][0]['skipped'] = 1,
            'ledger_count' => $artifact['commands'][0]['tests'] = 0,
            'auth_mode' => $artifact['verification_mode'] = 'forged',
            'auth_count' => $artifact['counts']['passed'] = 21,
            'auth_case' => $artifact['cases'][0]['status'] = 200,
            'malformed_order' => $artifact['cases'] = array_reverse($artifact['cases']),
            'malformed_count' => $artifact['counts']['http_requests'] = 1,
            'malformed_case' => $artifact['cases'][0]['response_codes'] = [null],
            default => throw new \LogicException('Unknown mutation'),
        };
        $builder = new \PlanOneAEvidence($this->root());

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_EVIDENCE_SCHEMA_INVALID');

        $this->invoke($builder, 'validateSchema', [json_encode($artifact, JSON_THROW_ON_ERROR), 'docs/reports/contracts/plan-1a-gate-evidence.schema.json']);
    }

    public static function gateMutationCases(): array
    {
        return [
            ['plan-1a-command-ledger.valid.json', 'ledger_order'],
            ['plan-1a-command-ledger.valid.json', 'ledger_skipped'],
            ['plan-1a-command-ledger.valid.json', 'ledger_count'],
            ['plan-1a-ci-authorization.valid.json', 'auth_mode'],
            ['plan-1a-ci-authorization.valid.json', 'auth_count'],
            ['plan-1a-ci-authorization.valid.json', 'auth_case'],
            ['plan-1a-ci-malformed.valid.json', 'malformed_order'],
            ['plan-1a-ci-malformed.valid.json', 'malformed_count'],
            ['plan-1a-ci-malformed.valid.json', 'malformed_case'],
        ];
    }

    #[DataProvider('invalidBranches')]
    public function test_runtime_preflight_rejects_wrong_or_detached_branch_for_each_mode(string $branch, string $mode): void
    {
        $builder = new \PlanOneAEvidence($this->repository());
        $this->setStaticOverride('branchOverride', static fn (): string => $branch);

        try {
            $this->expectException(\PlanOneAEvidenceFailure::class);
            $this->expectExceptionMessage('PLAN_1A_EVIDENCE_BRANCH_INVALID');
            $this->invoke($builder, 'validateRuntimePreflight', [$mode]);
        } finally {
            $this->setStaticOverride('branchOverride', null);
        }
    }

    public static function invalidBranches(): array
    {
        return [
            ['feature/wrong', 'contract-lock'],
            ['(detached)', 'task-7'],
            ['', 'completion'],
        ];
    }

    public function test_runtime_preflight_rejects_unexpected_php_hash(): void
    {
        $builder = new \PlanOneAEvidence($this->repository());
        $this->setStaticOverride('branchOverride', static fn (string $root, string $mode): string => 'feat/reports-canonical-backend');
        $this->setStaticOverride('phpHashOverride', static fn (): string => str_repeat('0', 64));

        try {
            $this->expectException(\PlanOneAEvidenceFailure::class);
            $this->expectExceptionMessage('PLAN_1A_EVIDENCE_PHP_HASH_INVALID');
            $this->invoke($builder, 'validateRuntimePreflight', ['completion']);
        } finally {
            $this->setStaticOverride('phpHashOverride', null);
            $this->setStaticOverride('branchOverride', null);
        }
    }

    public function test_precommit_exact_unstaged_task_four_a2_pre_generation_set_is_accepted(): void
    {
        [$repository, $head] = $this->precommitRepository();
        $builder = new \PlanOneAEvidence($repository);

        $this->invoke($builder, 'validateModeGitState', ['contract-lock', $head]);

        self::assertSame([], $this->gitPaths($repository, ['diff', '--cached', '--name-only']));
    }

    public function test_precommit_staged_path_is_rejected(): void
    {
        [$repository, $head] = $this->precommitRepository();
        $this->git($repository, ['add', $this->taskFourA2PreGenerationPaths()[0]]);
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);

        $this->invoke($builder, 'validateModeGitState', ['contract-lock', $head]);
    }

    public function test_precommit_extra_untracked_path_is_rejected(): void
    {
        [$repository, $head] = $this->precommitRepository();
        file_put_contents($repository.'/composer.json', "\n", FILE_APPEND);
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);

        $this->invoke($builder, 'validateModeGitState', ['contract-lock', $head]);
    }

    public function test_clean_canonical_task_four_a2_commit_is_accepted(): void
    {
        [$repository, $commit] = $this->canonicalRepository($this->taskFourA2Subject());
        $builder = new \PlanOneAEvidence($repository);

        $this->invoke($builder, 'validateModeGitState', ['task-7', $commit]);

        self::assertSame([], $this->gitPaths($repository, ['status', '--porcelain']));
    }

    public function test_canonical_wrong_subject_is_rejected(): void
    {
        [$repository, $commit] = $this->canonicalRepository('wrong subject');
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);

        $this->invoke($builder, 'validateModeGitState', ['completion', $commit]);
    }

    public function test_canonical_worktree_byte_drift_is_rejected(): void
    {
        [$repository, $commit] = $this->canonicalRepository($this->taskFourA2Subject());
        file_put_contents($repository.'/scripts/reporting/build-plan-1a-evidence.php', 'drift');
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);

        $this->invoke($builder, 'validateCanonicalTaskFourA2Commit', [$commit]);
    }

    public function test_canonical_extra_path_commit_is_rejected(): void
    {
        [$repository] = $this->canonicalRepository($this->taskFourA2Subject());
        file_put_contents($repository.'/foreign.txt', 'foreign');
        $this->git($repository, ['add', '-f', 'foreign.txt']);
        $this->git($repository, ['commit', '-m', $this->taskFourA2Subject()]);
        $commit = $this->git($repository, ['rev-parse', 'HEAD']);
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->invoke($builder, 'validateCanonicalTaskFourA2Commit', [$commit]);
    }

    public function test_canonical_merge_parent_is_rejected(): void
    {
        [$repository, $taskFourA] = $this->canonicalRepository($this->taskFourA2Subject());
        $canonicalBranch = $this->git($repository, ['branch', '--show-current']);
        $parent = $this->git($repository, ['rev-parse', $taskFourA.'^']);
        $this->git($repository, ['checkout', '-b', 'side', $parent]);
        file_put_contents($repository.'/side.txt', 'side');
        $this->git($repository, ['add', '-f', 'side.txt']);
        $this->git($repository, ['commit', '-m', 'side']);
        $this->git($repository, ['checkout', $canonicalBranch]);
        $this->git($repository, ['merge', '--no-ff', 'side', '-m', $this->taskFourA2Subject()]);
        $merge = $this->git($repository, ['rev-parse', 'HEAD']);
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->invoke($builder, 'validateCanonicalTaskFourA2Commit', [$merge]);
    }

    public function test_task_seven_owner_with_exact_two_trailers_is_resolved(): void
    {
        [$repository, , $owner, $completion] = $this->historyRepository();
        $builder = new \PlanOneAEvidence($repository);

        $provenance = $builder->resolveTaskSevenOwner($completion);

        self::assertSame($owner, $provenance->ownerCommit);
        self::assertSame($completion, $provenance->completionCommit);
    }

    public function test_task_seven_owner_with_extra_trailer_is_rejected(): void
    {
        [$repository, , , $completion] = $this->historyRepository("Extra-Trailer: forbidden\n");
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_TASK_7_TRAILER_KEYS_INVALID');

        $builder->resolveTaskSevenOwner($completion);
    }

    public function test_task_seven_owner_with_duplicate_task_trailer_is_rejected(): void
    {
        [$repository, , , $completion] = $this->historyRepository("Reports-Plan1a-Task: 7\n");
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);

        $builder->resolveTaskSevenOwner($completion);
    }

    public function test_task_seven_owner_with_base_not_equal_to_first_parent_is_rejected(): void
    {
        [$repository, , , $completion] = $this->historyRepository('', false, str_repeat('a', 40));
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_TASK_7_PARENT_INVALID');

        $builder->resolveTaskSevenOwner($completion);
    }

    public function test_task_seven_descendant_owned_path_touch_is_rejected(): void
    {
        [$repository, , , $completion] = $this->historyRepository('', true);
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_TASK_7_DESCENDANT_TOUCH');

        $builder->resolveTaskSevenOwner($completion);
    }

    public function test_task_seven_descendant_restoration_does_not_hide_owned_path_touch(): void
    {
        [$repository, , $owner] = $this->historyRepository();
        $path = $this->taskSevenPaths()[0];
        $this->write($repository.'/'.$path, 'temporary descendant drift');
        $this->git($repository, ['add', '--', $path]);
        $this->git($repository, ['commit', '-m', 'temporary task seven touch']);
        $this->git($repository, ['checkout', $owner, '--', $path]);
        $this->git($repository, ['add', '--', $path]);
        $this->git($repository, ['commit', '-m', 'restored task seven bytes']);
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_TASK_7_DESCENDANT_TOUCH');

        $builder->resolveTaskSevenOwner($this->git($repository, ['rev-parse', 'HEAD']));
    }

    public function test_task_seven_owner_rejects_genuinely_shallow_history(): void
    {
        [$repository, , , $completion] = $this->historyRepository();
        $clone = $this->temporaryDirectory().'/shallow';
        $process = new Process(['git', 'clone', '--depth=1', 'file://'.str_replace('\\', '/', $repository), $clone]);
        $process->mustRun();
        $builder = new \PlanOneAEvidence($clone);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_TASK_7_SHALLOW_HISTORY');

        $builder->resolveTaskSevenOwner($completion);
    }

    public function test_task_seven_owner_resolves_in_clean_clone_without_custom_refs_or_artifacts(): void
    {
        [$repository, , $owner, $completion] = $this->historyRepository();
        $clone = $this->temporaryDirectory().'/clean';
        (new Process(['git', 'clone', 'file://'.str_replace('\\', '/', $repository), $clone]))->mustRun();
        $builder = new \PlanOneAEvidence($clone);

        $provenance = $builder->resolveTaskSevenOwner($completion);

        self::assertSame($owner, $provenance->ownerCommit);
        self::assertSame($completion, $provenance->completionCommit);
        self::assertFileDoesNotExist($clone.'/build/reports/task-7-composer-evidence.json');
    }

    public function test_task_seven_check_rereads_without_changing_fixed_artifact(): void
    {
        $builder = new \PlanOneAEvidence($this->root());
        $provenance = $builder->resolveTaskSevenOwner((string) trim($this->git($this->root(), ['rev-parse', 'HEAD'])));
        $path = $this->root().'/build/reports/task-7-composer-evidence.json';
        $before = [hash_file('sha256', $path), filemtime($path)];

        [$evidence, $bytes] = $this->invoke($builder, 'regenerateTaskSeven', [$provenance, false]);

        clearstatcache(true, $path);
        self::assertSame('task_7_composer_contract_passed', $evidence['status']);
        self::assertSame($before, [hash_file('sha256', $path), filemtime($path)]);
        self::assertSame($evidence, json_decode($bytes, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_task_seven_lock_binding_accepts_exact_evidence_and_bytes(): void
    {
        $builder = new \PlanOneAEvidence($this->repository());
        $taskSeven = $this->taskSevenEvidence();
        $bytes = 'canonical-task-seven-evidence';
        $lock = $this->taskSevenLock($taskSeven, $bytes);

        $this->invoke($builder, 'validateTaskSevenLockBinding', [$lock, $taskSeven, $bytes]);

        self::assertSame($taskSeven, array_slice($lock['composer_contract']['evidence'], 2));
    }

    #[DataProvider('taskSevenLockFieldMutations')]
    public function test_task_seven_lock_binding_rejects_missing_tampered_extra_and_reordered_fields(string $kind, ?string $field): void
    {
        $builder = new \PlanOneAEvidence($this->repository());
        $taskSeven = $this->taskSevenEvidence();
        $bytes = 'canonical-task-seven-evidence';
        $lock = $this->taskSevenLock($taskSeven, $bytes);

        switch ($kind) {
            case 'missing':
                unset($taskSeven[$field]);
                break;
            case 'tampered':
                $taskSeven[$field] = $this->tamperedTaskSevenValue($taskSeven[$field]);
                break;
            case 'extra':
                $taskSeven['forged_field'] = 'forged';
                break;
            case 'reordered':
                $taskSeven = array_reverse($taskSeven, true);
                break;
        }

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_TASK_7_LOCK_FIELDS_DRIFT');

        $this->invoke($builder, 'validateTaskSevenLockBinding', [$lock, $taskSeven, $bytes]);
    }

    public static function taskSevenLockFieldMutations(): array
    {
        $fields = [
            'status',
            'baseline_commit_sha',
            'reviewed_commit_sha',
            'composer_json_before_sha256',
            'composer_lock_before_sha256',
            'composer_json_after_sha256',
            'composer_lock_after_sha256',
            'root_constraint',
            'locked_opis_version',
            'added_packages',
            'content_hash',
        ];
        $cases = [];
        foreach ($fields as $field) {
            $cases['missing '.$field] = ['missing', $field];
            $cases['tampered '.$field] = ['tampered', $field];
        }
        $cases['extra field'] = ['extra', null];
        $cases['reordered fields'] = ['reordered', null];

        return $cases;
    }

    public function test_task_seven_lock_binding_rejects_artifact_bytes_divergence(): void
    {
        $builder = new \PlanOneAEvidence($this->repository());
        $taskSeven = $this->taskSevenEvidence();
        $lock = $this->taskSevenLock($taskSeven, 'canonical-task-seven-evidence');

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_TASK_7_LOCK_BINDING_DRIFT');

        $this->invoke($builder, 'validateTaskSevenLockBinding', [$lock, $taskSeven, 'tampered-task-seven-evidence']);
    }

    #[DataProvider('invalidLockArtifacts')]
    public function test_read_validated_lock_rejects_missing_or_tampered_raw_lock_and_sidecar(string $mutation): void
    {
        $repository = $this->repository();
        $directory = $repository.'/docs/reports/contracts';
        mkdir($directory, 0777, true);
        $lockPath = $directory.'/plan-1a-contract-lock.json';
        $sidecarPath = $directory.'/plan-1a-contract-lock.sha256';
        file_put_contents($lockPath, "{\"lock\":true}\n");
        file_put_contents($sidecarPath, hash_file('sha256', $lockPath)."\n");
        match ($mutation) {
            'missing raw' => unlink($lockPath),
            'missing sidecar' => unlink($sidecarPath),
            'tampered raw' => file_put_contents($lockPath, "{\"lock\":false}\n"),
            'tampered sidecar' => file_put_contents($sidecarPath, str_repeat('0', 64)."\n"),
        };
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_LOCK_HASH_DRIFT');

        $this->invoke($builder, 'readValidatedLock', []);
    }

    public static function invalidLockArtifacts(): array
    {
        return [
            ['missing raw'],
            ['missing sidecar'],
            ['tampered raw'],
            ['tampered sidecar'],
        ];
    }

    public function test_cleanup_removes_only_bounded_outputs_and_temps(): void
    {
        $repository = $this->repository();
        $builder = new \PlanOneAEvidence($repository);
        mkdir($repository.'/build/reports', 0777, true);
        $bounded = $repository.'/build/reports/task-7-composer-evidence.json';
        $temporary = $repository.'/build/reports/.plan-1a-write-deadbeef.tmp';
        $foreign = $repository.'/build/reports/foreign.json';
        file_put_contents($bounded, 'bounded');
        file_put_contents($temporary, 'temporary');
        file_put_contents($foreign, 'foreign');

        $this->invoke($builder, 'cleanup', [[$bounded]]);

        self::assertFileDoesNotExist($bounded);
        self::assertFileDoesNotExist($temporary);
        self::assertFileExists($foreign);
    }

    public function test_completion_cleanup_removes_all_six_outputs_and_bounded_temps(): void
    {
        $repository = $this->repository();
        $builder = new \PlanOneAEvidence($repository);
        $outputs = $this->invoke($builder, 'modeOutputs', ['completion']);
        foreach ($outputs as $path) {
            $this->write($path, 'stale');
        }
        $this->write($repository.'/build/reports/.plan-1a-write-a.tmp', 'temp');
        $this->write($repository.'/docs/reports/contracts/.plan-1a-write-b.tmp', 'temp');

        $this->invoke($builder, 'cleanup', [$outputs]);

        foreach ($outputs as $path) {
            self::assertFileDoesNotExist($path);
        }
        self::assertSame([], glob($repository.'/build/reports/.plan-1a-*.tmp') ?: []);
        self::assertSame([], glob($repository.'/docs/reports/contracts/.plan-1a-*.tmp') ?: []);
    }

    #[DataProvider('completionOrchestratorFailures')]
    public function test_completion_orchestrator_cleans_every_output_and_temp_after_each_failure_stage(
        string $failureStage,
        int $expectedExit,
        string $expectedError,
    ): void {
        $repository = match ($failureStage) {
            'tracked schema bytes', 'lock provenance/binding', 'runner bootstrap' => $this->canonicalOrchestratorRepository(),
            'missing Task7 history' => $this->canonicalOrchestratorRepository(),
            default => $this->preflightFailureRepository(),
        };
        file_put_contents(
            $repository.'/.git/info/exclude',
            "/build/reports/.plan-1a-*.tmp\n/docs/reports/contracts/.plan-1a-*.tmp\n",
            FILE_APPEND,
        );

        $this->setStaticOverride('branchOverride', static fn (): string => 'feat/reports-canonical-backend');
        $this->setStaticOverride('phpHashOverride', static fn (): string => 'f515db26936a2702886ca19523518556972fdf25dee699b78e1c78863a08b680');
        if ($failureStage === 'missing Task7 history') {
            $this->setStaticOverride(
                'taskSevenProvenanceOverride',
                static function (): never {
                    throw new \PlanOneAEvidenceFailure(4, 'PLAN_1A_TASK_7_OWNER_COUNT_INVALID');
                },
            );
        }
        if (in_array($failureStage, ['tracked schema bytes', 'lock provenance/binding', 'runner bootstrap'], true)) {
            $taskSevenBytes = (string) file_get_contents($this->root().'/build/reports/task-7-composer-evidence.json');
            $taskSeven = json_decode($taskSevenBytes, true, 512, JSON_THROW_ON_ERROR);
            $this->setStaticOverride(
                'taskSevenRegenerationOverride',
                static function (\TaskSevenProvenance $provenance, bool $write, string $root) use ($taskSeven, $taskSevenBytes): array {
                    if ($write) {
                        file_put_contents($root.'/build/reports/task-7-composer-evidence.json', $taskSevenBytes);
                    }

                    return [$taskSeven, $taskSevenBytes];
                },
            );
            $this->setStaticOverride(
                'taskSevenProvenanceOverride',
                static fn (string $commit): \TaskSevenProvenance => new \TaskSevenProvenance(
                    str_repeat('a', 40),
                    str_repeat('b', 40),
                    $commit,
                ),
            );
        }

        if ($failureStage === 'branch') {
            $this->setStaticOverride('branchOverride', static fn (): string => 'main');
        } elseif ($failureStage === 'PHP hash') {
            $this->setStaticOverride('phpHashOverride', static fn (): string => str_repeat('0', 64));
        } elseif ($failureStage === 'dirty tree') {
            file_put_contents($repository.'/.gitignore', "\n# dirty\n", FILE_APPEND);
        } elseif ($failureStage === 'tracked schema bytes') {
            $this->write($repository.'/docs/reports/contracts/plan-1a-gate-evidence.schema.json', "{\"type\":\"null\"}\n");
            $this->write($repository.'/scripts/reporting/run-plan-1a-gates.php', "<?php\n\nexit(0);\n");
            $this->git($repository, ['add', '--',
                'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
                'scripts/reporting/run-plan-1a-gates.php',
            ]);
            $this->git($repository, ['commit', '--amend', '--no-edit']);
        } elseif ($failureStage === 'lock provenance/binding') {
            $lockPath = $repository.'/docs/reports/contracts/plan-1a-contract-lock.json';
            $lock = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);
            $lock['composer_contract']['evidence']['artifact_sha256'] = str_repeat('0', 64);
            file_put_contents($lockPath, json_encode($lock, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            file_put_contents(
                $repository.'/docs/reports/contracts/plan-1a-contract-lock.sha256',
                hash_file('sha256', $lockPath)."\n",
            );
            $this->git($repository, ['add', '--', 'docs/reports/contracts/plan-1a-contract-lock.json', 'docs/reports/contracts/plan-1a-contract-lock.sha256']);
            $this->git($repository, ['commit', '--amend', '--no-edit']);
        } elseif ($failureStage === 'runner bootstrap') {
            $this->write($repository.'/scripts/reporting/run-plan-1a-gates.php', "<?php\n\nexit(17);\n");
            $this->git($repository, ['add', '--', 'scripts/reporting/run-plan-1a-gates.php']);
            $this->git($repository, ['commit', '--amend', '--no-edit']);
        }

        $outputs = [
            'task-7-composer-evidence.json',
            'plan-1a-route-snapshot.json',
            'plan-1a-ci-authorization.json',
            'plan-1a-ci-malformed.json',
            'plan-1a-command-ledger.json',
            'plan-1a-completion.json',
        ];
        foreach ($outputs as $output) {
            $fixture = $failureStage === 'tracked schema bytes'
                ? match ($output) {
                    'plan-1a-route-snapshot.json' => 'plan-1a-route-snapshot.valid.json',
                    'plan-1a-ci-authorization.json' => 'plan-1a-ci-authorization.valid.json',
                    'plan-1a-ci-malformed.json' => 'plan-1a-ci-malformed.valid.json',
                    'plan-1a-command-ledger.json' => 'plan-1a-command-ledger.valid.json',
                    default => null,
                }
            : null;
            $bytes = is_string($fixture)
                ? (string) file_get_contents($this->root().'/tests/Fixtures/Reporting/Evidence/'.$fixture)
                : 'stale-'.$failureStage;
            $this->write($repository.'/build/reports/'.$output, $bytes);
        }
        $temporaries = [
            $repository.'/build/reports/.plan-1a-write-orchestrator.tmp',
            $repository.'/build/reports/.plan-1a-trailers-orchestrator.tmp',
            $repository.'/docs/reports/contracts/.plan-1a-write-orchestrator.tmp',
        ];
        foreach ($temporaries as $temporary) {
            $this->write($temporary, 'stale-temp-'.$failureStage);
        }

        $errors = [];
        $this->setStaticOverride('errorOverride', static function (string $error) use (&$errors): void {
            $errors[] = $error;
        });
        $commit = $this->git($repository, ['rev-parse', 'HEAD']);
        $exit = \PlanOneAEvidence::execute([
            'build-plan-1a-evidence.php',
            'completion',
            '--repository-root='.$repository,
            '--commit-sha='.$commit,
            '--output=build/reports/plan-1a-completion.json',
        ]);

        self::assertSame([$expectedError], $errors);
        self::assertSame($expectedExit, $exit);
        foreach ($outputs as $output) {
            self::assertFileDoesNotExist($repository.'/build/reports/'.$output);
        }
        foreach ($temporaries as $temporary) {
            self::assertFileDoesNotExist($temporary);
        }
    }

    public static function completionOrchestratorFailures(): array
    {
        return [
            'branch' => ['branch', 3, 'PLAN_1A_EVIDENCE_BRANCH_INVALID'],
            'PHP hash' => ['PHP hash', 2, 'PLAN_1A_EVIDENCE_PHP_HASH_INVALID'],
            'dirty tree' => ['dirty tree', 3, 'PLAN_1A_EVIDENCE_WORKTREE_DIRTY'],
            'tracked schema bytes' => ['tracked schema bytes', 3, 'PLAN_1A_EVIDENCE_SCHEMA_INVALID'],
            'missing Task7 history' => ['missing Task7 history', 4, 'PLAN_1A_TASK_7_OWNER_COUNT_INVALID'],
            'lock provenance/binding' => ['lock provenance/binding', 4, 'PLAN_1A_TASK_7_LOCK_BINDING_DRIFT'],
            'runner bootstrap' => ['runner bootstrap', 5, 'PLAN_1A_GATE_REPLAY_FAILED'],
        ];
    }

    public function test_completion_governance_rejects_tracked_generated_output(): void
    {
        $repository = $this->repository();
        $this->write($repository.'/build/reports/plan-1a-completion.json', 'tracked');
        $this->git($repository, ['add', '--', 'build/reports/plan-1a-completion.json']);
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_EVIDENCE_OUTPUT_TRACKED');

        $this->invoke($builder, 'validateGeneratedOutputGovernance', ['completion']);
    }

    public function test_completion_governance_rejects_not_ignored_generated_outputs(): void
    {
        $repository = $this->repository();
        $builder = new \PlanOneAEvidence($repository);

        $this->expectException(\PlanOneAEvidenceFailure::class);
        $this->expectExceptionMessage('PLAN_1A_EVIDENCE_OUTPUT_NOT_IGNORED');

        $this->invoke($builder, 'validateGeneratedOutputGovernance', ['completion']);
    }

    public function test_builder_rejects_build_junction_escape_without_writing_target(): void
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
            $result = $this->runCli(
                $repository,
                'task-7',
                'build/reports/task-7-composer-evidence.json',
                '--check',
            );

            self::assertSame(2, $result->getExitCode());
            self::assertStringContainsString('PLAN_1A_EVIDENCE_OUTPUT_PATH_INVALID', $result->getErrorOutput());
            self::assertSame($before, $this->snapshot($outside));
        } finally {
            if (is_dir($junction)) {
                rmdir($junction);
            }
        }
    }

    private function completionValidates(array $value): bool
    {
        $schema = json_decode((string) file_get_contents($this->root().'/docs/reports/contracts/plan-1a-completion.schema.json'));

        return (new CompliantValidator)->validate(json_decode(json_encode($value, JSON_THROW_ON_ERROR)), $schema)->isValid();
    }

    private function taskSevenEvidence(): array
    {
        return [
            'status' => 'task_7_composer_contract_passed',
            'baseline_commit_sha' => str_repeat('a', 40),
            'reviewed_commit_sha' => str_repeat('b', 40),
            'composer_json_before_sha256' => str_repeat('c', 64),
            'composer_lock_before_sha256' => str_repeat('d', 64),
            'composer_json_after_sha256' => str_repeat('e', 64),
            'composer_lock_after_sha256' => str_repeat('f', 64),
            'root_constraint' => '^2.6',
            'locked_opis_version' => '2.6.0',
            'added_packages' => ['opis/json-schema', 'opis/string', 'opis/uri'],
            'content_hash' => str_repeat('1', 32),
        ];
    }

    private function taskSevenLock(array $taskSeven, string $bytes): array
    {
        return [
            'composer_contract' => [
                'evidence' => [
                    'artifact_path' => 'build/reports/task-7-composer-evidence.json',
                    'artifact_sha256' => hash('sha256', $bytes),
                    ...$taskSeven,
                ],
            ],
        ];
    }

    private function tamperedTaskSevenValue(mixed $value): mixed
    {
        return is_array($value) ? ['forged'] : 'forged';
    }

    private function completion(): array
    {
        return json_decode((string) file_get_contents($this->fixturePath()), true, 512, JSON_THROW_ON_ERROR);
    }

    private function digestCount(mixed $value): int
    {
        if (! is_array($value)) {
            return 0;
        }
        $count = 0;
        foreach ($value as $key => $item) {
            $count += is_string($key) && str_ends_with($key, 'sha256') ? 1 : 0;
            $count += $this->digestCount($item);
        }

        return $count;
    }

    private function parse(string $mode, string $output, bool $check): array
    {
        $arguments = [
            $mode,
            '--repository-root='.$this->root(),
            '--commit-sha='.str_repeat('a', 40),
            '--output='.$output,
        ];
        if ($check) {
            $arguments[] = '--check';
        }

        return (new ReflectionClass(\PlanOneAEvidence::class))->getMethod('parse')->invoke(null, $arguments);
    }

    private function invoke(object $object, string $method, array $arguments): mixed
    {
        return (new ReflectionClass($object))->getMethod($method)->invoke($object, ...$arguments);
    }

    private function setBuilderFault(?\Closure $fault): void
    {
        $property = (new ReflectionClass(\PlanOneAEvidence::class))->getProperty('faultOverride');
        $property->setValue(null, $fault);
    }

    private function setStaticOverride(string $propertyName, ?\Closure $override): void
    {
        $property = (new ReflectionClass(\PlanOneAEvidence::class))->getProperty($propertyName);
        $property->setValue(null, $override);
    }

    private function runCli(string $repository, string $mode, string $output, string ...$extra): Process
    {
        $process = new Process([
            self::PHP,
            '-c',
            self::PHP_DIR,
            $this->root().'/scripts/reporting/build-plan-1a-evidence.php',
            $mode,
            '--repository-root='.$repository,
            '--commit-sha='.$this->git($repository, ['rev-parse', 'HEAD']),
            '--output='.$output,
            ...$extra,
        ], $this->root());
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    private function repository(): string
    {
        $repository = $this->temporaryDirectory();
        $this->git($repository, ['init']);
        $this->git($repository, ['config', 'user.email', 'reports@example.test']);
        $this->git($repository, ['config', 'user.name', 'Reports Test']);
        file_put_contents($repository.'/seed.txt', 'seed');
        $this->git($repository, ['add', 'seed.txt']);
        $this->git($repository, ['commit', '-m', 'base']);
        $this->git($repository, ['branch', '-m', 'feat/reports-canonical-backend']);

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
        foreach ($this->taskFourA2PreGenerationPaths() as $path) {
            $this->write($repository.'/'.$path, (string) file_get_contents($this->root().'/'.$path));
        }

        return [$repository, $this->git($repository, ['rev-parse', 'HEAD'])];
    }

    private function canonicalRepository(string $subject = 'test[reports]: добавлен проверяемый handoff Plan 1a'): array
    {
        [$repository] = $this->precommitRepository();
        foreach (array_diff($this->taskFourA2Paths(), $this->taskFourA2PreGenerationPaths()) as $path) {
            $this->write($repository.'/'.$path, (string) file_get_contents($this->root().'/'.$path));
        }
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
        $wrongParent = $this->git($repository, ['commit-tree', $taskFourATree, '-p', $taskFourAParent.'^', '-m', $this->taskFourASubject()]);
        $wrongSubject = $this->git($repository, ['commit-tree', $taskFourBTree, '-p', $taskFourA, '-m', 'wrong subject']);

        $this->git($repository, ['checkout', '--detach', $taskFourA]);
        file_put_contents($repository.'/app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotRef.php', "\n", FILE_APPEND);
        $this->git($repository, ['add', 'app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotRef.php']);
        $alteredTaskFourATree = $this->git($repository, ['write-tree']);
        $alteredTaskFourABlob = $this->git($repository, ['commit-tree', $alteredTaskFourATree, '-p', $taskFourAParent, '-m', $this->taskFourASubject()]);

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

    private function canonicalOrchestratorRepository(): string
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
        $this->git($repository, ['add', '--', ...$this->taskFourA2Paths()]);
        $this->git($repository, ['commit', '-m', $this->taskFourA2Subject()]);

        return $repository;
    }

    private function preflightFailureRepository(): string
    {
        $repository = $this->repository();
        file_put_contents($repository.'/.gitignore', $this->reportIgnoreRules());
        $this->git($repository, ['add', '.gitignore']);
        $this->git($repository, ['commit', '-m', 'ignore generated evidence']);

        return $repository;
    }

    private function historyRepository(string $extraTrailer = '', bool $touchOwned = false, ?string $baseTrailer = null): array
    {
        $repository = $this->repository();
        $base = $this->git($repository, ['rev-parse', 'HEAD']);
        foreach ($this->taskSevenPaths() as $path) {
            $this->write($repository.'/'.$path, 'task7:'.$path);
        }
        $this->git($repository, ['add', '--', ...$this->taskSevenPaths()]);
        $message = "feat[reports]: зафиксированы схемы ресурсов отчётности\n\n"
            ."Reports-Plan1a-Task: 7\n"
            .'Reports-Plan1a-Base-Commit: '.($baseTrailer ?? $base)."\n"
            .$extraTrailer;
        $this->git($repository, ['commit', '-m', $message]);
        $owner = $this->git($repository, ['rev-parse', 'HEAD']);
        foreach ($this->taskFourAPaths() as $path) {
            $this->write($repository.'/'.$path, 'task4a:'.$path);
        }
        if ($touchOwned) {
            $this->write($repository.'/'.$this->taskSevenPaths()[0], 'descendant drift');
        }
        $this->git($repository, ['add', '--', ...$this->taskFourAPaths(), ...($touchOwned ? [$this->taskSevenPaths()[0]] : [])]);
        $this->git($repository, ['commit', '-m', $this->taskFourASubject()]);
        $completion = $this->git($repository, ['rev-parse', 'HEAD']);

        return [$repository, $base, $owner, $completion];
    }

    private function taskSevenPaths(): array
    {
        $constant = (new ReflectionClass(\PlanOneAEvidence::class))->getReflectionConstant('TASK_SEVEN_PATHS');
        self::assertNotFalse($constant);

        return $constant->getValue();
    }

    private function taskFourAPaths(): array
    {
        $constant = (new ReflectionClass(\PlanOneAEvidence::class))->getReflectionConstant('TASK_FOUR_A_PATHS');
        self::assertNotFalse($constant);

        return $constant->getValue();
    }

    private function taskFourA2Paths(): array
    {
        $constant = (new ReflectionClass(\PlanOneAEvidence::class))->getReflectionConstant('TASK_FOUR_A2_PATHS');
        self::assertNotFalse($constant);

        return $constant->getValue();
    }

    private function taskFourA2PreGenerationPaths(): array
    {
        return array_values(array_filter(
            $this->taskFourA2Paths(),
            static fn (string $path): bool => ! in_array($path, [
                'docs/reports/contracts/plan-1a-contract-lock.json',
                'docs/reports/contracts/plan-1a-contract-lock.sha256',
            ], true),
        ));
    }

    private function taskFourASubject(): string
    {
        $constant = (new ReflectionClass(\PlanOneAEvidence::class))->getReflectionConstant('TASK_FOUR_A_SUBJECT');
        self::assertNotFalse($constant);

        return $constant->getValue();
    }

    private function taskFourA2Subject(): string
    {
        $constant = (new ReflectionClass(\PlanOneAEvidence::class))->getReflectionConstant('TASK_FOUR_A2_SUBJECT');
        self::assertNotFalse($constant);

        return $constant->getValue();
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
        $directory = sys_get_temp_dir().'/most-plan1a-builder-'.bin2hex(random_bytes(8));
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

    private function fixturePath(): string
    {
        return $this->root().'/tests/Fixtures/Reporting/Evidence/plan-1a-completion.valid.json';
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
