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

    public function test_deletion_contract_uses_durable_lease_and_finalizes_after_external_io(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(DeleteExpiredReportArtifactsService::class))->getFileName(),
        );

        foreach ([
            "->where('status', ReportExportStatus::EXPIRED->value)",
            "->where('expired_at', '<=', \$this->timestamp(\$cutoff))",
            "->whereNull('artifact_deleted_at')",
            "->where('organization_id', \$organizationId)",
            '->lockForUpdate()',
            "'artifact_deletion_lease_token' => \$leaseToken",
            "'artifact_deletion_lease_expires_at' => \$this->timestamp(\$leaseExpiresAt)",
            "ReportAuditIntentRecord::query()->where('event_key', \$eventKey)->exists()",
            "\$this->files->deleteVersion(\$claim['path'], \$claim['version_id']);",
            "'version_id' => \$claim['version_id']",
            "'artifact_deleted_at' => \$this->timestamp(\$occurredAt)",
        ] as $requiredFence) {
            self::assertStringContainsString($requiredFence, $source);
        }

        $claim = strpos($source, '$claim = $this->claimArtifact(');
        $delete = strpos($source, "\$this->files->deleteVersion(\$claim['path'], \$claim['version_id']);");
        $finalize = strpos($source, 'return $this->finalizeDeletion(');
        self::assertIsInt($claim);
        self::assertIsInt($delete);
        self::assertIsInt($finalize);
        self::assertLessThan($delete, $claim);
        self::assertLessThan($finalize, $delete);
        self::assertStringNotContainsString(
            "DB::transaction(function () use (\n            \$exportId,\n            \$organizationId,\n            \$expectedPath",
            $source,
        );
        self::assertStringNotContainsString("'artifact_path' => null", $source);
        self::assertStringNotContainsString("'artifact_version_id' => null", $source);
    }

    public function test_storage_and_finalize_failures_release_the_claim_without_false_deletion(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(DeleteExpiredReportArtifactsService::class))->getFileName(),
        );

        self::assertStringContainsString('catch (Throwable $throwable)', $source);
        self::assertStringContainsString("\$summary['failed']++;", $source);
        self::assertStringContainsString("\$summary[\$deleted ? 'deleted' : 'skipped']++;", $source);
        self::assertSame(2, substr_count($source, '$this->releaseClaim('));
        self::assertStringContainsString("'artifact_deleted_at' => \$this->timestamp(\$occurredAt)", $source);
    }

    public function test_completed_rows_are_excluded_from_later_batches_to_prevent_starvation(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionClass(DeleteExpiredReportArtifactsService::class))->getFileName(),
        );

        $eligibility = strpos($source, "->whereNull('artifact_deleted_at')");
        $limit = strpos($source, '->limit($limit)');
        self::assertIsInt($eligibility);
        self::assertIsInt($limit);
        self::assertLessThan($limit, $eligibility);
        self::assertStringContainsString(
            'CREATE INDEX report_exports_artifact_deletion_due_idx',
            (string) file_get_contents(
                dirname(__DIR__, 4).'/database/migrations/2026_07_26_000005_create_report_exports_table.php',
            ),
        );
    }

    public function test_deletion_uses_a_closed_durable_state_machine(): void
    {
        $service = (string) file_get_contents(
            (new \ReflectionClass(DeleteExpiredReportArtifactsService::class))->getFileName(),
        );
        $migration = (string) file_get_contents(
            dirname(__DIR__, 4).'/database/migrations/2026_07_26_000005_create_report_exports_table.php',
        );

        foreach (['pending', 'deleting', 'storage_accepted', 'deleted'] as $state) {
            self::assertStringContainsString("'{$state}'", $service.$migration);
        }
        self::assertStringContainsString('artifact_deletion_state IN', $migration);
        self::assertStringContainsString('artifact_deletion_storage_accepted_at', $migration);
        self::assertStringContainsString(
            'artifact_deleted_at >= artifact_deletion_storage_accepted_at',
            $migration,
        );
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
