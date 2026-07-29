<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\WaveOne;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursorKeyset;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownCell;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownInput;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollReadinessFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Formulas\PayrollSourceRateFormula;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Infrastructure\DatabasePayrollReadinessAdapter;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class PayrollReadinessDatabaseContractTest extends TestCase
{
    #[Test]
    public function cursor_and_drill_down_read_the_same_snapshot_with_exact_export_envelope(): void
    {
        [$adapter, $snapshot] = $this->fixture();
        $context = (new ReportExecutionContextBuilder())->build();
        $sort = new ReportWindowSort('period_start', ReportSortDirection::ASC);

        $chunks = iterator_to_array($adapter->cursor($context, $snapshot, $sort, 1), false);
        $drillDown = $adapter->drillDown(
            $context,
            $snapshot,
            new ReportDrillDownInput(new ReportDrillDownCell('row_1', 'status'), null, 10),
        );

        self::assertCount(1, $chunks);
        self::assertSame(
            ['query_hash', 'row_key', 'snapshot_id', 'source_hash', 'values'],
            array_keys($chunks[0]),
        );
        self::assertSame('row_1', $chunks[0]['row_key']);
        self::assertArrayNotHasKey('rate', $chunks[0]['values']);
        self::assertArrayNotHasKey('amount', $chunks[0]['values']);
        self::assertSame('payroll_source_row', $drillDown->rows[0]['source_type']);
        self::assertNull($drillDown->nextCursor);
    }

    #[Test]
    public function page_rejects_a_cursor_signed_for_another_query(): void
    {
        [$adapter, $snapshot] = $this->fixture();
        $context = (new ReportExecutionContextBuilder())->build();
        $sort = new ReportWindowSort('period_start', ReportSortDirection::ASC);
        $cursor = new ReportCursor(
            token: 'signed.cursor',
            runId: '01J00000000000000000000000',
            queryHash: new Sha256Hash(str_repeat('c', 64)),
            sourceHash: $snapshot->sourceHash,
            sort: $sort,
            keyset: new ReportCursorKeyset('2026-07-01', 'row_1'),
            expiresAt: new DateTimeImmutable('+1 hour'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payroll_readiness_cursor_invalid');
        $adapter->page($context, $snapshot, $sort, $cursor, 10);
    }

    #[Test]
    public function foreign_organization_filter_id_is_rejected_with_the_common_not_found_error(): void
    {
        [$adapter, , $connection] = $this->fixture();
        $connection->statement(
            'CREATE TABLE projects (id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL)',
        );
        $connection->table('projects')->insert(['id' => 9, 'organization_id' => 2]);
        $method = new ReflectionMethod($adapter, 'assertOrganizationIds');
        $method->setAccessible(true);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('REPORT_FILTER_VALUE_NOT_FOUND');
        $method->invoke(
            $adapter,
            'projects',
            (new ReportExecutionContextBuilder())->build()->scope,
            [9],
        );
    }

    #[Test]
    public function drill_down_cursor_pages_all_source_references_without_repeating_rows(): void
    {
        [$adapter, $snapshot] = $this->fixture();
        $context = (new ReportExecutionContextBuilder())
            ->visibility(new ReportVisibility(true, true, true, true, false, true, false))
            ->build();
        $cell = new ReportDrillDownCell('row_1', 'status');
        $first = $adapter->drillDown(
            $context,
            $snapshot,
            new ReportDrillDownInput($cell, null, 1),
        );
        self::assertNotNull($first->nextCursor);
        $second = $adapter->drillDown(
            $context,
            $snapshot,
            new ReportDrillDownInput($cell, $first->nextCursor, 1),
        );

        self::assertNotSame($first->rows[0]['row_key'], $second->rows[0]['row_key']);
        self::assertSame('labor_rate_version', $second->rows[0]['source_type']);
        self::assertNull($second->nextCursor);
    }

    #[Test]
    public function scoped_resource_mismatch_is_rejected_when_the_snapshot_row_is_read(): void
    {
        [$adapter, $snapshot] = $this->fixture();
        $resource = new ReportScopedResource('payroll_source_row', 99, null);
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope(1, [1], [], [$resource], $timezone);
        $authorization = new AuthorizationDecisionContext(
            'http',
            1,
            [1],
            [],
            [$resource],
            $timezone,
            'report-test',
            null,
        );
        $context = (new ReportExecutionContextBuilder())
            ->scope($scope)
            ->authorization($authorization)
            ->build();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('REPORT_FILTER_VALUE_NOT_FOUND');
        iterator_to_array(
            $adapter->cursor(
                $context,
                $snapshot,
                new ReportWindowSort('period_start', ReportSortDirection::ASC),
                10,
            ),
            false,
        );
    }

    private function fixture(): array
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->statement(
            'CREATE TABLE workforce_report_snapshots (
                id TEXT PRIMARY KEY,
                organization_id INTEGER NOT NULL,
                report_code TEXT NOT NULL,
                query_hash TEXT NOT NULL,
                source_hash TEXT NOT NULL,
                totals TEXT NOT NULL,
                freshness_status TEXT NOT NULL,
                quality_status TEXT NOT NULL,
                reconciliation_status TEXT NOT NULL,
                row_schema TEXT NOT NULL,
                warnings TEXT NOT NULL,
                source_refs TEXT NOT NULL,
                row_count INTEGER NOT NULL
            )',
        );
        $connection->statement(
            'CREATE TABLE payroll_readiness_snapshot_rows (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                organization_id INTEGER NOT NULL,
                snapshot_id TEXT NOT NULL,
                row_key TEXT NOT NULL,
                period_start TEXT,
                row_payload TEXT NOT NULL
            )',
        );
        $snapshotId = '01J00000000000000000000001';
        $queryHash = new Sha256Hash(str_repeat('a', 64));
        $sourceHash = new Sha256Hash(str_repeat('b', 64));
        $payload = [
            'row_key' => 'row_1',
            'row_type' => 'source',
            'payroll_period_id' => 5,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'calculation_version_id' => 8,
            'calculation_version' => 3,
            'employee_id' => 7,
            'employee_name' => 'Сотрудник',
            'project_id' => null,
            'project_name' => null,
            'source_type' => 'timesheet',
            'source_row_id' => 11,
            'hours' => '8.0000',
            'rate' => '100.0000',
            'rate_type' => 'hourly',
            'amount' => '800.0000',
            'currency' => 'RUB',
            'issue_id' => null,
            'issue_code' => null,
            'severity' => null,
            'status' => 'ready',
            'source_refs' => [
                ['type' => 'payroll_source_row', 'id' => 11],
                ['type' => 'labor_rate_version', 'id' => 4],
            ],
            'audit_refs' => [],
        ];
        $connection->table('workforce_report_snapshots')->insert([
            'id' => $snapshotId,
            'organization_id' => 1,
            'report_code' => 'payroll_readiness',
            'query_hash' => $queryHash->value,
            'source_hash' => $sourceHash->value,
            'totals' => json_encode([
                'source_rows' => 1,
                'covered_source_rows' => 1,
                'source_amounts' => ['RUB' => '800.0000'],
            ], JSON_THROW_ON_ERROR),
            'freshness_status' => 'fresh',
            'quality_status' => 'complete',
            'reconciliation_status' => 'matched',
            'row_schema' => '[]',
            'warnings' => '[]',
            'source_refs' => json_encode([[
                'source' => 'payroll_calculation',
                'snapshot_kind' => 'payroll_calculation_version',
                'snapshot_id' => 'calculation_8',
                'schema_version' => 'v1',
                'watermark' => 'version_3_locked',
                'row_count' => 1,
                'hash' => $sourceHash->value,
            ]], JSON_THROW_ON_ERROR),
            'row_count' => 1,
        ]);
        $connection->table('payroll_readiness_snapshot_rows')->insert([
            'organization_id' => 1,
            'snapshot_id' => $snapshotId,
            'row_key' => 'row_1',
            'period_start' => '2026-07-01',
            'row_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $snapshot = new ReportSnapshotRef(
            kind: 'payroll_readiness',
            id: $snapshotId,
            scope: (new ReportExecutionContextBuilder())->build()->scope,
            definitionHash: new Sha256Hash(str_repeat('d', 64)),
            formulaVersion: 'payroll-readiness.v1',
            sourceHash: $sourceHash,
            generatedAt: new DateTimeImmutable('2026-07-31T12:00:00Z'),
            staleAt: new DateTimeImmutable('2026-08-01T12:00:00Z'),
            watermarks: ['calculation_8' => 'version_3_locked'],
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );

        return [
            new DatabasePayrollReadinessAdapter(
                $connection,
                new PayrollReadinessFormula(),
                new PayrollSourceRateFormula(),
            ),
            $snapshot,
            $connection,
        ];
    }
}
