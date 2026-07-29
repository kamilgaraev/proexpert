<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Dispatch;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditDispatcher;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportDispatchIntentStore;
use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntent;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntentLease;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntent;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchLease;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchPublishSummary;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchTopic;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\OutboxReportTransitionAudit;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class ReportAuditIntentContractTest extends TestCase
{
    public function test_dispatch_enums_are_closed(): void
    {
        self::assertSame(['RUN' => 'run', 'EXPORT' => 'export'], $this->enumMap(ReportDispatchAggregate::class));
        self::assertSame(
            ['MATERIALIZE_RUN' => 'materialize_run', 'GENERATE_EXPORT' => 'generate_export'],
            $this->enumMap(ReportDispatchTopic::class),
        );
    }

    #[DataProvider('constructorContracts')]
    public function test_dispatch_values_have_exact_readonly_constructor_contract(string $class, array $parameters): void
    {
        $reflection = new ReflectionClass($class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertSame($parameters, $this->parameters($reflection->getConstructor()));
        self::assertSame(
            array_column($parameters, 0),
            array_map(static fn ($property): string => $property->getName(), $reflection->getProperties()),
        );
    }

    public static function constructorContracts(): iterable
    {
        yield ReportDispatchIntent::class => [ReportDispatchIntent::class, [
            ['id', 'string', false],
            ['eventKey', 'string', false],
            ['organizationId', 'int', false],
            ['aggregate', ReportDispatchAggregate::class, false],
            ['aggregateId', 'string', false],
            ['topic', ReportDispatchTopic::class, false],
            ['attemptCount', 'int', false],
            ['occurredAt', \DateTimeImmutable::class, false],
            ['availableAt', \DateTimeImmutable::class, false],
        ]];
        yield ReportDispatchLease::class => [ReportDispatchLease::class, [
            ['intent', ReportDispatchIntent::class, false],
            ['leaseToken', 'string', false],
            ['leaseExpiresAt', \DateTimeImmutable::class, false],
        ]];
        yield ReportDispatchPublishSummary::class => [ReportDispatchPublishSummary::class, [
            ['scanned', 'int', false],
            ['claimed', 'int', false],
            ['published', 'int', false],
            ['retryScheduled', 'int', false],
            ['deadLettered', 'int', false],
            ['skipped', 'int', false],
        ]];
        yield ReportAuditIntent::class => [ReportAuditIntent::class, [
            ['id', 'string', false],
            ['eventKey', 'string', false],
            ['eventType', 'string', false],
            ['organizationId', 'int', false],
            ['actorId', 'int', false],
            ['subject', 'array', false],
            ['attemptCount', 'int', false],
            ['occurredAt', \DateTimeImmutable::class, false],
            ['availableAt', \DateTimeImmutable::class, false],
        ]];
        yield ReportAuditIntentLease::class => [ReportAuditIntentLease::class, [
            ['intentId', 'string', false],
            ['leaseToken', 'string', false],
            ['leaseExpiresAt', \DateTimeImmutable::class, false],
            ['attemptCount', 'int', false],
        ]];
    }

    #[DataProvider('interfaceContracts')]
    public function test_intent_ports_have_exact_public_surface(string $interface, array $methods): void
    {
        $reflection = new ReflectionClass($interface);

        self::assertTrue($reflection->isInterface());
        self::assertSame(array_keys($methods), array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        ));
        foreach ($methods as $method => $contract) {
            $reflectionMethod = $reflection->getMethod($method);
            self::assertSame($contract['parameters'], $this->parameters($reflectionMethod));
            self::assertSame($contract['return'], (string) $reflectionMethod->getReturnType());
        }
    }

    public static function interfaceContracts(): iterable
    {
        yield ReportDispatchIntentStore::class => [ReportDispatchIntentStore::class, [
            'addRunIntent' => [
                'parameters' => [['runId', 'string', false], ['organizationId', 'int', false], ['eventKey', 'string', false], ['occurredAt', \DateTimeImmutable::class, false]],
                'return' => 'void',
            ],
            'addExportIntent' => [
                'parameters' => [['exportId', 'string', false], ['organizationId', 'int', false], ['eventKey', 'string', false], ['occurredAt', \DateTimeImmutable::class, false]],
                'return' => 'void',
            ],
            'claimDue' => [
                'parameters' => [['limit', 'int', false], ['now', \DateTimeImmutable::class, false], ['leasedUntil', \DateTimeImmutable::class, false], ['leaseToken', 'string', false]],
                'return' => 'array',
            ],
            'markPublished' => [
                'parameters' => [['intentId', 'string', false], ['leaseToken', 'string', false], ['occurredAt', \DateTimeImmutable::class, false]],
                'return' => 'void',
            ],
            'markPublicationFailed' => [
                'parameters' => [['intentId', 'string', false], ['leaseToken', 'string', false], ['errorCode', 'App\\BusinessModules\\Core\\Reporting\\Application\\Errors\\ReportErrorCode', false], ['occurredAt', \DateTimeImmutable::class, false], ['nextAttemptAt', \DateTimeImmutable::class, false]],
                'return' => 'void',
            ],
            'reclaimExpiredLeases' => [
                'parameters' => [['limit', 'int', false], ['occurredAt', \DateTimeImmutable::class, false]],
                'return' => 'int',
            ],
        ]];
        yield ReportAuditIntentStore::class => [ReportAuditIntentStore::class, [
            'add' => [
                'parameters' => [['eventKey', 'string', false], ['eventType', 'string', false], ['context', 'App\\BusinessModules\\Core\\Reporting\\Domain\\DTO\\ReportExecutionContext', false], ['subject', 'array', false], ['occurredAt', \DateTimeImmutable::class, false]],
                'return' => 'void',
            ],
            'dueIds' => [
                'parameters' => [['limit', 'int', false], ['now', \DateTimeImmutable::class, false]],
                'return' => 'array',
            ],
            'claim' => [
                'parameters' => [['intentId', 'string', false], ['leaseToken', 'string', false], ['now', \DateTimeImmutable::class, false], ['leasedUntil', \DateTimeImmutable::class, false]],
                'return' => '?'.ReportAuditIntentLease::class,
            ],
            'loadLeased' => [
                'parameters' => [['intentId', 'string', false], ['leaseToken', 'string', false]],
                'return' => ReportAuditIntent::class,
            ],
            'acknowledge' => [
                'parameters' => [['intentId', 'string', false], ['leaseToken', 'string', false], ['deliveredAt', \DateTimeImmutable::class, false]],
                'return' => 'void',
            ],
            'failDelivery' => [
                'parameters' => [['intentId', 'string', false], ['leaseToken', 'string', false], ['errorCode', 'App\\BusinessModules\\Core\\Reporting\\Application\\Errors\\ReportErrorCode', false], ['occurredAt', \DateTimeImmutable::class, false], ['nextAttemptAt', \DateTimeImmutable::class, false]],
                'return' => 'void',
            ],
            'reclaimExpired' => [
                'parameters' => [['limit', 'int', false], ['occurredAt', \DateTimeImmutable::class, false]],
                'return' => 'int',
            ],
        ]];
        yield ReportAuditDispatcher::class => [ReportAuditDispatcher::class, [
            'dispatch' => [
                'parameters' => [['intentId', 'string', false]],
                'return' => 'void',
            ],
        ]];
    }

    #[DataProvider('validSubjects')]
    public function test_accepts_every_closed_audit_subject(string $eventType, array $subject): void
    {
        $store = new RecordingAuditIntentStore();
        $audit = new OutboxReportTransitionAudit($store);
        $context = (new ReportExecutionContextBuilder())->build();
        $occurredAt = new DateTimeImmutable('2026-07-28T12:00:00.123456Z');

        $audit->append("event:{$eventType}", $eventType, $context, $subject, $occurredAt);

        self::assertInstanceOf(ReportTransitionAudit::class, $audit);
        self::assertCount(1, $store->added);
        self::assertSame("event:{$eventType}", $store->added[0]['event_key']);
        self::assertSame($eventType, $store->added[0]['event_type']);
        self::assertSame($subject, $store->added[0]['subject']);
        self::assertSame($context, $store->added[0]['context']);
        self::assertSame($occurredAt, $store->added[0]['occurred_at']);
    }

    public static function validSubjects(): iterable
    {
        $run = [
            'run_id' => '01J00000000000000000000001',
            'report_code' => 'cost_control',
            'status' => 'queued',
            'definition_hash' => str_repeat('a', 64),
            'query_hash' => str_repeat('b', 64),
            'contract_version' => '1.0.0',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
            'saved_view' => null,
        ];
        yield 'run queued' => ['report.run.queued', $run];
        yield 'run materializing' => ['report.run.materializing', array_intersect_key(
            [...$run, 'status' => 'materializing'],
            array_flip(['run_id', 'report_code', 'status', 'definition_hash', 'query_hash']),
        )];
        yield 'run ready' => ['report.run.ready', [
            'run_id' => $run['run_id'],
            'report_code' => $run['report_code'],
            'status' => 'ready',
            'definition_hash' => $run['definition_hash'],
            'query_hash' => $run['query_hash'],
            'source_hash' => str_repeat('c', 64),
            'result_hash' => str_repeat('d', 64),
            'snapshot' => [
                'kind' => 'materialized',
                'id' => 'snapshot_one',
                'classification' => 'operational',
                'seal_digest' => null,
            ],
            'data_classification' => 'standard',
            'row_count' => 10,
            'contract_version' => '1.0.0',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
        ]];
        yield 'run failed' => ['report.run.failed', [
            'run_id' => $run['run_id'],
            'report_code' => $run['report_code'],
            'status' => 'failed',
            'definition_hash' => $run['definition_hash'],
            'query_hash' => $run['query_hash'],
            'error_code' => 'REPORT_DEPENDENCY_FAILED',
        ]];
        yield 'run cancelled' => ['report.run.cancelled', [
            'run_id' => $run['run_id'],
            'report_code' => $run['report_code'],
            'status' => 'cancelled',
            'definition_hash' => $run['definition_hash'],
            'query_hash' => $run['query_hash'],
        ]];
        yield 'run expired' => ['report.run.expired', [
            'run_id' => $run['run_id'],
            'report_code' => $run['report_code'],
            'status' => 'expired',
            'definition_hash' => $run['definition_hash'],
            'query_hash' => $run['query_hash'],
            'source_hash' => str_repeat('c', 64),
            'result_hash' => str_repeat('d', 64),
            'snapshot_id' => 'snapshot_one',
            'expired_at' => '2026-07-28T12:00:00.000000Z',
        ]];

        $export = [
            'export_id' => '01J00000000000000000000002',
            'run_id' => $run['run_id'],
            'report_code' => $run['report_code'],
            'status' => 'queued',
            'definition_hash' => $run['definition_hash'],
            'query_hash' => $run['query_hash'],
            'source_hash' => str_repeat('c', 64),
            'result_hash' => str_repeat('d', 64),
            'snapshot_id' => 'snapshot_one',
            'snapshot_classification' => 'operational',
            'data_classification' => 'standard',
            'format' => 'csv',
            'columns' => ['amount'],
            'locale' => 'ru',
            'timezone' => 'UTC',
            'renderer_version' => '1',
        ];
        yield 'export queued' => ['report.export.queued', $export];
        foreach (['running', 'uploading'] as $status) {
            yield "export {$status}" => ["report.export.{$status}", [
                'export_id' => $export['export_id'],
                'run_id' => $export['run_id'],
                'report_code' => $export['report_code'],
                'status' => $status,
                'format' => 'csv',
            ]];
        }
        yield 'export ready' => ['report.export.ready', [
            'export_id' => $export['export_id'],
            'run_id' => $export['run_id'],
            'report_code' => $export['report_code'],
            'status' => 'ready',
            'definition_hash' => $export['definition_hash'],
            'query_hash' => $export['query_hash'],
            'source_hash' => $export['source_hash'],
            'result_hash' => $export['result_hash'],
            'snapshot_id' => $export['snapshot_id'],
            'format' => 'csv',
            'renderer_version' => '1',
            'row_count' => 10,
            'artifact' => [
                'version_id' => 'version_one',
                'etag' => '"7f83b1657ff1fc53b92dc18148a1d65dfa13514a2096"-3',
                'checksum' => str_repeat('f', 64),
                'size' => 100,
                'mime' => 'text/csv',
            ],
        ]];
        yield 'export failed' => ['report.export.failed', [
            'export_id' => $export['export_id'],
            'run_id' => $export['run_id'],
            'report_code' => $export['report_code'],
            'status' => 'failed',
            'format' => 'csv',
            'error_code' => 'REPORT_DEPENDENCY_FAILED',
        ]];
        yield 'export cancelled' => ['report.export.cancelled', [
            'export_id' => $export['export_id'],
            'run_id' => $export['run_id'],
            'report_code' => $export['report_code'],
            'status' => 'cancelled',
            'format' => 'csv',
        ]];
        $expired = [
            'export_id' => $export['export_id'],
            'run_id' => $export['run_id'],
            'report_code' => $export['report_code'],
            'status' => 'expired',
            'format' => 'csv',
            'version_id' => 'version_one',
            'occurred_at' => '2026-07-28T12:00:00.000000Z',
        ];
        yield 'export expired' => ['report.export.expired', $expired];
        yield 'export artifact deleted' => ['report.export.artifact_deleted', $expired];
    }

    public function test_rejects_unknown_missing_extra_and_recursively_forbidden_subject_members(): void
    {
        $valid = iterator_to_array(self::validSubjects())['run queued'][1];
        $context = (new ReportExecutionContextBuilder())->build();
        $occurredAt = new DateTimeImmutable('2026-07-28T12:00:00Z');

        $mutations = [
            ['report.unknown', $valid],
            ['report.run.queued', array_diff_key($valid, ['query_hash' => true])],
            ['report.run.queued', [...$valid, 'extra' => true]],
            ['report.run.queued', [...$valid, 'saved_view' => ['id' => '01J00000000000000000000003', 'revision' => 1, 'hash' => str_repeat('a', 64), 'token' => 'secret']]],
        ];
        foreach ($mutations as [$eventType, $subject]) {
            $store = new RecordingAuditIntentStore();
            try {
                (new OutboxReportTransitionAudit($store))->append('event:key', $eventType, $context, $subject, $occurredAt);
                self::fail("Invalid subject accepted for {$eventType}.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $store->added);
            }
        }
    }

    #[DataProvider('validSubjects')]
    public function test_every_closed_subject_rejects_missing_extra_wrong_status_wrong_type_and_forbidden_depth(
        string $eventType,
        array $subject,
    ): void {
        $mutations = [
            [...$subject, 'extra' => true],
            [...$subject, 'status' => 'wrong'],
            [...$subject, 'transport' => ['token' => 'secret']],
        ];
        foreach (array_keys($subject) as $key) {
            $missing = $subject;
            unset($missing[$key]);
            $mutations[] = $missing;
            $mutations[] = [...$subject, $key => new \stdClass()];
        }
        foreach ($mutations as $index => $mutation) {
            $store = new RecordingAuditIntentStore();
            try {
                (new OutboxReportTransitionAudit($store))->append(
                    "event:{$eventType}:{$index}",
                    $eventType,
                    (new ReportExecutionContextBuilder())->build(),
                    $mutation,
                    new DateTimeImmutable('2026-07-28T12:00:00Z'),
                );
                self::fail("Mutation {$index} accepted for {$eventType}.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $store->added);
            }
        }
    }

    public function test_rejects_classification_columns_time_and_nested_forbidden_mutations(): void
    {
        $subjects = iterator_to_array(self::validSubjects());
        $queued = $subjects['export queued'][1];
        $expired = $subjects['export expired'][1];
        $runQueued = $subjects['run queued'][1];
        $context = (new ReportExecutionContextBuilder())->build();
        $occurredAt = new DateTimeImmutable('2026-07-28T12:00:00Z');
        $mutations = [
            ['report.export.queued', [...$queued, 'snapshot_classification' => 'trusted']],
            ['report.export.queued', [...$queued, 'data_classification' => 'secret']],
            ['report.export.queued', [...$queued, 'columns' => ['z', 'a']]],
            ['report.export.queued', [...$queued, 'columns' => ['amount', 'amount']]],
            ['report.export.expired', [...$expired, 'occurred_at' => 'yesterday']],
            ['report.run.queued', [...$runQueued, 'saved_view' => ['id' => '01J00000000000000000000003', 'revision' => 1, 'hash' => str_repeat('a', 64), 'credentials' => ['password' => 'x']]]],
        ];

        foreach ($mutations as [$eventType, $subject]) {
            $store = new RecordingAuditIntentStore();
            try {
                (new OutboxReportTransitionAudit($store))->append('event:key', $eventType, $context, $subject, $occurredAt);
                self::fail("Semantic mutation accepted for {$eventType}.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $store->added);
            }
        }
    }

    public function test_export_ready_keeps_etag_opaque_and_validates_checksum_separately(): void
    {
        $ready = iterator_to_array(self::validSubjects())['export ready'][1];
        $context = (new ReportExecutionContextBuilder())->build();
        $occurredAt = new DateTimeImmutable('2026-07-28T12:00:00Z');

        $store = new RecordingAuditIntentStore();
        (new OutboxReportTransitionAudit($store))->append(
            'event:export-ready:opaque-etag',
            'report.export.ready',
            $context,
            $ready,
            $occurredAt,
        );
        self::assertSame($ready['artifact']['etag'], $store->added[0]['subject']['artifact']['etag']);

        foreach ([
            [...$ready, 'artifact' => [...$ready['artifact'], 'etag' => '']],
            [...$ready, 'artifact' => [...$ready['artifact'], 'etag' => "part\x1Ftwo"]],
            [...$ready, 'artifact' => [...$ready['artifact'], 'etag' => str_repeat('x', 256)]],
            [...$ready, 'artifact' => [...$ready['artifact'], 'checksum' => strtoupper(str_repeat('f', 64))]],
        ] as $mutation) {
            $invalidStore = new RecordingAuditIntentStore();
            try {
                (new OutboxReportTransitionAudit($invalidStore))->append(
                    'event:export-ready:invalid-artifact',
                    'report.export.ready',
                    $context,
                    $mutation,
                    $occurredAt,
                );
                self::fail('Invalid artifact identity accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $invalidStore->added);
            }
        }
    }

    public function test_created_services_keep_locked_constructor_and_public_method_shapes(): void
    {
        $contracts = [
            \App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchBackoffPolicy::class => [[], ['nextAttemptAt']],
            \App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntentPublisher::class => [['store', 'transport', 'backoff', 'leaseSeconds'], ['__construct', 'publishBatch']],
            \App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchIntentReconciler::class => [['store', 'publisher'], ['__construct', 'reconcile']],
            \App\BusinessModules\Core\Reporting\Infrastructure\Dispatch\LaravelReportDispatchIntentPublisher::class => [['runs', 'exports'], ['__construct', 'publish']],
            \App\BusinessModules\Core\Reporting\Infrastructure\Audit\OutboxReportTransitionAudit::class => [['store'], ['__construct', 'append']],
            \App\BusinessModules\Core\Reporting\Infrastructure\Console\PublishReportDispatchIntentsCommand::class => [['publisher', 'batchSize'], ['__construct', 'handle']],
            \App\BusinessModules\Core\Reporting\Infrastructure\Console\ReconcileReportDispatchIntentsCommand::class => [['reconciler', 'batchSize'], ['__construct', 'handle']],
        ];

        foreach ($contracts as $class => [$constructorParameters, $publicMethods]) {
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();
            self::assertSame(
                $constructorParameters,
                $constructor === null ? [] : array_map(static fn ($parameter): string => $parameter->getName(), $constructor->getParameters()),
                $class,
            );
            $actual = array_map(
                static fn (ReflectionMethod $method): string => $method->getName(),
                array_filter(
                    $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                    static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class,
                ),
            );
            sort($actual);
            sort($publicMethods);
            self::assertSame($publicMethods, $actual, $class);
        }
    }

    private function enumMap(string $enum): array
    {
        $values = [];
        foreach ($enum::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        return $values;
    }

    private function parameters(?ReflectionMethod $method): array
    {
        self::assertNotNull($method);

        return array_map(
            static function (ReflectionParameter $parameter): array {
                $type = $parameter->getType();
                self::assertInstanceOf(ReflectionNamedType::class, $type);

                return [$parameter->getName(), $type->getName(), $type->allowsNull()];
            },
            $method->getParameters(),
        );
    }
}

final class RecordingAuditIntentStore implements ReportAuditIntentStore
{
    public array $added = [];

    public function add(string $eventKey, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void
    {
        $this->added[] = [
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'context' => $context,
            'subject' => $subject,
            'occurred_at' => $occurredAt,
        ];
    }

    public function dueIds(int $limit, DateTimeImmutable $now): array
    {
        return [];
    }

    public function claim(string $intentId, string $leaseToken, DateTimeImmutable $now, DateTimeImmutable $leasedUntil): ?ReportAuditIntentLease
    {
        return null;
    }

    public function loadLeased(string $intentId, string $leaseToken): ReportAuditIntent
    {
        throw new \LogicException('not used');
    }

    public function acknowledge(string $intentId, string $leaseToken, DateTimeImmutable $deliveredAt): void {}

    public function failDelivery(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void {}

    public function reclaimExpired(int $limit, DateTimeImmutable $occurredAt): int
    {
        return 0;
    }
}
