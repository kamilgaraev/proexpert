<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Audit;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportAuditIntentStore;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntent;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntentLease;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\AppendReportAuditEventJob;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\CoreReportAuditIntentConsumer;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\LaravelReportAuditDispatcher;
use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AppendReportAuditEventJobTest extends TestCase
{
    private const INTENT_ID = '01J00000000000000000000001';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Container::setInstance(new Container);
        parent::tearDown();
    }

    public function test_job_serializes_only_the_intent_id_and_targets_the_dedicated_queue(): void
    {
        $job = new AppendReportAuditEventJob(self::INTENT_ID);

        self::assertSame(self::INTENT_ID, $job->intentId);
        self::assertSame('redis_reports', $job->connection);
        self::assertSame('reports-audit', $job->queue);
        $serializedPayload = get_object_vars($job);

        self::assertArrayHasKey('intentId', $serializedPayload);
        self::assertArrayNotHasKey('intent', $serializedPayload);
        self::assertArrayNotHasKey('subject', $serializedPayload);
        self::assertArrayNotHasKey('context', $serializedPayload);
        self::assertTrue(method_exists(LaravelReportAuditDispatcher::class, 'dispatch'));
    }

    public function test_duplicate_or_no_longer_due_claim_is_a_noop(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30T10:00:00.123456Z'));
        $store = new JobAuditIntentStore(null);
        $consumer = (new \ReflectionClass(CoreReportAuditIntentConsumer::class))->newInstanceWithoutConstructor();
        $job = $this->jobWithEnvelope('00000000-0000-4000-8000-000000000001');

        $job->handle($consumer, $store);

        self::assertSame(1, $store->claimCalls);
        self::assertSame(0, $store->loadCalls);
        self::assertSame(0, $store->acknowledgeCalls);
    }

    public function test_failed_delivery_uses_the_same_lease_token_and_exact_backoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30T10:00:00.999999Z'));
        $token = '00000000-0000-4000-8000-000000000003';
        $intent = $this->intent(3);
        $store = new JobAuditIntentStore(new ReportAuditIntentLease(
            self::INTENT_ID,
            $token,
            new DateTimeImmutable('2026-07-30T10:01:00Z'),
            3,
        ), $intent);
        $container = new Container;
        $container->instance(ReportAuditIntentStore::class, $store);
        Container::setInstance($container);
        $job = $this->jobWithEnvelope($token);

        $job->failed(new \RuntimeException('Core unavailable'));

        self::assertCount(1, $store->failures);
        self::assertSame($token, $store->failures[0]['lease_token']);
        self::assertSame(ReportErrorCode::REPORT_DEPENDENCY_FAILED, $store->failures[0]['error_code']);
        self::assertSame(
            '2026-07-30T10:01:00.000000Z',
            $store->failures[0]['next_attempt_at']->format('Y-m-d\TH:i:s.u\Z'),
        );
    }

    public function test_handle_records_failure_before_the_queue_exception_escapes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30T10:00:00.999999Z'));
        $token = '00000000-0000-4000-8000-000000000004';
        $store = new JobAuditIntentStore(
            new ReportAuditIntentLease(
                self::INTENT_ID,
                $token,
                new DateTimeImmutable('2026-07-30T10:01:00Z'),
                3,
            ),
            $this->intent(3, 'report.unknown'),
        );
        $consumer = (new \ReflectionClass(CoreReportAuditIntentConsumer::class))->newInstanceWithoutConstructor();
        $job = $this->jobWithEnvelope($token);

        try {
            $job->handle($consumer, $store);
            self::fail('Invalid Core audit event was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('report_core_audit_intent_invalid', $exception->getMessage());
        }

        self::assertSame(1, $store->loadCalls);
        self::assertSame(0, $store->acknowledgeCalls);
        self::assertCount(1, $store->failures);
        self::assertSame($token, $store->failures[0]['lease_token']);
        self::assertSame(
            '2026-07-30T10:01:00.000000Z',
            $store->failures[0]['next_attempt_at']->format('Y-m-d\TH:i:s.u\Z'),
        );
    }

    public function test_constructor_rejects_every_non_ulid_identifier(): void
    {
        foreach (['', 'not-an-ulid', strtolower(self::INTENT_ID)] as $intentId) {
            try {
                new AppendReportAuditEventJob($intentId);
                self::fail('Malformed audit intent ID was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('report_audit_intent_id_invalid', $exception->getMessage());
            }
        }
    }

    private function jobWithEnvelope(string $uuid): AppendReportAuditEventJob
    {
        $envelope = $this->createMock(Job::class);
        $envelope->method('uuid')->willReturn($uuid);
        $job = new AppendReportAuditEventJob(self::INTENT_ID);
        $job->setJob($envelope);

        return $job;
    }

    private function intent(int $attempt, string $eventType = 'report.run.ready'): ReportAuditIntent
    {
        return new ReportAuditIntent(
            self::INTENT_ID,
            'reports:run:01J00000000000000000000002:ready',
            $eventType,
            10,
            20,
            [
                'run_id' => '01J00000000000000000000002',
                'report_code' => 'cost_control',
                'status' => 'ready',
                'definition_hash' => str_repeat('a', 64),
                'query_hash' => str_repeat('b', 64),
                'source_hash' => str_repeat('c', 64),
                'result_hash' => str_repeat('d', 64),
                'snapshot' => [
                    'kind' => 'materialized',
                    'id' => 'snapshot-one',
                    'classification' => 'operational',
                    'seal_digest' => null,
                ],
                'data_classification' => 'standard',
                'row_count' => 1,
                'contract_version' => '1',
                'formula_version' => '1',
                'source_schema_version' => '1',
                'renderer_version' => '1',
            ],
            $attempt,
            new DateTimeImmutable('2026-07-30T09:00:00Z'),
            new DateTimeImmutable('2026-07-30T09:00:00Z'),
        );
    }
}

final class JobAuditIntentStore implements ReportAuditIntentStore
{
    public int $claimCalls = 0;

    public int $loadCalls = 0;

    public int $acknowledgeCalls = 0;

    public array $failures = [];

    public function __construct(
        private readonly ?ReportAuditIntentLease $lease,
        private readonly ?ReportAuditIntent $intent = null,
    ) {}

    public function add(string $eventKey, string $eventType, ReportExecutionContext $context, array $subject, DateTimeImmutable $occurredAt): void {}

    public function dueIds(int $limit, DateTimeImmutable $now): array
    {
        return [];
    }

    public function claim(string $intentId, string $leaseToken, DateTimeImmutable $now, DateTimeImmutable $leasedUntil): ?ReportAuditIntentLease
    {
        $this->claimCalls++;

        return $this->lease;
    }

    public function loadLeased(string $intentId, string $leaseToken): ReportAuditIntent
    {
        $this->loadCalls++;

        return $this->intent ?? throw new \LogicException('missing intent');
    }

    public function acknowledge(string $intentId, string $leaseToken, DateTimeImmutable $deliveredAt): void
    {
        $this->acknowledgeCalls++;
    }

    public function failDelivery(string $intentId, string $leaseToken, ReportErrorCode $errorCode, DateTimeImmutable $occurredAt, DateTimeImmutable $nextAttemptAt): void
    {
        $this->failures[] = [
            'intent_id' => $intentId,
            'lease_token' => $leaseToken,
            'error_code' => $errorCode,
            'occurred_at' => $occurredAt,
            'next_attempt_at' => $nextAttemptAt,
        ];
    }

    public function reclaimExpired(int $limit, DateTimeImmutable $occurredAt): int
    {
        return 0;
    }
}
