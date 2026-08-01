<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class CurrentReportAuthorizationTargetTest extends TestCase
{
    public function test_fingerprint_binds_complete_definition_operation_and_snapshot(): void
    {
        $definition = $this->definition();
        $snapshot = $this->snapshot($definition);
        $view = new CurrentReportAuthorizationTarget($definition, ReportOperation::VIEW, null);
        $drillDown = new CurrentReportAuthorizationTarget($definition, ReportOperation::DRILL_DOWN, $snapshot);
        $replacementSnapshot = $this->snapshot($definition, 'replacement');

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $view->canonicalFingerprint());
        self::assertNotSame($view->canonicalFingerprint(), $drillDown->canonicalFingerprint());
        self::assertNotSame(
            $drillDown->canonicalFingerprint(),
            (new CurrentReportAuthorizationTarget(
                $definition,
                ReportOperation::DRILL_DOWN,
                $replacementSnapshot,
            ))->canonicalFingerprint(),
        );
        self::assertSame($view->canonicalFingerprint(), $view->canonicalFingerprint());
    }

    #[DataProvider('snapshotRequiredOperations')]
    public function test_persisted_snapshot_operations_require_snapshot(ReportOperation $operation): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('current_report_authorization_target_invalid');

        new CurrentReportAuthorizationTarget($this->definition(), $operation, null);
    }

    public static function snapshotRequiredOperations(): iterable
    {
        yield 'export' => [ReportOperation::EXPORT];
        yield 'download' => [ReportOperation::DOWNLOAD];
        yield 'drill-down' => [ReportOperation::DRILL_DOWN];
    }

    #[DataProvider('snapshotOptionalOperations')]
    public function test_non_persisted_snapshot_operations_accept_no_snapshot(ReportOperation $operation): void
    {
        $target = new CurrentReportAuthorizationTarget($this->definition(), $operation, null);

        self::assertNull($target->snapshot);
        self::assertSame($operation, $target->operation);
    }

    public static function snapshotOptionalOperations(): iterable
    {
        yield 'view' => [ReportOperation::VIEW];
        yield 'run' => [ReportOperation::RUN];
        yield 'manage' => [ReportOperation::MANAGE];
        yield 'sensitive' => [ReportOperation::VIEW_SENSITIVE];
        yield 'audit' => [ReportOperation::VIEW_AUDIT];
    }

    public function test_run_rejects_snapshot_replay(): void
    {
        $definition = $this->definition();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('current_report_authorization_target_invalid');

        new CurrentReportAuthorizationTarget($definition, ReportOperation::RUN, $this->snapshot($definition));
    }

    public function test_snapshot_from_other_definition_revision_is_rejected(): void
    {
        $current = $this->definition();
        $otherRevision = (new ReportDefinitionBuilder)
            ->definitionHash(new Sha256Hash(str_repeat('b', 64)))
            ->payload();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('current_report_authorization_target_invalid');

        new CurrentReportAuthorizationTarget(
            $current,
            ReportOperation::DOWNLOAD,
            $this->snapshot($otherRevision),
        );
    }

    public function test_snapshot_from_other_formula_version_is_rejected(): void
    {
        $current = $this->definition();
        $otherFormula = (new ReportDefinitionBuilder)->formulaVersion('2')->payload();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('current_report_authorization_target_invalid');

        new CurrentReportAuthorizationTarget(
            $current,
            ReportOperation::EXPORT,
            $this->snapshot($otherFormula),
        );
    }

    #[DataProvider('snapshotRequiredOperations')]
    public function test_matching_snapshot_identity_is_accepted(ReportOperation $operation): void
    {
        $definition = $this->definition();
        $snapshot = $this->snapshot($definition);
        $target = new CurrentReportAuthorizationTarget($definition, $operation, $snapshot);

        self::assertSame($snapshot, $target->snapshot);
    }

    public function test_definition_revision_operation_and_snapshot_replays_have_distinct_fingerprints(): void
    {
        $definition = $this->definition();
        $revision = (new ReportDefinitionBuilder)
            ->definitionHash(new Sha256Hash(str_repeat('b', 64)))
            ->payload();
        $snapshot = $this->snapshot($definition);
        $revisionSnapshot = $this->snapshot($revision);

        $targets = [
            new CurrentReportAuthorizationTarget($definition, ReportOperation::EXPORT, $snapshot),
            new CurrentReportAuthorizationTarget($definition, ReportOperation::DOWNLOAD, $snapshot),
            new CurrentReportAuthorizationTarget($revision, ReportOperation::EXPORT, $revisionSnapshot),
            new CurrentReportAuthorizationTarget($definition, ReportOperation::EXPORT, $this->snapshot($definition, 'other')),
        ];

        self::assertCount(4, array_unique(array_map(
            static fn (CurrentReportAuthorizationTarget $target): string => $target->canonicalFingerprint(),
            $targets,
        )));
    }

    private function definition(): ReportDefinition
    {
        return (new ReportDefinitionBuilder)->payload();
    }

    private function snapshot(ReportDefinition $definition, string $id = 'snapshot'): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            'report',
            $id,
            new ReportScope(1, [1], [], [], new DateTimeZone('UTC')),
            $definition->definitionHash,
            $definition->formulaVersion,
            new Sha256Hash(str_repeat('c', 64)),
            new DateTimeImmutable('2026-07-29T12:00:00+00:00'),
            null,
            [],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }
}
