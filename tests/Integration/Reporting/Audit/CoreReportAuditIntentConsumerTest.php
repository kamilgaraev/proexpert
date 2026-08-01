<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Audit;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportAuditIntent;
use App\BusinessModules\Core\Reporting\Infrastructure\Audit\CoreReportAuditIntentConsumer;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\Reporting\ReportRuntimeFixture;

final class CoreReportAuditIntentConsumerTest extends TestCase
{
    public function test_rejects_unknown_event_and_subject_members_before_core_io(): void
    {
        $recorder = (new ReflectionClass(ImmutableAuditRecorder::class))->newInstanceWithoutConstructor();
        $consumer = new CoreReportAuditIntentConsumer($recorder, ReportRuntimeFixture::configuration());

        $this->assertRejectedBeforeCoreIo(
            $consumer,
            $this->intent('report.subscription.ready', $this->readySubject()),
        );
        $this->assertRejectedBeforeCoreIo(
            $consumer,
            $this->intent('report.run.ready', [...$this->readySubject(), 'rows' => [['secret' => true]]]),
        );
        $this->assertRejectedBeforeCoreIo(
            $consumer,
            $this->intent('report.run.ready', [...$this->readySubject(), 'unknown' => 'member']),
        );
        $this->assertRejectedBeforeCoreIo(
            $consumer,
            $this->intent('report.run.ready', [
                ...$this->readySubject(),
                'snapshot' => [...$this->readySubject()['snapshot'], 'unknown' => 'member'],
            ]),
        );
        $this->assertRejectedBeforeCoreIo(
            $consumer,
            $this->intent('report.run.queued', [
                'run_id' => '01J00000000000000000000002',
                'report_code' => 'cost_control',
                'status' => 'queued',
                'definition_hash' => str_repeat('a', 64),
                'query_hash' => str_repeat('b', 64),
                'contract_version' => '1',
                'formula_version' => '1',
                'source_schema_version' => '1',
                'renderer_version' => '1',
                'saved_view' => [
                    'id' => '01J00000000000000000000003',
                    'revision' => 1,
                    'hash' => str_repeat('c', 64),
                    'unknown' => 'member',
                ],
            ]),
        );
        $this->assertRejectedBeforeCoreIo(
            $consumer,
            $this->intent('report.export.queued', [
                'export_id' => '01J00000000000000000000004',
                'run_id' => '01J00000000000000000000002',
                'report_code' => 'cost_control',
                'status' => 'queued',
                'definition_hash' => str_repeat('a', 64),
                'query_hash' => str_repeat('b', 64),
                'source_hash' => str_repeat('c', 64),
                'result_hash' => str_repeat('d', 64),
                'snapshot_id' => 'snapshot-one',
                'snapshot_classification' => 'operational',
                'data_classification' => 'standard',
                'format' => 'xlsx',
                'columns' => ['z', 'a'],
                'locale' => 'ru',
                'timezone' => 'UTC',
                'renderer_version' => '1',
            ]),
        );
        $this->assertRejectedBeforeCoreIo(
            $consumer,
            $this->intent('report.run.ready', [
                ...$this->readySubject(),
                'snapshot' => [...$this->readySubject()['snapshot'], 'seal_digest' => 'not-a-hash'],
            ]),
        );
    }

    public function test_closed_ready_payload_contains_hashes_versions_count_and_no_rows(): void
    {
        $recorder = (new ReflectionClass(ImmutableAuditRecorder::class))->newInstanceWithoutConstructor();
        $consumer = new CoreReportAuditIntentConsumer($recorder, ReportRuntimeFixture::configuration());
        $method = new \ReflectionMethod($consumer, 'eventData');
        $data = $method->invoke($consumer, $this->intent('report.run.ready', $this->readySubject()));

        self::assertSame(10, $data->organizationId);
        self::assertSame(20, $data->actorUserId);
        self::assertSame('report.run.ready', $data->eventType);
        self::assertSame('reports:run:01J00000000000000000000002:ready', $data->sourceEventId);
        self::assertSame($this->readySubject(), $data->domainContext);
        self::assertArrayNotHasKey('rows', $data->domainContext);
        self::assertSame(17, $data->domainContext['row_count']);
        self::assertSame('1', $data->domainContext['renderer_version']);
    }

    public function test_long_exact_version_event_key_has_bounded_stable_core_identity(): void
    {
        $recorder = (new ReflectionClass(ImmutableAuditRecorder::class))->newInstanceWithoutConstructor();
        $consumer = new CoreReportAuditIntentConsumer($recorder, ReportRuntimeFixture::configuration());
        $method = new \ReflectionMethod($consumer, 'eventData');
        $versionId = str_repeat('v', 255);
        $eventKey = 'reports:export:01J00000000000000000000002:artifact-deleted:'.$versionId;
        $intent = new ReportAuditIntent(
            '01J00000000000000000000001',
            $eventKey,
            'report.export.artifact_deleted',
            10,
            20,
            [
                'export_id' => '01J00000000000000000000002',
                'run_id' => '01J00000000000000000000003',
                'report_code' => 'cost_control',
                'status' => 'expired',
                'format' => 'xlsx',
                'version_id' => $versionId,
                'occurred_at' => '2026-07-30T09:00:00.123456Z',
            ],
            1,
            new DateTimeImmutable('2026-07-30T09:00:00.123456Z'),
            new DateTimeImmutable('2026-07-30T09:00:00.123456Z'),
        );

        $first = $method->invoke($consumer, $intent);
        $second = $method->invoke($consumer, $intent);

        self::assertSame('reporting:sha256:'.hash('sha256', $eventKey), $first->sourceEventId);
        self::assertSame($first->sourceEventId, $second->sourceEventId);
        self::assertLessThanOrEqual(191, strlen((string) $first->sourceEventId));
        self::assertSame($versionId, $first->domainContext['version_id']);
    }

    private function intent(string $eventType, array $subject): ReportAuditIntent
    {
        return new ReportAuditIntent(
            '01J00000000000000000000001',
            'reports:run:01J00000000000000000000002:ready',
            $eventType,
            10,
            20,
            $subject,
            1,
            new DateTimeImmutable('2026-07-30T09:00:00.123456Z'),
            new DateTimeImmutable('2026-07-30T09:00:00.123456Z'),
        );
    }

    private function assertRejectedBeforeCoreIo(
        CoreReportAuditIntentConsumer $consumer,
        ReportAuditIntent $intent,
    ): void {
        self::assertContains(
            $intent->eventType,
            [
                'report.subscription.ready',
                'report.run.ready',
                'report.run.queued',
                'report.export.queued',
            ],
        );

        try {
            $consumer->append($intent);
        } catch (InvalidArgumentException $exception) {
            self::assertStringStartsWith('report_core_audit_', $exception->getMessage());

            return;
        }

        self::fail('Malformed Core audit intent was accepted.');
    }

    private function readySubject(): array
    {
        return [
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
            'row_count' => 17,
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
        ];
    }
}
