<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Actions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportAccessService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportActorLoader;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Access\ReportSourceAccessResolver;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CancelReportRunHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\CreateReportRunHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportRunHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\RetryReportRunHandler;
use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealVerifier;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Execution\CanonicalReportSourceHashBuilder;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunCoordinator;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunRetrySource;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealValidator;
use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportRunData;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSavedViewReferenceResolver;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Security\TrustedReportSnapshotSealVerifier;
use DateTimeImmutable;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\DeterministicReportModuleEntitlement;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class ReportRunHandlersTest extends TestCase
{
    public function test_every_task_four_c_public_surface_is_exact(): void
    {
        $this->assertConstructor(
            ReportRunCoordinator::class,
            [
                ['definitions', ReportDefinitionRegistry::class],
                ['savedViews', ReportSavedViewReferenceResolver::class],
                ['access', ReportAccessService::class],
                ['runs', ReportRunStore::class],
                ['clock', ReportExecutionClock::class],
            ],
        );
        $this->assertMethod(ReportRunCoordinator::class, 'create', [
            ['context', ReportExecutionContext::class],
            ['data', CreateReportRunData::class],
            ['idempotencyKey', IdempotencyKey::class],
        ], ReportRun::class);
        $this->assertMethod(ReportRunCoordinator::class, 'get', [
            ['context', ReportExecutionContext::class],
            ['runId', 'string'],
        ], ReportRun::class);
        $this->assertMethod(ReportRunCoordinator::class, 'retry', [
            ['context', ReportExecutionContext::class],
            ['runId', 'string'],
            ['key', IdempotencyKey::class],
        ], ReportRun::class);
        $this->assertMethod(ReportRunCoordinator::class, 'cancel', [
            ['context', ReportExecutionContext::class],
            ['runId', 'string'],
        ], ReportRun::class);

        foreach ([
            CreateReportRunHandler::class => [CreateReportRunAction::class, 'handle', [
                ['context', ReportExecutionContext::class],
                ['data', CreateReportRunData::class],
                ['key', IdempotencyKey::class],
            ]],
            GetReportRunHandler::class => [GetReportRunAction::class, 'handle', [
                ['context', ReportExecutionContext::class],
                ['runId', 'string'],
            ]],
            RetryReportRunHandler::class => [RetryReportRunAction::class, 'handle', [
                ['context', ReportExecutionContext::class],
                ['runId', 'string'],
                ['idempotencyKey', IdempotencyKey::class],
            ]],
            CancelReportRunHandler::class => [CancelReportRunAction::class, 'handle', [
                ['context', ReportExecutionContext::class],
                ['runId', 'string'],
            ]],
        ] as $handler => [$interface, $method, $parameters]) {
            self::assertSame([$interface], array_values(class_implements($handler)));
            $this->assertConstructor($handler, [['coordinator', ReportRunCoordinator::class]]);
            $this->assertMethod($handler, $method, $parameters, ReportRun::class);
        }

        $this->assertMethodSurface(ReportRunStore::class, [
            'createOrReuse',
            'get',
            'queryForRun',
            'retrySource',
            'exportSource',
            'claimMaterialization',
            'persistProgress',
            'sealReady',
            'fail',
            'cancel',
        ]);

        self::assertNull((new \ReflectionClass(CanonicalReportSourceHashBuilder::class))->getConstructor());
        $this->assertMethod(CanonicalReportSourceHashBuilder::class, 'build', [
            ['query', ReportQuery::class],
            ['snapshot', ReportSnapshotRef::class],
            ['result', ReportResult::class],
        ], Sha256Hash::class);
        $this->assertMethodSurface(CanonicalReportSourceHashBuilder::class, ['build']);

        $this->assertMethod(ReportSnapshotSealVerifier::class, 'assertTrusted', [
            ['input', ReportSnapshotSealVerificationInput::class],
        ], 'void');
        $this->assertMethodSurface(ReportSnapshotSealVerifier::class, ['assertTrusted']);

        $this->assertConstructor(ReportSnapshotSealVerificationInput::class, [
            ['seal', \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal::class],
            ['snapshotId', 'string'],
            ['snapshotKind', 'string'],
            ['snapshotClassification', \App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification::class],
            ['generatedAt', DateTimeImmutable::class],
            ['calculatedSourceHash', Sha256Hash::class],
        ]);
        $this->assertMethod(ReportSnapshotSealVerificationInput::class, 'signedBytes', [], 'string');
        $this->assertMethodSurface(ReportSnapshotSealVerificationInput::class, ['__construct', 'signedBytes']);

        $this->assertConstructor(TrustedReportSnapshotSealVerifier::class, [['trustedSealKeys', 'array']]);
        $this->assertMethod(TrustedReportSnapshotSealVerifier::class, 'assertTrusted', [
            ['input', ReportSnapshotSealVerificationInput::class],
        ], 'void');
        $this->assertMethodSurface(TrustedReportSnapshotSealVerifier::class, ['__construct', 'assertTrusted']);

        $this->assertConstructor(ReportSnapshotSealValidator::class, [['verifier', ReportSnapshotSealVerifier::class]]);
        $this->assertMethod(ReportSnapshotSealValidator::class, 'assertSealable', [
            ['query', ReportQuery::class],
            ['snapshot', ReportSnapshotRef::class],
            ['result', ReportResult::class],
            ['calculatedSourceHash', Sha256Hash::class],
        ], 'void');
        $this->assertMethodSurface(ReportSnapshotSealValidator::class, ['__construct', 'assertSealable']);
    }

    public function test_ast_dependency_graph_has_no_dispatcher_or_direct_record_access(): void
    {
        $files = [
            'app/BusinessModules/Core/Reporting/Application/Execution/CanonicalReportSourceHashBuilder.php',
            'app/BusinessModules/Core/Reporting/Application/Contracts/Execution/ReportSnapshotSealVerifier.php',
            'app/BusinessModules/Core/Reporting/Application/Execution/ReportSnapshotSealVerificationInput.php',
            'app/BusinessModules/Core/Reporting/Infrastructure/Security/TrustedReportSnapshotSealVerifier.php',
            'app/BusinessModules/Core/Reporting/Application/Execution/ReportSnapshotSealValidator.php',
            'app/BusinessModules/Core/Reporting/Application/Execution/ReportRunCoordinator.php',
            'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CreateReportRunHandler.php',
            'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/GetReportRunHandler.php',
            'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/RetryReportRunHandler.php',
            'app/BusinessModules/Core/Reporting/Application/Actions/Handlers/CancelReportRunHandler.php',
        ];
        foreach ($files as $file) {
            self::assertSame([], $this->forbiddenDependencyNames($this->fileSource($file)), $file);
        }
    }

    public function test_resolved_dependency_gate_rejects_aliases_and_every_direct_persistence_surface(): void
    {
        $mutants = [
            'aliased_record' => '<?php use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord as RunRow; RunRow::query();',
            'aliased_dispatcher' => '<?php use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportMaterializationDispatcher as Delivery; function f(Delivery $delivery): void {}',
            'db_facade' => '<?php use Illuminate\Support\Facades\DB as Database; Database::table("report_runs");',
            'eloquent_model' => '<?php use Illuminate\Database\Eloquent\Model as Entity; class Row extends Entity {}',
            'query_builder' => '<?php use Illuminate\Database\Eloquent\Builder as Query; function f(Query $query): void {}',
        ];

        foreach ($mutants as $name => $source) {
            self::assertNotSame([], $this->forbiddenDependencyNames($source), $name);
        }
    }

    public function test_eloquent_overlap_has_exact_reachable_receiver_and_record_data_flow(): void
    {
        $source = $this->fileSource('app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php');

        self::assertSame([], $this->storeFlowViolations($source, 'get', 'findIncludingExpired', 'hydrate', 2));
        self::assertSame([], $this->storeFlowViolations($source, 'queryForRun', 'findIncludingExpired', 'query', 2));
        self::assertSame([], $this->storeFlowViolations($source, 'exportSource', 'find', 'exportSource', 3));
    }

    public function test_eloquent_flow_gate_rejects_dead_decoy_wrong_receiver_and_dynamic_lookup_mutants(): void
    {
        $source = $this->fileSource('app/BusinessModules/Core/Reporting/Infrastructure/Persistence/EloquentReportRunStore.php');
        $mutants = [
            'dead_decoy' => str_replace(
                '$record = $this->findIncludingExpired($context, $runId);',
                '$this->findIncludingExpired($context, $runId); $record = $this->find($context, $runId);',
                $source,
            ),
            'wrong_receiver' => str_replace(
                '$record = $this->findIncludingExpired($context, $runId);',
                '$record = $other->findIncludingExpired($context, $runId);',
                $source,
            ),
            'dynamic_lookup' => str_replace(
                '$record = $this->findIncludingExpired($context, $runId);',
                '$lookup = "findIncludingExpired"; $record = $this->{$lookup}($context, $runId);',
                $source,
            ),
            'wrong_flow' => str_replace(
                'return $this->hydrator->hydrate($record, \'reused\', $this->pollAfterMs);',
                'return $this->hydrator->hydrate($otherRecord, \'reused\', $this->pollAfterMs);',
                $source,
            ),
        ];

        foreach ($mutants as $name => $mutant) {
            self::assertNotSame(
                [],
                $this->storeFlowViolations($mutant, 'get', 'findIncludingExpired', 'hydrate', 2),
                $name,
            );
        }

        $exportMutants = [
            'expired_guard_bypass' => str_replace(
                '$this->isExpiredForExport($record->expires_at, $this->clock->now())',
                'false',
                $source,
            ),
            'expired_inclusive_export_lookup' => preg_replace(
                '/(\$record = \$this->)find(\(\$context, \$runId\);\R        if \(\$this->isExpiredForExport)/',
                '$1findIncludingExpired$2',
                $source,
                1,
            ),
        ];
        foreach ($exportMutants as $name => $mutant) {
            self::assertIsString($mutant);
            self::assertNotSame(
                [],
                $this->storeFlowViolations($mutant, 'exportSource', 'find', 'exportSource', 3),
                $name,
            );
        }
    }

    public function test_create_forwards_explicit_key_and_resolved_saved_view(): void
    {
        [$coordinator, $store, $savedViews, $context] = $this->fixture();
        $key = new IdempotencyKey('run-key-1');
        $data = new CreateReportRunData('report', new ReportFilterSet([]), [], new DateTimeImmutable('2026-07-29T07:00:00Z'), 'ru-RU', $savedViews->reference->id);

        $run = (new CreateReportRunHandler($coordinator))->handle($context, $data, $key);

        self::assertSame($store->run, $run);
        self::assertSame($key, $store->lastKey);
        self::assertSame($savedViews->reference, $store->lastSavedView);
        self::assertSame('report', $store->lastQuery?->definition->code);
    }

    public function test_create_reuses_same_explicit_key_and_conflicts_when_canonical_body_changes(): void
    {
        [$coordinator, $store, , $context] = $this->fixture();
        $key = new IdempotencyKey('run-key-1');
        $first = new CreateReportRunData('report', new ReportFilterSet([]), [], new DateTimeImmutable('2026-07-29T07:00:00Z'), 'ru-RU', null);
        $changed = new CreateReportRunData('report', new ReportFilterSet([]), ['period' => 'previous'], new DateTimeImmutable('2026-07-29T07:00:00Z'), 'ru-RU', null);

        self::assertSame(
            (new CreateReportRunHandler($coordinator))->handle($context, $first, $key),
            (new CreateReportRunHandler($coordinator))->handle($context, $first, $key),
        );
        self::assertSame(2, $store->createCalls);

        try {
            (new CreateReportRunHandler($coordinator))->handle($context, $changed, $key);
            self::fail('Changed canonical body reused the key.');
        } catch (ReportContractException $exception) {
            self::assertSame(\App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT, $exception->errorCode);
        }
    }

    public function test_saved_view_resolve_and_current_rejections_stop_before_store_creation(): void
    {
        [$coordinator, $store, $savedViews, $context] = $this->fixture();
        $savedViews->rejectResolve = true;
        $data = new CreateReportRunData('report', new ReportFilterSet([]), [], new DateTimeImmutable('2026-07-29T07:00:00Z'), 'ru-RU', $savedViews->reference->id);

        try {
            (new CreateReportRunHandler($coordinator))->handle($context, $data, new IdempotencyKey('run-key-1'));
            self::fail('Invalid saved view was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $store->createCalls);
        }

        $savedViews->rejectResolve = false;
        $savedViews->rejectCurrent = true;
        $store->retrySource = new ReportRunRetrySource(
            $this->makeRun(ReportRunStatus::CANCELLED, $store->query),
            $store->query,
            $savedViews->reference,
            null,
        );
        try {
            (new RetryReportRunHandler($coordinator))->handle($context, $store->run->id, new IdempotencyKey('retry-key-1'));
            self::fail('Stale saved view was accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, $store->createCalls);
        }
    }

    public function test_retry_preserves_query_and_saved_view_tuple_and_forwards_explicit_key(): void
    {
        [$coordinator, $store, $savedViews, $context] = $this->fixture();
        $store->retrySource = new ReportRunRetrySource(
            $this->makeRun(ReportRunStatus::FAILED, $store->query),
            $store->query,
            $savedViews->reference,
            \App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_DEPENDENCY_FAILED,
        );
        $key = new IdempotencyKey('retry-key-1');

        (new RetryReportRunHandler($coordinator))->handle($context, $store->run->id, $key);

        self::assertSame($savedViews->reference, $savedViews->asserted);
        self::assertSame($store->retrySource->query, $store->lastQuery);
        self::assertSame($key, $store->lastKey);
    }

    public function test_retry_accepts_each_exact_terminal_status_and_ready_is_rejected(): void
    {
        foreach ([ReportRunStatus::FAILED, ReportRunStatus::CANCELLED, ReportRunStatus::EXPIRED] as $status) {
            [$coordinator, $store, , $context] = $this->fixture();
            $store->retrySource = new ReportRunRetrySource(
                $this->makeRun($status, $store->query),
                $store->query,
                null,
                $status === ReportRunStatus::FAILED
                    ? \App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_DEPENDENCY_FAILED
                    : null,
            );

            self::assertSame(
                $store->run,
                (new RetryReportRunHandler($coordinator))->handle($context, $store->run->id, new IdempotencyKey('retry-key-'.$status->value)),
                $status->value,
            );
        }

        [, $readyStore] = $this->fixture();
        $ready = (new ReportRunBuilder)
            ->definitionHash($readyStore->query->definition->definitionHash)
            ->queryHash($readyStore->query->queryHash)
            ->ready();

        $this->expectException(InvalidArgumentException::class);
        new ReportRunRetrySource(
            $ready,
            $readyStore->query,
            null,
            null,
        );
    }

    public function test_retry_authorizes_original_definition_without_resolving_current_registry_definition(): void
    {
        $oldPolicy = new ReportPermissionPolicy(['reports.old'], ['reports.export'], [], []);
        $oldDefinition = (new ReportDefinitionBuilder)->permissionPolicy($oldPolicy)->payload();
        $context = (new ReportExecutionContextBuilder)->actor(new ReportActor(1, 'active', ['reports.view', 'reports.run', 'reports.old']))->build();
        $oldQuery = new ReportQuery($oldDefinition, $context->scope, new ReportFilterSet([]), [], new DateTimeImmutable('2026-07-29T07:00:00Z'), 'ru-RU');
        $store = new RecordingRunStore($oldQuery, $this->makeRun(ReportRunStatus::QUEUED, $oldQuery));
        $store->retrySource = new ReportRunRetrySource($this->makeRun(ReportRunStatus::CANCELLED, $oldQuery), $oldQuery, null, null);
        $newDefinition = (new ReportDefinitionBuilder)
            ->definitionHash(new Sha256Hash(str_repeat('f', 64)))
            ->permissionPolicy(new ReportPermissionPolicy(['reports.new'], ['reports.export'], [], []))
            ->published();
        $registry = new SingleDefinitionRegistry($newDefinition);
        $coordinator = new ReportRunCoordinator(
            $registry,
            new RecordingSavedViewResolver,
            new ReportAccessService(
                new RecordingActorLoader(new ReportActor(1, 'active', ['reports.view', 'reports.run', 'reports.old'])),
                new PermissiveSourceResolver,
                new ReportDefinitionVisibilityResolver(
                    new ReportDefinitionModuleAuthorizer(new DeterministicReportModuleEntitlement),
                ),
            ),
            $store,
            new FixedClock(new DateTimeImmutable('2026-07-29T09:30:00Z')),
        );

        (new RetryReportRunHandler($coordinator))->handle($context, $store->run->id, new IdempotencyKey('retry-original-definition'));

        self::assertSame(0, $registry->publishedCalls);
        self::assertSame($oldDefinition, $store->lastQuery?->definition);
    }

    public function test_get_checks_sensitive_before_audit_for_summary(): void
    {
        $output = new ReportOutputClassification(ReportDataClassification::STANDARD, ['name'], ['name'], true, true, true);
        [$coordinator, , , $context, $actorLoader] = $this->fixture($output, [
            'reports.view',
            'reports.run',
            'reports.sensitive',
        ]);

        try {
            (new GetReportRunHandler($coordinator))->handle($context, '01J00000000000000000000000');
            self::fail('Audit denial was expected.');
        } catch (ReportContractException) {
            self::assertSame(3, $actorLoader->loads);
        }
    }

    public function test_cancel_uses_store_cas_at_exact_clock_instant_without_local_status_check(): void
    {
        [$coordinator, $store, , $context] = $this->fixture();

        $run = (new CancelReportRunHandler($coordinator))->handle($context, $store->run->id);

        self::assertSame($store->run, $run);
        self::assertSame('2026-07-29T09:30:00.123456+00:00', $store->cancelledAt?->format('Y-m-d\TH:i:s.uP'));
    }

    public function test_cancel_propagates_store_race_without_local_status_branch(): void
    {
        [$coordinator, $store, , $context] = $this->fixture();
        $store->cancelRace = true;

        try {
            (new CancelReportRunHandler($coordinator))->handle($context, $store->run->id);
            self::fail('Store race was hidden.');
        } catch (ReportContractException $exception) {
            self::assertSame(\App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_SNAPSHOT_NOT_READY, $exception->errorCode);
            self::assertSame(1, $store->cancelCalls);
        }
    }

    public function test_run_and_view_denials_stop_each_mutating_store_boundary(): void
    {
        [$coordinator, $store, , $context] = $this->fixture(null, ['reports.view']);
        $store->retrySource = new ReportRunRetrySource(
            $this->makeRun(ReportRunStatus::CANCELLED, $store->query),
            $store->query,
            null,
            null,
        );
        $attempts = [
            'create' => static fn () => (new CreateReportRunHandler($coordinator))->handle(
                $context,
                new CreateReportRunData('report', new ReportFilterSet([]), [], new DateTimeImmutable('2026-07-29T07:00:00Z'), 'ru-RU', null),
                new IdempotencyKey('denied-create'),
            ),
            'retry' => static fn () => (new RetryReportRunHandler($coordinator))->handle($context, $store->run->id, new IdempotencyKey('denied-retry')),
            'cancel' => static fn () => (new CancelReportRunHandler($coordinator))->handle($context, $store->run->id),
        ];
        foreach ($attempts as $name => $attempt) {
            try {
                $attempt();
                self::fail("Denied {$name} was accepted.");
            } catch (ReportContractException $exception) {
                self::assertSame(\App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode, $name);
            }
        }
        self::assertSame(0, $store->createCalls);
        self::assertSame(0, $store->cancelCalls);

        [$viewCoordinator, $viewStore, , $viewContext] = $this->fixture(null, []);
        try {
            (new GetReportRunHandler($viewCoordinator))->handle($viewContext, $viewStore->run->id);
            self::fail('Denied view was accepted.');
        } catch (ReportContractException $exception) {
            self::assertSame(\App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_SCOPE_FORBIDDEN, $exception->errorCode);
            self::assertSame(1, $viewStore->getCalls);
            self::assertSame(1, $viewStore->queryCalls);
        }
    }

    private function fixture(?ReportOutputClassification $output = null, ?array $permissions = null): array
    {
        $definition = (new ReportDefinitionBuilder)
            ->permissionPolicy(new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []))
            ->outputClassification($output ?? new ReportOutputClassification(ReportDataClassification::STANDARD, [], [], false, false, false))
            ->payload();
        $published = new PublishedReportDefinition($definition);
        $context = (new ReportExecutionContextBuilder)->actor(new ReportActor(1, 'active', $permissions ?? ['reports.view', 'reports.run']))->build();
        $query = new ReportQuery($definition, $context->scope, new ReportFilterSet([]), [], new DateTimeImmutable('2026-07-29T07:00:00Z'), 'ru-RU');
        $store = new RecordingRunStore($query, $this->makeRun(ReportRunStatus::QUEUED, $query));
        $savedViews = new RecordingSavedViewResolver;
        $actorLoader = new RecordingActorLoader(new ReportActor(1, 'active', $permissions ?? ['reports.view', 'reports.run']));
        $access = new ReportAccessService(
            $actorLoader,
            new PermissiveSourceResolver,
            new ReportDefinitionVisibilityResolver(
                new ReportDefinitionModuleAuthorizer(new DeterministicReportModuleEntitlement),
            ),
        );
        $clock = new FixedClock(new DateTimeImmutable('2026-07-29T09:30:00.123456Z'));
        $coordinator = new ReportRunCoordinator(new SingleDefinitionRegistry($published), $savedViews, $access, $store, $clock);

        return [$coordinator, $store, $savedViews, $context, $actorLoader];
    }

    private function makeRun(ReportRunStatus $status, ?ReportQuery $query = null): ReportRun
    {
        $instant = new DateTimeImmutable('2026-07-29T07:00:00Z');

        return new ReportRun(
            '01J00000000000000000000000',
            'report',
            $status,
            $query?->definition->definitionHash ?? new Sha256Hash(str_repeat('a', 64)),
            '1',
            '1',
            '1',
            '1',
            $query?->queryHash ?? new Sha256Hash(str_repeat('b', 64)),
            null,
            0,
            null,
            null,
            [],
            null,
            null,
            null,
            $instant,
            $instant,
            null,
            $instant->modify('+1 day'),
            null,
            'reused',
            $status === ReportRunStatus::QUEUED ? 1000 : null,
        );
    }

    private function assertConstructor(string $class, array $parameters): void
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        self::assertNotNull($constructor);
        $this->assertParameters($constructor, $parameters);
    }

    private function assertMethod(string $class, string $method, array $parameters, string $returnType): void
    {
        $reflection = new \ReflectionMethod($class, $method);
        self::assertTrue($reflection->isPublic());
        self::assertFalse($reflection->isStatic());
        self::assertSame($returnType, (string) $reflection->getReturnType());
        $this->assertParameters($reflection, $parameters);
    }

    private function assertParameters(\ReflectionFunctionAbstract $function, array $expected): void
    {
        $actual = [];
        foreach ($function->getParameters() as $parameter) {
            $actual[] = [
                $parameter->getName(),
                (string) $parameter->getType(),
                $parameter->isOptional(),
                $parameter->isDefaultValueAvailable(),
                $parameter->isPassedByReference(),
                $parameter->isVariadic(),
            ];
        }
        $expectedExpanded = array_map(
            static fn (array $parameter): array => [$parameter[0], $parameter[1], false, false, false, false],
            $expected,
        );
        self::assertSame($expectedExpanded, $actual);
    }

    private function assertMethodSurface(string $interface, array $methods): void
    {
        $reflection = new \ReflectionClass($interface);
        $actual = array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $reflection->getMethods(\ReflectionMethod::IS_PUBLIC));
        self::assertSame($methods, $actual);
    }

    private function fileSource(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 4).DIRECTORY_SEPARATOR.$relativePath);
        self::assertIsString($source);

        return $source;
    }

    private function parseSource(string $source): array
    {
        $statements = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        self::assertIsArray($statements);
        $traverser = new NodeTraverser(new NameResolver);

        return $traverser->traverse($statements);
    }

    private function forbiddenDependencyNames(string $source): array
    {
        $finder = new NodeFinder;
        $forbiddenExact = [
            'App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord',
            'App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportMaterializationDispatcher',
            'App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExportDispatcher',
            'Illuminate\Support\Facades\DB',
            'Illuminate\Database\Eloquent\Model',
            'Illuminate\Database\Eloquent\Builder',
            'Illuminate\Database\Query\Builder',
        ];
        $violations = [];
        foreach ($finder->findInstanceOf($this->parseSource($source), Name::class) as $name) {
            $resolved = $name->getAttribute('resolvedName');
            $fqcn = $resolved instanceof Name ? $resolved->toString() : $name->toString();
            if (in_array($fqcn, $forbiddenExact, true)
                || str_ends_with($fqcn, 'Dispatcher')
                || str_ends_with($fqcn, 'Record')) {
                $violations[] = $fqcn;
            }
        }

        return array_values(array_unique($violations));
    }

    private function storeFlowViolations(
        string $source,
        string $methodName,
        string $lookupName,
        string $consumerName,
        int $statementCount,
    ): array {
        $finder = new NodeFinder;
        $ast = $this->parseSource($source);
        $method = $finder->findFirst(
            $ast,
            static fn (Node $node): bool => $node instanceof ClassMethod && $node->name->toString() === $methodName,
        );
        if (! $method instanceof ClassMethod || $method->stmts === null) {
            return ['method_missing'];
        }

        $violations = [];
        if (count($method->stmts) !== $statementCount) {
            $violations[] = 'statement_count';
        }
        $first = $method->stmts[0] ?? null;
        $assignment = $first instanceof Expression ? $first->expr : null;
        if (! $assignment instanceof Assign
            || ! $assignment->var instanceof Variable
            || $assignment->var->name !== 'record'
            || ! $assignment->expr instanceof MethodCall
            || ! $assignment->expr->var instanceof Variable
            || $assignment->expr->var->name !== 'this'
            || ! $assignment->expr->name instanceof Node\Identifier
            || $assignment->expr->name->toString() !== $lookupName
            || ! $this->hasExactVariableArgs($assignment->expr->args, ['context', 'runId'])) {
            $violations[] = 'lookup_assignment';
        }

        $lookupCalls = $finder->find(
            $method->stmts,
            static fn (Node $node): bool => $node instanceof MethodCall
                && $node->name instanceof Node\Identifier
                && in_array($node->name->toString(), ['find', 'findIncludingExpired'], true),
        );
        if (count($lookupCalls) !== 1) {
            $violations[] = 'lookup_call_count';
        }
        if ($methodName === 'exportSource' && ! $this->hasExactExpiredExportGuard($method->stmts[1] ?? null)) {
            $violations[] = 'expired_export_guard';
        }

        $last = $method->stmts[array_key_last($method->stmts)] ?? null;
        $consumer = $last instanceof Return_ ? $last->expr : null;
        if (! $consumer instanceof MethodCall
            || ! $consumer->var instanceof PropertyFetch
            || ! $consumer->var->var instanceof Variable
            || $consumer->var->var->name !== 'this'
            || ! $consumer->var->name instanceof Node\Identifier
            || $consumer->var->name->toString() !== 'hydrator'
            || ! $consumer->name instanceof Node\Identifier
            || $consumer->name->toString() !== $consumerName
            || ! isset($consumer->args[0])
            || ! $consumer->args[0] instanceof Arg
            || ! $consumer->args[0]->value instanceof Variable
            || $consumer->args[0]->value->name !== 'record') {
            $violations[] = 'record_consumer';
        }

        return $violations;
    }

    private function hasExactExpiredExportGuard(?Node $statement): bool
    {
        if (! $statement instanceof If_
            || ! $statement->cond instanceof MethodCall
            || ! $statement->cond->var instanceof Variable
            || $statement->cond->var->name !== 'this'
            || ! $statement->cond->name instanceof Node\Identifier
            || $statement->cond->name->toString() !== 'isExpiredForExport'
            || count($statement->cond->args) !== 2
            || ! $statement->cond->args[0]->value instanceof PropertyFetch
            || ! $statement->cond->args[0]->value->var instanceof Variable
            || $statement->cond->args[0]->value->var->name !== 'record'
            || ! $statement->cond->args[0]->value->name instanceof Node\Identifier
            || $statement->cond->args[0]->value->name->toString() !== 'expires_at'
            || ! $statement->cond->args[1]->value instanceof MethodCall
            || ! $statement->cond->args[1]->value->var instanceof PropertyFetch
            || ! $statement->cond->args[1]->value->var->var instanceof Variable
            || $statement->cond->args[1]->value->var->var->name !== 'this'
            || ! $statement->cond->args[1]->value->var->name instanceof Node\Identifier
            || $statement->cond->args[1]->value->var->name->toString() !== 'clock'
            || ! $statement->cond->args[1]->value->name instanceof Node\Identifier
            || $statement->cond->args[1]->value->name->toString() !== 'now'
            || count($statement->stmts) !== 1
            || ! $statement->stmts[0] instanceof Expression
            || ! $statement->stmts[0]->expr instanceof Throw_) {
            return false;
        }
        $exception = $statement->stmts[0]->expr->expr;
        if (! $exception instanceof StaticCall
            || ! $exception->class instanceof Name
            || $exception->class->toString() !== ReportContractException::class
            || ! $exception->name instanceof Node\Identifier
            || $exception->name->toString() !== 'fromCode'
            || count($exception->args) !== 1
            || ! $exception->args[0]->value instanceof ClassConstFetch
            || ! $exception->args[0]->value->class instanceof Name
            || $exception->args[0]->value->class->toString() !== \App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::class
            || ! $exception->args[0]->value->name instanceof Node\Identifier
            || $exception->args[0]->value->name->toString() !== 'REPORT_SNAPSHOT_EXPIRED') {
            return false;
        }

        return true;
    }

    private function hasExactVariableArgs(array $args, array $names): bool
    {
        if (count($args) !== count($names)) {
            return false;
        }
        foreach ($args as $index => $arg) {
            if (! $arg instanceof Arg || ! $arg->value instanceof Variable || $arg->value->name !== $names[$index]) {
                return false;
            }
        }

        return true;
    }
}

final class SingleDefinitionRegistry implements ReportDefinitionRegistry
{
    public int $publishedCalls = 0;

    public function __construct(private readonly PublishedReportDefinition $definition) {}

    public function published(string $code): PublishedReportDefinition
    {
        $this->publishedCalls++;

        return $this->definition;
    }

    public function publishedCodes(): array
    {
        return [$this->definition->code];
    }

    public function manifestSha256(): Sha256Hash
    {
        return $this->definition->definitionHash;
    }
}

final class RecordingSavedViewResolver implements ReportSavedViewReferenceResolver
{
    public readonly ReportSavedViewRef $reference;

    public ?ReportSavedViewRef $asserted = null;

    public bool $rejectResolve = false;

    public bool $rejectCurrent = false;

    public function __construct()
    {
        $this->reference = new ReportSavedViewRef('01J00000000000000000000001', 7, new Sha256Hash(str_repeat('c', 64)));
    }

    public function resolve(ReportExecutionContext $context, string $savedViewId): ReportSavedViewRef
    {
        if ($this->rejectResolve) {
            throw new InvalidArgumentException('saved_view_invalid');
        }

        return $this->reference;
    }

    public function assertCurrent(ReportExecutionContext $context, ReportSavedViewRef $reference): void
    {
        if ($this->rejectCurrent) {
            throw new InvalidArgumentException('saved_view_stale');
        }
        $this->asserted = $reference;
    }
}

final class RecordingActorLoader implements ReportActorLoader
{
    public int $loads = 0;

    public function __construct(private readonly ReportActor $actor) {}

    public function loadActive(int $actorId): ReportActor
    {
        $this->loads++;

        return $this->actor;
    }
}

final class PermissiveSourceResolver implements ReportSourceAccessResolver
{
    public function assertAccessible(ReportExecutionContext $context, \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition, ReportSourceRef $source): void {}
}

final class FixedClock implements ReportExecutionClock
{
    public function __construct(private readonly DateTimeImmutable $instant) {}

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}

final class RecordingRunStore implements ReportRunStore
{
    public ?ReportQuery $lastQuery = null;

    public ?ReportSavedViewRef $lastSavedView = null;

    public ?IdempotencyKey $lastKey = null;

    public ?DateTimeImmutable $cancelledAt = null;

    public ?ReportRunRetrySource $retrySource = null;

    public int $createCalls = 0;

    public int $cancelCalls = 0;

    public int $getCalls = 0;

    public int $queryCalls = 0;

    public bool $cancelRace = false;

    private array $idempotency = [];

    public function __construct(public readonly ReportQuery $query, public readonly ReportRun $run) {}

    public function createOrReuse(ReportExecutionContext $context, ReportQuery $query, ?ReportSavedViewRef $savedView, IdempotencyKey $idempotencyKey): ReportRun
    {
        $this->createCalls++;
        $this->lastQuery = $query;
        $this->lastSavedView = $savedView;
        $this->lastKey = $idempotencyKey;
        $fingerprint = hash('sha256', \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode([
            'query' => json_decode($query->canonicalJson, true, 512, JSON_THROW_ON_ERROR),
            'saved_view' => $savedView === null ? null : [
                'id' => $savedView->id,
                'revision' => $savedView->revision,
                'hash' => $savedView->hash->value,
            ],
        ]));
        $existing = $this->idempotency[$idempotencyKey->hash] ?? null;
        if (is_string($existing) && ! hash_equals($existing, $fingerprint)) {
            throw ReportContractException::fromCode(\App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT);
        }
        $this->idempotency[$idempotencyKey->hash] = $fingerprint;

        return $this->run;
    }

    public function get(ReportExecutionContext $context, string $runId): ReportRun
    {
        $this->getCalls++;

        return $this->run;
    }

    public function queryForRun(ReportExecutionContext $context, string $runId): ReportQuery
    {
        $this->queryCalls++;

        return $this->query;
    }

    public function retrySource(ReportExecutionContext $context, string $runId): ReportRunRetrySource
    {
        return $this->retrySource ?? throw new \LogicException('retry source missing');
    }

    public function exportSource(ReportExecutionContext $context, string $runId): ReportRunExportSource
    {
        throw new \LogicException('unused');
    }

    public function claimMaterialization(ReportExecutionContext $context, string $runId, string $leaseToken, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportRun
    {
        return $this->run;
    }

    public function persistProgress(ReportExecutionContext $context, string $runId, string $leaseToken, ReportProgress $progress, DateTimeImmutable $leaseExpiresAt, DateTimeImmutable $occurredAt): ReportRun
    {
        return $this->run;
    }

    public function sealReady(ReportExecutionContext $context, string $runId, string $leaseToken, ReportSnapshotRef $snapshot, ReportResult $result, Sha256Hash $sourceHash, DateTimeImmutable $occurredAt): ReportRun
    {
        return $this->run;
    }

    public function fail(ReportExecutionContext $context, string $runId, ?string $leaseToken, \App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode $errorCode, DateTimeImmutable $occurredAt): ReportRun
    {
        return $this->run;
    }

    public function cancel(ReportExecutionContext $context, string $runId, DateTimeImmutable $occurredAt): ReportRun
    {
        $this->cancelCalls++;
        $this->cancelledAt = $occurredAt;
        if ($this->cancelRace) {
            throw ReportContractException::fromCode(\App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $this->run;
    }
}
