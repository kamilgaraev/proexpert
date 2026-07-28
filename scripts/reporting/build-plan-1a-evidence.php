<?php

declare(strict_types=1);

use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class PlanOneAEvidenceFailure extends RuntimeException
{
    public function __construct(
        public readonly int $exitStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}

final readonly class TaskSevenProvenance
{
    public function __construct(
        public string $ownerCommit,
        public string $baseCommit,
        public string $completionCommit,
    ) {}
}

final class PlanOneAEvidence
{
    private const PHP = 'C:/Users/kamilgaraev/AppData/Local/CodexToolchains/most-reports/php-8.2.29-nts-vs16-x64/php.exe';
    private const PHP_DIR = 'C:/Users/kamilgaraev/AppData/Local/CodexToolchains/most-reports/php-8.2.29-nts-vs16-x64';
    private const PHP_SHA256 = 'f515db26936a2702886ca19523518556972fdf25dee699b78e1c78863a08b680';
    private const CANONICAL_BRANCH = 'feat/reports-canonical-backend';
    private const TASK_SEVEN_SUBJECT = 'feat[reports]: зафиксированы схемы ресурсов отчётности';
    private const TASK_SEVEN_PATHS = [
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportCatalogView.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportSavedView.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportCatalogResource.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportDownloadLinkResource.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportDrillDownResource.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportExportResource.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportRowsResource.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportRunResource.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportSavedViewResource.php',
        'composer.json',
        'composer.lock',
        'docs/reports/contracts/reporting-admin-resources.v1.schema.json',
        'scripts/reporting/verify-task-7-composer.php',
        'tests/Fixtures/Reporting/Composer/Task7/baseline-composer.json',
        'tests/Fixtures/Reporting/Composer/Task7/baseline-composer.lock',
        'tests/Fixtures/Reporting/Composer/Task7/expected-evidence.json',
        'tests/Fixtures/Reporting/Composer/Task7/reviewed-composer.json',
        'tests/Fixtures/Reporting/Composer/Task7/reviewed-composer.lock',
        'tests/Fixtures/Reporting/Wire/reporting-admin-resources.v1.json',
        'tests/Unit/Reporting/Http/ReportResourceSchemaTest.php',
        'tests/Unit/Reporting/Tooling/VerifyTaskSevenComposerTest.php',
    ];
    private const TASK_FOUR_A_SUBJECT = 'fix[reports]: зафиксировать классификацию и печать снимков';
    private const TASK_FOUR_A_PATHS = [
        'app/BusinessModules/Core/Reporting/Application/Access/ReportAccessService.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportRunStore.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/RetryReportExportAction.php',
        'app/BusinessModules/Core/Reporting/Application/Contracts/RetryReportRunAction.php',
        'app/BusinessModules/Core/Reporting/Domain/Contracts/ReportSavedViewReferenceResolver.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportDefinition.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportOutputClassification.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportSavedViewRef.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotRef.php',
        'app/BusinessModules/Core/Reporting/Domain/DTO/ReportSnapshotSeal.php',
        'app/BusinessModules/Core/Reporting/Domain/Enums/ReportDataClassification.php',
        'app/BusinessModules/Core/Reporting/Domain/Enums/ReportSnapshotClassification.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportExportController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Controllers/ReportRunController.php',
        'app/BusinessModules/Core/Reporting/Http/Admin/Resources/ReportCatalogResource.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/Models/ReportRunRecord.php',
        'app/BusinessModules/Core/Reporting/Infrastructure/Persistence/ReportRunHydrator.php',
        'database/migrations/2026_07_26_000001_create_report_runs_table.php',
        'docs/reports/contracts/plan-1a-completion.schema.json',
        'docs/reports/contracts/plan-1a-contract-lock.json',
        'docs/reports/contracts/plan-1a-contract-lock.sha256',
        'docs/reports/contracts/plan-1a-gate-evidence.schema.json',
        'docs/reports/contracts/reporting-admin-resources.v1.schema.json',
        'scripts/reporting/build-plan-1a-evidence.php',
        'scripts/reporting/run-plan-1a-gates.php',
        'tests/Architecture/Reporting/PlanOneAHandoffContractTest.php',
        'tests/Architecture/Reporting/PlanOneBPlanOneAHandoffTest.php',
        'tests/Architecture/Reporting/ThinReportControllerTest.php',
        'tests/Feature/Api/V1/Admin/Reporting/ReportingMalformedRequestContractTest.php',
        'tests/Feature/Reporting/Persistence/EloquentReportRunStoreTest.php',
        'tests/Fixtures/Reporting/Evidence/plan-1a-ci-authorization.valid.json',
        'tests/Fixtures/Reporting/Evidence/plan-1a-ci-malformed.valid.json',
        'tests/Fixtures/Reporting/Evidence/plan-1a-command-ledger.valid.json',
        'tests/Fixtures/Reporting/Evidence/plan-1a-completion.valid.json',
        'tests/Fixtures/Reporting/Wire/reporting-admin-resources.v1.json',
        'tests/Support/Reporting/FakeReportingActions.php',
        'tests/Support/Reporting/HermeticReportingHttpHarness.php',
        'tests/Support/Reporting/ReportDefinitionBuilder.php',
        'tests/Support/Reporting/ReportRunBuilder.php',
        'tests/Unit/Reporting/Access/ReportAccessServiceTest.php',
        'tests/Unit/Reporting/Contracts/ReportBindingLifecycleContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportDefinitionContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportExecutionContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportProviderPortContractTest.php',
        'tests/Unit/Reporting/Contracts/ReportWireDtoContractTest.php',
        'tests/Unit/Reporting/Execution/ExecutionContractsTest.php',
        'tests/Unit/Reporting/Http/ReportControllerContractTest.php',
        'tests/Unit/Reporting/Http/ReportResourceSchemaTest.php',
        'tests/Unit/Reporting/Input/ReportInputNormalizerTest.php',
        'tests/Unit/Reporting/Persistence/ReportRunHydratorTest.php',
        'tests/Unit/Reporting/Tooling/BuildPlanOneAEvidenceTest.php',
        'tests/Unit/Reporting/Tooling/RunPlanOneAGatesTest.php',
    ];
    private static ?Closure $faultOverride = null;
    private static ?Closure $phpHashOverride = null;
    private static ?Closure $phpVersionOverride = null;
    private static ?Closure $branchOverride = null;
    private static ?Closure $errorOverride = null;
    private static ?Closure $taskSevenRegenerationOverride = null;
    private static ?Closure $taskSevenProvenanceOverride = null;

    private string $root;

    public static function execute(array $argv): int
    {
        try {
            $options = self::parse(array_slice($argv, 1));
            $instance = new self($options['repository-root']);
            $commit = $options['commit-sha'];
            $mutating = !$options['check'];
            if ($mutating) {
                $instance->ensureDirectory('build/reports');
            } else {
                $instance->requireDirectory('build/reports');
            }
            $outputs = $instance->modeOutputs($options['mode']);
            try {
                $instance->fault('before_output_governance');
                $instance->validateGeneratedOutputGovernance($options['mode']);
                $instance->fault('after_output_governance');
                $instance->validateRuntimePreflight($options['mode']);
                $instance->fault('after_runtime_preflight');
                $instance->validateModeGitState($options['mode'], $commit);
                $instance->fault('after_git_state');
                if ($mutating) {
                    $instance->cleanup($instance->precleanOutputs($options['mode']));
                }
                $instance->fault('after_preclean');
                if ($options['mode'] === 'contract-lock') {
                    $instance->contractLock($commit, $options['output'], $options['check']);
                } elseif ($options['mode'] === 'task-7') {
                    $instance->taskSeven($commit, $options['output'], $options['check']);
                } else {
                    $instance->completion($commit, $options['output'], $options['check']);
                }
                $instance->fault('after_mode');
            } catch (Throwable $failure) {
                if ($mutating) {
                    $instance->cleanup($outputs);
                }
                throw $failure;
            }

            return 0;
        } catch (PlanOneAEvidenceFailure $failure) {
            self::emitError($failure->getMessage());

            return $failure->exitStatus;
        } catch (Throwable) {
            self::emitError('PLAN_1A_EVIDENCE_INTERNAL_FAILURE');

            return 3;
        }
    }

    private static function emitError(string $message): void
    {
        if (self::$errorOverride instanceof Closure) {
            (self::$errorOverride)($message);

            return;
        }
        fwrite(STDERR, $message.PHP_EOL);
    }

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        self::guard(is_string($resolved), 2, 'PLAN_1A_EVIDENCE_ROOT_INVALID');
        $gitRoot = trim($this->process(['git', 'rev-parse', '--show-toplevel'], $resolved));
        self::guard(strcasecmp(str_replace('\\', '/', $gitRoot), str_replace('\\', '/', $resolved)) === 0, 2, 'PLAN_1A_EVIDENCE_ROOT_INVALID');
        $this->root = $resolved;
    }

    public function resolveTaskSevenOwner(string $completionCommit): TaskSevenProvenance
    {
        if (self::$taskSevenProvenanceOverride instanceof Closure) {
            $overridden = (self::$taskSevenProvenanceOverride)($completionCommit, $this->root);
            self::guard($overridden instanceof TaskSevenProvenance, 4, 'PLAN_1A_TASK_7_PROVENANCE_OVERRIDE_INVALID');

            return $overridden;
        }
        self::guard(preg_match('/^[a-f0-9]{40}$/D', $completionCommit) === 1, 3, 'PLAN_1A_TASK_7_COMMIT_INVALID');
        self::guard(trim($this->process(['git', 'rev-parse', '--is-shallow-repository'])) === 'false', 3, 'PLAN_1A_TASK_7_SHALLOW_HISTORY');
        $owners = [];
        $candidates = array_values(array_filter(preg_split('/\R/', trim($this->process([
            'git',
            'log',
            '--first-parent',
            '--format=%H',
            '--grep=Reports-Plan1a-Task:',
            $completionCommit,
        ]))) ?: [], static fn (string $commit): bool => $commit !== ''));
        foreach ($candidates as $commit) {
            $body = $this->process(['git', 'show', '-s', '--format=%B', $commit]);
            $trailers = $this->trailers($body);
            self::guard(
                array_keys($trailers) === ['Reports-Plan1a-Task', 'Reports-Plan1a-Base-Commit'],
                4,
                'PLAN_1A_TASK_7_TRAILER_KEYS_INVALID',
            );
            $taskValues = $trailers['Reports-Plan1a-Task'] ?? [];
            if ($taskValues !== []) {
                self::guard($taskValues === ['7'], 4, 'PLAN_1A_TASK_7_TRAILER_INVALID');
                $owners[] = [$commit, $trailers];
            }
        }
        self::guard(count($owners) === 1, 4, 'PLAN_1A_TASK_7_OWNER_COUNT_INVALID');
        [$owner, $trailers] = $owners[0];
        self::guard(trim($this->process(['git', 'show', '-s', '--format=%s', $owner])) === self::TASK_SEVEN_SUBJECT, 4, 'PLAN_1A_TASK_7_SUBJECT_INVALID');
        $bases = $trailers['Reports-Plan1a-Base-Commit'] ?? [];
        self::guard(count($bases) === 1 && preg_match('/^[a-f0-9]{40}$/D', $bases[0]) === 1, 4, 'PLAN_1A_TASK_7_BASE_TRAILER_INVALID');
        $base = $bases[0];
        $parents = preg_split('/\s+/', trim($this->process(['git', 'show', '-s', '--format=%P', $owner]))) ?: [];
        self::guard($parents === [$base], 4, 'PLAN_1A_TASK_7_PARENT_INVALID');
        self::guard(
            $this->processExit(['git', 'merge-base', '--is-ancestor', $owner, $completionCommit]) === 0,
            4,
            'PLAN_1A_TASK_7_FIRST_PARENT_INVALID',
        );
        $chain = preg_split('/\R/', trim($this->process([
            'git',
            'rev-list',
            '--first-parent',
            $owner.'..'.$completionCommit,
        ]))) ?: [];
        $owned = $this->changedPaths($owner);
        self::guard($owned === self::TASK_SEVEN_PATHS, 4, 'PLAN_1A_TASK_7_PATH_SET_INVALID');
        foreach ($chain as $descendant) {
            $descendantParents = preg_split('/\s+/', trim($this->process(['git', 'show', '-s', '--format=%P', $descendant]))) ?: [];
            self::guard(count($descendantParents) === 1, 4, 'PLAN_1A_TASK_7_DESCENDANT_MERGE');
            $changedPaths = $this->changedPaths($descendant);
            if (array_intersect($changedPaths, self::TASK_SEVEN_PATHS) !== []) {
                self::guard(
                    trim($this->process(['git', 'show', '-s', '--format=%s', $descendant])) === self::TASK_FOUR_A_SUBJECT
                        && $changedPaths === self::TASK_FOUR_A_PATHS,
                    4,
                    'PLAN_1A_TASK_7_DESCENDANT_TOUCH',
                );
            }
        }
        foreach (self::TASK_SEVEN_PATHS as $path) {
            if (in_array($path, self::TASK_FOUR_A_PATHS, true)) {
                continue;
            }
            self::guard(
                hash_equals(hash('sha256', $this->gitBytes($owner, $path)), hash('sha256', $this->gitBytes($completionCommit, $path))),
                4,
                'PLAN_1A_TASK_7_BYTE_DRIFT',
            );
        }

        return new TaskSevenProvenance($owner, $base, $completionCommit);
    }

    private static function parse(array $arguments): array
    {
        self::guard($arguments !== [] && in_array($arguments[0], ['contract-lock', 'task-7', 'completion'], true), 2, 'PLAN_1A_EVIDENCE_CLI_INVALID');
        $mode = array_shift($arguments);
        $values = ['mode' => $mode, 'check' => false];
        foreach ($arguments as $argument) {
            if ($argument === '--check') {
                self::guard(!$values['check'], 2, 'PLAN_1A_EVIDENCE_CLI_INVALID');
                $values['check'] = true;
                continue;
            }
            self::guard(str_starts_with($argument, '--') && str_contains($argument, '='), 2, 'PLAN_1A_EVIDENCE_CLI_INVALID');
            [$key, $value] = explode('=', substr($argument, 2), 2);
            self::guard(in_array($key, ['repository-root', 'commit-sha', 'output'], true) && !isset($values[$key]), 2, 'PLAN_1A_EVIDENCE_CLI_INVALID');
            $values[$key] = $value;
        }
        foreach (['repository-root', 'commit-sha', 'output'] as $key) {
            self::guard(isset($values[$key]) && is_string($values[$key]), 2, 'PLAN_1A_EVIDENCE_CLI_INVALID');
        }
        self::guard(preg_match('/^[a-f0-9]{40}$/D', $values['commit-sha']) === 1, 2, 'PLAN_1A_EVIDENCE_CLI_INVALID');
        $paths = [
            'contract-lock' => 'docs/reports/contracts/plan-1a-contract-lock.json',
            'task-7' => 'build/reports/task-7-composer-evidence.json',
            'completion' => 'build/reports/plan-1a-completion.json',
        ];
        self::guard($values['output'] === $paths[$mode], 2, 'PLAN_1A_EVIDENCE_OUTPUT_INVALID');
        return $values;
    }

    private function contractLock(string $commit, string $output, bool $check): void
    {
        self::guard(trim($this->process(['git', 'rev-parse', 'HEAD'])) === $commit, 3, 'PLAN_1A_EVIDENCE_HEAD_MISMATCH');
        $provenance = $this->resolveTaskSevenOwner($commit);
        [$evidence, $evidenceBytes] = $this->regenerateTaskSeven($provenance, !$check);
        $lock = [
            'plan' => '1a',
            'contract_version' => '1.0.0',
            'resources' => ['ReportCatalogResource', 'ReportRunResource', 'ReportRowsResource', 'ReportDrillDownResource', 'ReportExportResource', 'ReportDownloadLinkResource', 'ReportSavedViewResource'],
            'permissions' => ['reports.view', 'reports.run', 'reports.export', 'reports.download', 'reports.manage'],
            'error_count' => 20,
            'definition_lifecycle' => [
                'payload' => 'ReportDefinition',
                'candidate' => 'CandidateReportDefinition',
                'published' => 'PublishedReportDefinition',
                'published_registry_return' => 'PublishedReportDefinition',
                'candidate_registry_return' => 'CandidateReportDefinition',
            ],
            'binding_lifecycle' => ['candidate_validation_returns_runtime_map' => false, 'runtime_registry' => 'published', 'binding_constructor_fields' => 7],
            'owner_port_arity' => ['materialize' => 3, 'result' => 2, 'page' => 5, 'cursor' => 4, 'drill_down' => 3],
            'route_contract' => [
                'provider' => 'App\\BusinessModules\\Core\\Reporting\\ReportingContractsServiceProvider',
                'provider_registration' => 'bootstrap/providers.php',
                'core_route_count' => 12,
                'legacy_aggregator_require' => false,
                'legacy_route_file' => false,
            ],
            'task_4a' => [
                'subject' => self::TASK_FOUR_A_SUBJECT,
                'parent_commit_sha' => $commit,
                'tracked_paths' => self::TASK_FOUR_A_PATHS,
                'malformed_matrix' => ['cases' => 20, 'requests' => 38, 'assertions' => 120],
                'contract_command_counts' => ['tests' => 393, 'assertions' => 3143],
                'retry_idempotency_error' => 'REPORT_IDEMPOTENCY_KEY_INVALID',
                'snapshot_classifications' => ['operational', 'official'],
                'data_classifications' => ['standard', 'sensitive'],
                'output_classification_methods' => [
                    'requiresSensitiveForRows',
                    'requiresAuditForRows',
                    'requiresSensitiveForColumns',
                    'requiresAuditForColumns',
                    'requiresSensitiveForSummary',
                    'requiresAuditForSummary',
                ],
                'saved_view_reference' => ['id', 'revision', 'hash'],
                'retry_idempotency_ports' => ['RetryReportRunAction', 'RetryReportExportAction'],
            ],
            'composer_contract' => [
                'root_constraint' => '^2.6',
                'locked_opis_version' => '2.6.0',
                'added_packages' => ['opis/json-schema', 'opis/string', 'opis/uri'],
                'evidence' => [
                    'artifact_path' => 'build/reports/task-7-composer-evidence.json',
                    'artifact_sha256' => hash('sha256', $evidenceBytes),
                    ...$evidence,
                ],
            ],
        ];
        $bytes = self::encode($lock);
        $path = $this->root.'/'.$output;
        $hashPath = $this->root.'/docs/reports/contracts/plan-1a-contract-lock.sha256';
        if ($check) {
            self::guard(
                is_file($path)
                    && is_file($hashPath)
                    && hash_equals($bytes, (string) file_get_contents($path))
                    && hash_equals(hash('sha256', $bytes)."\n", (string) file_get_contents($hashPath)),
                4,
                'PLAN_1A_LOCK_HASH_DRIFT',
            );
        } else {
            $this->publishGroup([
                $path => $bytes,
                $hashPath => hash('sha256', $bytes)."\n",
            ]);
        }
        fwrite(STDOUT, 'plan-1a-contract-lock: locked task7='.hash('sha256', $evidenceBytes).' lock='.hash('sha256', $bytes).PHP_EOL);
    }

    private function taskSeven(string $commit, string $output, bool $check): void
    {
        self::guard(trim($this->process(['git', 'rev-parse', 'HEAD'])) === $commit, 3, 'PLAN_1A_EVIDENCE_HEAD_MISMATCH');
        $provenance = $this->resolveTaskSevenOwner($commit);
        [$evidence, $bytes] = $this->regenerateTaskSeven($provenance, !$check);
        if ($check) {
            self::guard(is_array($evidence), 4, 'PLAN_1A_TASK_7_EVIDENCE_INVALID');
        } else {
            self::guard($this->root.'/'.$output === $this->root.'/build/reports/task-7-composer-evidence.json', 2, 'PLAN_1A_EVIDENCE_OUTPUT_INVALID');
        }
        fwrite(STDOUT, 'plan-1a-task-7: passed artifact='.hash('sha256', $bytes).PHP_EOL);
    }

    private function completion(string $commit, string $output, bool $check): void
    {
        self::guard(trim($this->process(['git', 'rev-parse', 'HEAD'])) === $commit, 3, 'PLAN_1A_EVIDENCE_HEAD_MISMATCH');
        [$lockBytes, $lock] = $this->readValidatedLock();
        $provenance = $this->resolveTaskSevenOwner($commit);
        $this->fault('completion_after_provenance');
        [$taskSeven, $taskSevenBytes] = $this->regenerateTaskSeven($provenance, !$check);
        $this->fault('completion_after_task7');
        $this->validateTaskSevenLockBinding($lock, $taskSeven, $taskSevenBytes);

        $runner = new Process([
            self::PHP,
            '-c',
            self::PHP_DIR,
            'scripts/reporting/run-plan-1a-gates.php',
            '--repository-root='.$this->root,
            '--commit-sha='.$commit,
            '--output-directory=build/reports',
            '--verify-existing',
        ], $this->root);
        $runner->setTimeout(1200);
        $this->fault('completion_before_runner');
        $runner->run();
        self::guard($runner->isSuccessful(), 5, 'PLAN_1A_GATE_REPLAY_FAILED');
        $this->fault('completion_after_runner');

        $routeBytes = $this->readGate('plan-1a-route-snapshot.json');
        $this->fault('completion_after_route');
        $ledgerBytes = $this->readGate('plan-1a-command-ledger.json');
        $this->fault('completion_after_ledger');
        $authorizationBytes = $this->readGate('plan-1a-ci-authorization.json');
        $this->fault('completion_after_authorization');
        $malformedBytes = $this->readGate('plan-1a-ci-malformed.json');
        $this->fault('completion_after_malformed');
        $ledger = json_decode($ledgerBytes, true, 512, JSON_THROW_ON_ERROR);
        $authorization = json_decode($authorizationBytes, true, 512, JSON_THROW_ON_ERROR);
        $malformed = json_decode($malformedBytes, true, 512, JSON_THROW_ON_ERROR);
        $completion = [
            'plan' => '1a',
            'status' => 'passed',
            'commit_sha' => $commit,
            'contract_lock_sha256' => hash('sha256', $lockBytes),
            'resource_schema_sha256' => hash_file('sha256', $this->root.'/docs/reports/contracts/reporting-admin-resources.v1.schema.json'),
            'route_snapshot_sha256' => hash('sha256', $routeBytes),
            'commands' => $ledger['commands'],
            'ci_http_matrices' => [
                'authorization' => [
                    'status' => $authorization['status'],
                    'verification_mode' => $authorization['verification_mode'],
                    'cases' => $authorization['counts']['cases'],
                    'passed' => $authorization['counts']['passed'],
                    'artifact_sha256' => hash('sha256', $authorizationBytes),
                ],
                'malformed_requests' => [
                    'status' => $malformed['status'],
                    'verification_mode' => $malformed['verification_mode'],
                    'cases' => $malformed['counts']['cases'],
                    'passed' => $malformed['counts']['passed'],
                    'artifact_sha256' => hash('sha256', $malformedBytes),
                ],
            ],
        ];
        $bytes = self::encode($completion);
        $this->validateSchema($bytes, 'docs/reports/contracts/plan-1a-completion.schema.json');
        if (!$check) {
            $this->publishGroup([$this->root.'/'.$output => $bytes]);
        }
        fwrite(STDOUT, 'plan-1a-completion: passed digests=5 commands=2 authorization=22/22 malformed=20/20'.PHP_EOL);
    }

    private function readValidatedLock(): array
    {
        $lockPath = $this->root.'/docs/reports/contracts/plan-1a-contract-lock.json';
        $sidecarPath = $this->root.'/docs/reports/contracts/plan-1a-contract-lock.sha256';
        self::guard(
            is_file($lockPath)
                && is_file($sidecarPath)
                && hash_file('sha256', $lockPath)."\n" === (string) file_get_contents($sidecarPath),
            4,
            'PLAN_1A_LOCK_HASH_DRIFT',
        );
        $bytes = (string) file_get_contents($lockPath);
        $lock = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        self::guard(is_array($lock), 4, 'PLAN_1A_LOCK_INVALID');

        return [$bytes, $lock];
    }

    private function validateTaskSevenLockBinding(array $lock, array $taskSeven, string $taskSevenBytes): void
    {
        $locked = $lock['composer_contract']['evidence'] ?? null;
        self::guard(
            is_array($locked)
                && is_string($locked['artifact_sha256'] ?? null)
                && hash_equals($locked['artifact_sha256'], hash('sha256', $taskSevenBytes)),
            4,
            'PLAN_1A_TASK_7_LOCK_BINDING_DRIFT',
        );
        self::guard(array_slice($locked, 2) === $taskSeven, 4, 'PLAN_1A_TASK_7_LOCK_FIELDS_DRIFT');
    }

    private function regenerateTaskSeven(TaskSevenProvenance $provenance, bool $write): array
    {
        $output = $this->root.'/build/reports/task-7-composer-evidence.json';
        if (self::$taskSevenRegenerationOverride instanceof Closure) {
            $overridden = (self::$taskSevenRegenerationOverride)($provenance, $write, $this->root);
            self::guard(
                is_array($overridden)
                    && count($overridden) === 2
                    && is_array($overridden[0] ?? null)
                    && is_string($overridden[1] ?? null),
                4,
                'PLAN_1A_TASK_7_REGENERATION_OVERRIDE_INVALID',
            );
            if ($write) {
                self::guard(
                    file_put_contents($output, $overridden[1], LOCK_EX) !== false,
                    4,
                    'PLAN_1A_TASK_7_REGENERATION_FAILED',
                );
            }

            return $overridden;
        }
        $existingBytes = is_file($output) ? (string) file_get_contents($output) : null;
        $existingMtime = is_file($output) ? filemtime($output) : null;
        if (!is_dir(dirname($output))) {
            self::guard($write && mkdir(dirname($output), 0777, true), 4, 'PLAN_1A_TASK_7_OUTPUT_CREATE_FAILED');
        }
        $arguments = [
            self::PHP,
            '-c',
            self::PHP_DIR,
            'scripts/reporting/verify-task-7-composer.php',
            '--baseline-commit='.$provenance->baseCommit,
            '--reviewed-commit='.$provenance->ownerCommit,
            '--expected-composer-json-sha256='.hash('sha256', $this->gitBytes($provenance->baseCommit, 'composer.json')),
            '--expected-composer-lock-sha256='.hash('sha256', $this->gitBytes($provenance->baseCommit, 'composer.lock')),
        ];
        $arguments[] = $write ? '--output=build/reports/task-7-composer-evidence.json' : '--check';
        $process = new Process($arguments, $this->root);
        $process->run();
        self::guard($process->isSuccessful(), 4, 'PLAN_1A_TASK_7_REGENERATION_FAILED');
        if ($write) {
            self::guard(is_file($output), 4, 'PLAN_1A_TASK_7_REGENERATION_FAILED');
            $bytes = (string) file_get_contents($output);
            $evidence = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } else {
            clearstatcache(true, $output);
            self::guard(
                $existingBytes === null
                    ? !file_exists($output)
                    : is_file($output)
                        && hash_equals($existingBytes, (string) file_get_contents($output))
                        && $existingMtime === filemtime($output),
                4,
                'PLAN_1A_TASK_7_CHECK_MUTATED_OUTPUT',
            );
            $composer = json_decode($this->gitBytes($provenance->ownerCommit, 'composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $lock = json_decode($this->gitBytes($provenance->ownerCommit, 'composer.lock'), true, 512, JSON_THROW_ON_ERROR);
            $evidence = [
                'status' => 'task_7_composer_contract_passed',
                'baseline_commit_sha' => $provenance->baseCommit,
                'reviewed_commit_sha' => $provenance->ownerCommit,
                'composer_json_before_sha256' => hash('sha256', $this->gitBytes($provenance->baseCommit, 'composer.json')),
                'composer_lock_before_sha256' => hash('sha256', $this->gitBytes($provenance->baseCommit, 'composer.lock')),
                'composer_json_after_sha256' => hash('sha256', $this->gitBytes($provenance->ownerCommit, 'composer.json')),
                'composer_lock_after_sha256' => hash('sha256', $this->gitBytes($provenance->ownerCommit, 'composer.lock')),
                'root_constraint' => $composer['require']['opis/json-schema'] ?? null,
                'locked_opis_version' => $this->lockedPackageVersion($lock, 'opis/json-schema'),
                'added_packages' => ['opis/json-schema', 'opis/string', 'opis/uri'],
                'content_hash' => $lock['content-hash'] ?? null,
            ];
            $bytes = self::encode($evidence);
        }
        self::guard(array_keys($evidence) === [
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
        ], 4, 'PLAN_1A_TASK_7_EVIDENCE_SHAPE_INVALID');
        return [$evidence, $bytes];
    }

    private function trailers(string $body): array
    {
        $directory = $this->root.'/build/reports';
        if (!is_dir($directory)) {
            self::guard(mkdir($directory, 0777, true), 3, 'PLAN_1A_TASK_7_TRAILER_PARSE_FAILED');
        }
        $temporary = $this->temporaryPath($directory, 'trailers');
        try {
            self::guard(file_put_contents($temporary, $body, LOCK_EX) !== false, 3, 'PLAN_1A_TASK_7_TRAILER_PARSE_FAILED');
            $process = new Process(['git', 'interpret-trailers', '--parse', $temporary], $this->root);
            $process->setTimeout(10);
            $process->run();
            self::guard($process->isSuccessful(), 3, 'PLAN_1A_TASK_7_TRAILER_PARSE_FAILED');
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        $result = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $result[$key][] = trim($value);
        }

        return $result;
    }

    private function changedPaths(string $commit): array
    {
        $bytes = $this->process(['git', 'diff-tree', '--no-renames', '--no-commit-id', '--name-only', '-r', '-z', $commit]);
        $paths = array_values(array_filter(explode("\0", $bytes), static fn (string $path): bool => $path !== ''));
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function readGate(string $file): string
    {
        $path = $this->root.'/build/reports/'.$file;
        self::guard(is_file($path), 5, 'PLAN_1A_GATE_ARTIFACT_MISSING');
        $bytes = (string) file_get_contents($path);
        $this->validateSchema($bytes, 'docs/reports/contracts/plan-1a-gate-evidence.schema.json');

        return $bytes;
    }

    private function validateSchema(string $bytes, string $schemaPath): void
    {
        $data = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
        $schema = json_decode((string) file_get_contents($this->root.'/'.$schemaPath), false, 512, JSON_THROW_ON_ERROR);
        self::guard((new CompliantValidator())->validate($data, $schema)->isValid(), 3, 'PLAN_1A_EVIDENCE_SCHEMA_INVALID');
    }

    private function gitBytes(string $commit, string $path): string
    {
        return $this->process(['git', 'show', $commit.':'.$path]);
    }

    private function process(array $arguments, ?string $root = null): string
    {
        $process = new Process($arguments, $root ?? $this->root);
        $process->run();
        self::guard($process->isSuccessful(), 3, 'PLAN_1A_EVIDENCE_GIT_PROCESS_FAILED');

        return $process->getOutput();
    }

    private function processExit(array $arguments): int
    {
        $process = new Process($arguments, $this->root);
        $process->run();

        return $process->getExitCode() ?? 1;
    }

    private function fault(string $boundary): void
    {
        if (self::$faultOverride instanceof Closure) {
            (self::$faultOverride)($boundary);
        }
    }

    private function publishGroup(array $outputs): void
    {
        try {
            foreach ($outputs as $path => $bytes) {
                $directory = dirname($path);
                $temporary = $this->temporaryPath($directory, 'write');
                self::guard(file_put_contents($temporary, $bytes, LOCK_EX) !== false, 6, 'PLAN_1A_EVIDENCE_OUTPUT_WRITE_FAILED');
                $this->fault('after_temporary_write:'.basename($path));
                self::guard(@rename($temporary, $path), 6, 'PLAN_1A_EVIDENCE_OUTPUT_WRITE_FAILED');
                $this->fault('after_publish:'.basename($path));
                self::guard(
                    hash_equals($bytes, (string) file_get_contents($path)),
                    6,
                    'PLAN_1A_EVIDENCE_OUTPUT_REREAD_FAILED',
                );
                $this->fault('after_reread:'.basename($path));
            }
        } catch (Throwable $failure) {
            $this->cleanup(array_keys($outputs));
            throw $failure;
        }
    }

    private function modeOutputs(string $mode): array
    {
        return match ($mode) {
            'contract-lock' => [
                $this->root.'/build/reports/task-7-composer-evidence.json',
                $this->root.'/docs/reports/contracts/plan-1a-contract-lock.json',
                $this->root.'/docs/reports/contracts/plan-1a-contract-lock.sha256',
            ],
            'task-7' => [$this->root.'/build/reports/task-7-composer-evidence.json'],
            'completion' => [
                $this->root.'/build/reports/task-7-composer-evidence.json',
                $this->root.'/build/reports/plan-1a-route-snapshot.json',
                $this->root.'/build/reports/plan-1a-ci-authorization.json',
                $this->root.'/build/reports/plan-1a-ci-malformed.json',
                $this->root.'/build/reports/plan-1a-command-ledger.json',
                $this->root.'/build/reports/plan-1a-completion.json',
            ],
        };
    }

    private function precleanOutputs(string $mode): array
    {
        return match ($mode) {
            'completion' => [
                $this->root.'/build/reports/task-7-composer-evidence.json',
                $this->root.'/build/reports/plan-1a-completion.json',
            ],
            default => $this->modeOutputs($mode),
        };
    }

    private function validateGeneratedOutputGovernance(string $mode): void
    {
        $paths = $mode === 'completion'
            ? [
                'build/reports/task-7-composer-evidence.json',
                'build/reports/plan-1a-route-snapshot.json',
                'build/reports/plan-1a-ci-authorization.json',
                'build/reports/plan-1a-ci-malformed.json',
                'build/reports/plan-1a-command-ledger.json',
                'build/reports/plan-1a-completion.json',
            ]
            : ['build/reports/task-7-composer-evidence.json'];
        self::guard(
            trim($this->process(['git', 'ls-files', '--', ...$paths])) === '',
            3,
            'PLAN_1A_EVIDENCE_OUTPUT_TRACKED',
        );
        self::guard(
            $this->processExit(['git', 'check-ignore', '--no-index', '--', ...$paths]) === 0,
            3,
            'PLAN_1A_EVIDENCE_OUTPUT_NOT_IGNORED',
        );
    }

    private function validateRuntimePreflight(string $mode): void
    {
        $branch = self::$branchOverride instanceof Closure
            ? (self::$branchOverride)($this->root, $mode)
            : trim($this->process(['git', 'branch', '--show-current']));
        self::guard(
            $branch === self::CANONICAL_BRANCH,
            3,
            'PLAN_1A_EVIDENCE_BRANCH_INVALID',
        );
        $phpVersion = self::$phpVersionOverride instanceof Closure
            ? (self::$phpVersionOverride)()
            : PHP_VERSION;
        self::guard($phpVersion === '8.2.29', 2, 'PLAN_1A_EVIDENCE_PHP_VERSION_INVALID');
        $phpHash = self::$phpHashOverride instanceof Closure
            ? (self::$phpHashOverride)()
            : hash_file('sha256', self::PHP);
        self::guard($phpHash === self::PHP_SHA256, 2, 'PLAN_1A_EVIDENCE_PHP_HASH_INVALID');
    }

    private function cleanup(array $outputs): void
    {
        foreach ($outputs as $path) {
            if (is_file($path)) {
                self::guard(unlink($path), 6, 'PLAN_1A_EVIDENCE_OUTPUT_CLEANUP_FAILED');
            }
        }
        foreach ([
            $this->root.'/build/reports',
            $this->root.'/docs/reports/contracts',
        ] as $directory) {
            foreach (glob($directory.'/.plan-1a-*.tmp') ?: [] as $path) {
                if (is_file($path)) {
                    self::guard(unlink($path), 6, 'PLAN_1A_EVIDENCE_OUTPUT_CLEANUP_FAILED');
                }
            }
        }
    }

    private function validateModeGitState(string $mode, string $commit): void
    {
        self::guard(trim($this->process(['git', 'rev-parse', 'HEAD'])) === $commit, 3, 'PLAN_1A_EVIDENCE_HEAD_MISMATCH');
        $staged = $this->gitPaths(['diff', '--cached', '--name-only', '-z']);
        $unstaged = $this->gitPaths(['diff', '--name-only', '-z']);
        $untracked = $this->gitPaths(['ls-files', '--others', '--exclude-standard', '-z']);
        $working = array_values(array_unique([...$unstaged, ...$untracked]));
        sort($working, SORT_STRING);
        if ($mode === 'contract-lock') {
            $preGenerationPaths = array_values(array_filter(
                self::TASK_FOUR_A_PATHS,
                static fn (string $path): bool => !in_array($path, [
                    'docs/reports/contracts/plan-1a-contract-lock.json',
                    'docs/reports/contracts/plan-1a-contract-lock.sha256',
                ], true),
            ));
            self::guard(
                ($staged === [] && ($working === $preGenerationPaths || $working === self::TASK_FOUR_A_PATHS))
                    || ($staged === self::TASK_FOUR_A_PATHS && $working === []),
                3,
                'PLAN_1A_EVIDENCE_PRECOMMIT_STATE_INVALID',
            );

            return;
        }
        self::guard($staged === [] && $working === [], 3, 'PLAN_1A_EVIDENCE_WORKTREE_DIRTY');
        $this->validateCanonicalTaskFourACommit($commit);
    }

    private function validateCanonicalTaskFourACommit(string $commit): void
    {
        self::guard(trim($this->process(['git', 'show', '-s', '--format=%s', $commit])) === self::TASK_FOUR_A_SUBJECT, 3, 'PLAN_1A_EVIDENCE_TASK_4A_SUBJECT_INVALID');
        $parents = preg_split('/\s+/', trim($this->process(['git', 'show', '-s', '--format=%P', $commit]))) ?: [];
        self::guard(count($parents) === 1, 3, 'PLAN_1A_EVIDENCE_TASK_4A_PARENT_INVALID');
        self::guard($this->changedPaths($commit) === self::TASK_FOUR_A_PATHS, 3, 'PLAN_1A_EVIDENCE_TASK_4A_PATHS_INVALID');
        foreach (self::TASK_FOUR_A_PATHS as $path) {
            self::guard(
                is_file($this->root.'/'.$path)
                    && hash_equals(hash('sha256', $this->gitBytes($commit, $path)), hash_file('sha256', $this->root.'/'.$path)),
                3,
                'PLAN_1A_EVIDENCE_TASK_4A_BYTES_INVALID',
            );
        }
    }

    private function gitPaths(array $arguments): array
    {
        $paths = array_values(array_filter(explode("\0", $this->process(['git', ...$arguments])), static fn (string $path): bool => $path !== ''));
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function ensureDirectory(string $relative): string
    {
        $segments = explode('/', $relative);
        $current = $this->root;
        foreach ($segments as $segment) {
            $current .= '/'.$segment;
            if (!file_exists($current)) {
                self::guard(mkdir($current, 0777), 6, 'PLAN_1A_EVIDENCE_OUTPUT_CREATE_FAILED');
            }
            self::guard(is_dir($current) && !is_link($current), 2, 'PLAN_1A_EVIDENCE_OUTPUT_PATH_INVALID');
            self::guard(
                strcasecmp(str_replace('\\', '/', (string) realpath($current)), str_replace('\\', '/', $current)) === 0,
                2,
                'PLAN_1A_EVIDENCE_OUTPUT_PATH_INVALID',
            );
        }

        return $current;
    }

    private function requireDirectory(string $relative): string
    {
        $current = $this->root;
        foreach (explode('/', $relative) as $segment) {
            $current .= '/'.$segment;
            self::guard(is_dir($current) && !is_link($current), 2, 'PLAN_1A_EVIDENCE_OUTPUT_PATH_INVALID');
            self::guard(
                strcasecmp(str_replace('\\', '/', (string) realpath($current)), str_replace('\\', '/', $current)) === 0,
                2,
                'PLAN_1A_EVIDENCE_OUTPUT_PATH_INVALID',
            );
        }

        return $current;
    }

    private function temporaryPath(string $directory, string $purpose): string
    {
        self::guard(is_dir($directory) && !is_link($directory), 2, 'PLAN_1A_EVIDENCE_OUTPUT_PATH_INVALID');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $path = $directory.'/.plan-1a-'.$purpose.'-'.bin2hex(random_bytes(8)).'.tmp';
            $handle = @fopen($path, 'x+b');
            if (is_resource($handle)) {
                fclose($handle);

                return $path;
            }
        }
        throw new PlanOneAEvidenceFailure(6, 'PLAN_1A_EVIDENCE_OUTPUT_WRITE_FAILED');
    }

    private function lockedPackageVersion(array $lock, string $name): ?string
    {
        foreach ($lock['packages'] ?? [] as $package) {
            if (($package['name'] ?? null) === $name) {
                return is_string($package['version'] ?? null) ? $package['version'] : null;
            }
        }

        return null;
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private static function guard(bool $condition, int $exit, string $message): void
    {
        if (!$condition) {
            throw new PlanOneAEvidenceFailure($exit, $message);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(PlanOneAEvidence::execute($argv));
}
