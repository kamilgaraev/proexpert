<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Retention;

use App\BusinessModules\Core\Reporting\Application\Audit\ReportTransitionAudit;
use App\BusinessModules\Core\Reporting\Application\Retention\DeleteExpiredReportArtifactsService;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportHydrator;
use App\Services\Storage\FileService;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class DeleteExpiredReportArtifactsServiceTest extends TestCase
{
    public function test_exposes_the_same_bounded_closed_summary_contract(): void
    {
        $method = new ReflectionMethod(DeleteExpiredReportArtifactsService::class, 'delete');

        self::assertSame(['limit', 'occurredAt'], array_map(
            static fn ($parameter): string => $parameter->getName(),
            $method->getParameters(),
        ));
        self::assertSame('array', (string) $method->getReturnType());
    }

    public function test_rejects_invalid_batch_and_grace_before_storage_or_database_access(): void
    {
        $files = $this->createMock(FileService::class);
        $files->expects(self::never())->method('deleteVersion');

        foreach ([0, 501] as $limit) {
            $service = new DeleteExpiredReportArtifactsService(
                $files,
                new RecordingArtifactDeletionAudit,
                new ReportExportHydrator,
                3600,
            );

            try {
                $service->delete($limit, new DateTimeImmutable('2026-07-30T10:00:00.123456Z'));
                self::fail('Unbounded artifact deletion batch was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('report_retention_batch_size_invalid', $exception->getMessage());
            }
        }

        foreach ([-1, 2_592_001] as $graceSeconds) {
            try {
                new DeleteExpiredReportArtifactsService(
                    $files,
                    new RecordingArtifactDeletionAudit,
                    new ReportExportHydrator,
                    $graceSeconds,
                );
                self::fail('Invalid artifact grace period was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('report_artifact_grace_period_invalid', $exception->getMessage());
            }
        }
    }

    public function test_deletion_event_identity_is_bound_to_export_and_exact_version(): void
    {
        $service = new DeleteExpiredReportArtifactsService(
            $this->createMock(FileService::class),
            new RecordingArtifactDeletionAudit,
            new ReportExportHydrator,
            3600,
        );
        $method = new ReflectionMethod($service, 'deletionEventKey');

        self::assertSame(
            'reports:export:01J00000000000000000000001:artifact-deleted:version-one',
            $method->invoke($service, '01J00000000000000000000001', 'version-one'),
        );
        self::assertNotSame(
            $method->invoke($service, '01J00000000000000000000001', 'version-one'),
            $method->invoke($service, '01J00000000000000000000001', 'version-two'),
        );
    }

    public function test_deletion_contract_fences_grace_tenant_state_exact_version_and_idempotency_before_io(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(DeleteExpiredReportArtifactsService::class))->getFileName(),
        );

        foreach ([
            "->where('status', ReportExportStatus::EXPIRED->value)",
            "->where('expired_at', '<=', \$this->timestamp(\$cutoff))",
            "->where('organization_id', \$organizationId)",
            '->lockForUpdate()',
            '$record->artifact_path !== $expectedPath',
            '$record->artifact_version_id !== $expectedVersionId',
            "ReportAuditIntentRecord::query()->where('event_key', \$eventKey)->exists()",
            '$this->files->deleteVersion($path, $versionId);',
            "'version_id' => \$versionId",
        ] as $requiredFence) {
            self::assertStringContainsString($requiredFence, $source);
        }

        $lock = strpos($source, '->lockForUpdate()');
        $identity = strpos($source, '$this->assertDeletable($record, $cutoff);');
        $deduplication = strpos($source, "ReportAuditIntentRecord::query()->where('event_key', \$eventKey)->exists()");
        $delete = strpos($source, '$this->files->deleteVersion($path, $versionId);');
        $audit = strpos($source, '$this->audit->append(');

        self::assertIsInt($lock);
        self::assertIsInt($identity);
        self::assertIsInt($deduplication);
        self::assertIsInt($delete);
        self::assertIsInt($audit);
        self::assertLessThan($identity, $lock);
        self::assertLessThan($deduplication, $identity);
        self::assertLessThan($delete, $deduplication);
        self::assertLessThan($audit, $delete);
        self::assertStringNotContainsString("'artifact_path' => null", $source);
        self::assertStringNotContainsString("'artifact_version_id' => null", $source);
    }

    public function test_storage_failure_remains_retryable_and_is_counted_without_false_deletion(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(DeleteExpiredReportArtifactsService::class))->getFileName(),
        );

        self::assertStringContainsString('catch (Throwable $throwable)', $source);
        self::assertStringContainsString("\$summary['failed']++;", $source);
        self::assertStringContainsString("\$summary[\$deleted ? 'deleted' : 'skipped']++;", $source);
        self::assertStringNotContainsString('->update([', $source);
    }
}

final class RecordingArtifactDeletionAudit implements ReportTransitionAudit
{
    public function append(
        string $eventId,
        string $eventType,
        ReportExecutionContext $context,
        array $subject,
        DateTimeImmutable $occurredAt,
    ): void {}
}
