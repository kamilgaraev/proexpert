<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Actions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportDrillDownHandler;
use App\BusinessModules\Core\Reporting\Application\Actions\Handlers\GetReportRowsHandler;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunStore;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionBindingAssembler;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBindingMap;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRowsWindow;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportRun;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportRunStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportCursorCodec;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\FakeReportDrillDownProvider;
use Tests\Support\Reporting\FakeReportExecutionClock;
use Tests\Support\Reporting\FakeReportRowQuery;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class ReportReadHandlersTest extends TestCase
{
    private const RUN_ID = '01J00000000000000000000000';

    private DateTimeImmutable $now;
    private FakeReportExecutionClock $clock;
    private ReportExecutionContext $context;
    private ReportWindowSort $sort;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $this->clock = new FakeReportExecutionClock($this->now);
        $this->context = (new ReportExecutionContextBuilder())->build();
        $this->sort = new ReportWindowSort('name', ReportSortDirection::ASC);
    }

    public function test_rows_use_the_sealed_snapshot_original_query_and_typed_authorization_order(): void
    {
        $fixture = $this->fixture(
            new ReportOutputClassification(
                ReportDataClassification::STANDARD,
                ['secret_amount'],
                ['audit_event'],
                false,
                false,
                false,
            ),
            columns: [['id' => 'name'], ['id' => 'secret_amount'], ['id' => 'audit_event']],
        );
        $operations = [];
        $handler = $this->rowsHandler($fixture, $this->authorizer($operations));

        $result = $handler->handle(
            $this->context,
            self::RUN_ID,
            new ReportRowsWindow(null, 50, $this->sort),
        );

        self::assertSame($fixture['page'], $result);
        self::assertSame([
            ReportOperation::VIEW,
            ReportOperation::VIEW_SENSITIVE,
            ReportOperation::VIEW_AUDIT,
        ], $operations);
        self::assertCount(1, $fixture['rowQuery']->pageCalls());
        self::assertSame($fixture['run']->resultMetadata->snapshot, $fixture['rowQuery']->pageCalls()[0][1]);
        self::assertSame($this->sort, $fixture['rowQuery']->pageCalls()[0][2]);
        self::assertNull($fixture['rowQuery']->pageCalls()[0][3]);
        self::assertSame(50, $fixture['rowQuery']->pageCalls()[0][4]);
    }

    public function test_column_values_and_empty_permission_lists_do_not_drive_classification(): void
    {
        $fixture = $this->fixture(
            new ReportOutputClassification(
                ReportDataClassification::STANDARD,
                [],
                [],
                false,
                false,
                false,
            ),
            columns: [['id' => 'sensitive_password', 'label' => 'Аудит']],
            permissions: new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []),
            rows: [['row_key' => 'row-1', 'sensitive_password' => 'значение аудита']],
        );
        $operations = [];

        $this->rowsHandler($fixture, $this->authorizer($operations))->handle(
            $this->context,
            self::RUN_ID,
            new ReportRowsWindow(null, 50, $this->sort),
        );

        self::assertSame([ReportOperation::VIEW], $operations);
    }

    public function test_drill_down_applies_typed_decisions_before_drill_down_and_preserves_snapshot(): void
    {
        $fixture = $this->fixture(
            new ReportOutputClassification(
                ReportDataClassification::SENSITIVE,
                [],
                ['audit_event'],
                false,
                false,
                false,
            ),
            columns: [['id' => 'name'], ['id' => 'audit_event']],
        );
        $operations = [];
        $request = new ReportDrillDownRequest(
            $this->cellToken($fixture, 'row-1', 'name'),
            null,
            25,
        );

        $result = $this->drillDownHandler($fixture, $this->authorizer($operations))
            ->handle($this->context, self::RUN_ID, $request);

        self::assertSame($fixture['drillResult'], $result);
        self::assertSame([
            ReportOperation::VIEW,
            ReportOperation::VIEW_SENSITIVE,
            ReportOperation::VIEW_AUDIT,
            ReportOperation::DRILL_DOWN,
        ], $operations);
        self::assertCount(1, $fixture['drillDown']->calls());
        self::assertSame($fixture['run']->resultMetadata->snapshot, $fixture['drillDown']->calls()[0][1]);
        self::assertSame($request, $fixture['drillDown']->calls()[0][2]);
    }

    public function test_drill_down_rejects_invalid_or_cross_identity_cell_tokens_before_provider_call(): void
    {
        $cases = [
            'tamper' => static function (self $test, array $fixture): string {
                $token = $test->cellToken($fixture, 'row-1', 'name');

                return substr($token, 0, -1).($token[-1] === 'A' ? 'B' : 'A');
            },
            'cross-run' => fn (self $test, array $fixture): string => $test->cellToken(
                $fixture,
                'row-1',
                'name',
                runId: '01J00000000000000000000001',
            ),
            'cross-query' => fn (self $test, array $fixture): string => $test->cellToken(
                $fixture,
                'row-1',
                'name',
                queryHash: new Sha256Hash(str_repeat('d', 64)),
            ),
            'cross-snapshot' => fn (self $test, array $fixture): string => $test->cellToken(
                $fixture,
                'row-1',
                'name',
                snapshot: (new ReportRunBuilder())
                    ->sourceHash(new Sha256Hash(str_repeat('d', 64)))
                    ->ready()
                    ->resultMetadata
                    ->snapshot,
            ),
            'cell-mismatch' => fn (self $test, array $fixture): string => $test->cellToken(
                $fixture,
                'row-1',
                'unknown_column',
            ),
        ];

        foreach ($cases as $case => $token) {
            $fixture = $this->fixture();
            $operations = [];
            try {
                $this->drillDownHandler($fixture, $this->authorizer($operations))->handle(
                    $this->context,
                    self::RUN_ID,
                    new ReportDrillDownRequest($token($this, $fixture), null, 25),
                );
                self::fail('Ожидалось отклонение cell token: '.$case);
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_CURSOR_INVALID, $exception->errorCode, $case);
                self::assertSame([], $fixture['drillDown']->calls(), $case);
            }
        }
    }

    public function test_rows_reject_expired_status_before_authorization_or_provider_call(): void
    {
        $fixture = $this->fixture();
        $expired = (new ReportRunBuilder())
            ->id(self::RUN_ID)
            ->status(ReportRunStatus::EXPIRED)
            ->createdAt($this->now->modify('-2 hours'))
            ->updatedAt($this->now->modify('-1 hour'))
            ->expiresAt($this->now->modify('-1 hour'))
            ->queued();
        $fixture['run'] = $expired;
        $operations = [];

        $this->expectReportError(
            ReportErrorCode::REPORT_SNAPSHOT_EXPIRED,
            fn () => $this->rowsHandler($fixture, $this->authorizer($operations))->handle(
                $this->context,
                self::RUN_ID,
                new ReportRowsWindow(null, 50, $this->sort),
            ),
        );

        self::assertSame([], $operations);
        self::assertSame([], $fixture['rowQuery']->pageCalls());
    }

    public function test_drill_down_rejects_time_expired_ready_run_before_provider_call(): void
    {
        $fixture = $this->fixture();
        $fixture['run'] = $this->readyRun(
            $fixture['query'],
            $this->now->modify('-1 second'),
        );
        $operations = [];

        $this->expectReportError(
            ReportErrorCode::REPORT_SNAPSHOT_EXPIRED,
            fn () => $this->drillDownHandler($fixture, $this->authorizer($operations))->handle(
                $this->context,
                self::RUN_ID,
                new ReportDrillDownRequest('drill-token', null, 25),
            ),
        );

        self::assertSame([], $operations);
        self::assertSame([], $fixture['drillDown']->calls());
    }

    public function test_revocation_prevents_rows_provider_call(): void
    {
        $fixture = $this->fixture();
        $authorizer = $this->createMock(CurrentReportScopeAuthorizer::class);
        $authorizer->method('authorizeExact')->willThrowException(
            ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN),
        );

        $this->expectReportError(
            ReportErrorCode::REPORT_SCOPE_FORBIDDEN,
            fn () => $this->rowsHandler($fixture, $authorizer)->handle(
                $this->context,
                self::RUN_ID,
                new ReportRowsWindow(null, 50, $this->sort),
            ),
        );

        self::assertSame([], $fixture['rowQuery']->pageCalls());
    }

    private function fixture(
        ?ReportOutputClassification $classification = null,
        array $columns = [['id' => 'name']],
        ?ReportPermissionPolicy $permissions = null,
        array $rows = [['row_key' => 'row-1', 'name' => 'Строка']],
    ): array {
        $definition = (new ReportDefinitionBuilder())
            ->columns($columns)
            ->permissionPolicy($permissions ?? new ReportPermissionPolicy(['reports.view'], ['reports.export'], [], []))
            ->outputClassification($classification ?? new ReportOutputClassification(
                ReportDataClassification::STANDARD,
                [],
                [],
                false,
                false,
                false,
            ))
            ->payload();
        $query = new ReportQuery(
            $definition,
            $this->context->scope,
            new ReportFilterSet([]),
            [],
            $this->now,
            'ru',
        );
        $run = $this->readyRun($query, $this->now->modify('+1 hour'));
        $page = new ReportPage(
            $rows,
            [],
            ReportFreshnessStatus::FRESH,
            new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality(
                ReportQualityStatus::COMPLETE,
                null,
                [],
                0,
                ReportReconciliationStatus::MATCHED,
                [],
                [],
            ),
            null,
            50,
            false,
            $this->sort,
        );
        $rowQuery = new FakeReportRowQuery($page, []);
        $drillResult = new ReportDrillDownResult($rows, null, []);
        $drillDown = new FakeReportDrillDownProvider($drillResult);
        $binding = new ReportDefinitionBinding(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $this->createMock(ReportDataProvider::class),
            $rowQuery,
            $drillDown,
            null,
        );
        $registry = $this->createMock(ReportDefinitionRegistry::class);
        $assembler = $this->createMock(ReportDefinitionBindingAssembler::class);
        $assembler->method('assemble')->with($registry)->willReturn(
            new ReportDefinitionBindingMap([$definition->code => $binding]),
        );
        $store = $this->createMock(ReportRunStore::class);

        return compact(
            'definition',
            'query',
            'run',
            'page',
            'rowQuery',
            'drillResult',
            'drillDown',
            'registry',
            'assembler',
            'store',
        );
    }

    private function readyRun(ReportQuery $query, DateTimeImmutable $expiresAt): ReportRun
    {
        return (new ReportRunBuilder())
            ->id(self::RUN_ID)
            ->reportCode($query->definition->code)
            ->definitionHash($query->definition->definitionHash)
            ->contractVersion($query->definition->contractVersion)
            ->formulaVersion($query->definition->formulaVersion)
            ->sourceSchemaVersion($query->definition->sourceSchemaVersion)
            ->rendererVersion($query->definition->rendererVersion)
            ->queryHash($query->queryHash)
            ->createdAt($this->now->modify('-2 minutes'))
            ->updatedAt($this->now->modify('-1 minute'))
            ->readyAt($this->now->modify('-1 minute'))
            ->expiresAt($expiresAt)
            ->ready();
    }

    private function authorizer(array &$operations): CurrentReportScopeAuthorizer&MockObject
    {
        $authorizer = $this->createMock(CurrentReportScopeAuthorizer::class);
        $authorizer->method('authorizeExact')->willReturnCallback(
            function (int $actorId, $scope, CurrentReportAuthorizationTarget $target) use (&$operations): CurrentReportAuthorization {
                $operations[] = $target->operation;

                return new CurrentReportAuthorization(
                    $this->context->actor,
                    $this->context->authorization,
                    new ReportVisibility(true, true, true, true, true, true, true),
                    $target,
                );
            },
        );

        return $authorizer;
    }

    private function rowsHandler(array $fixture, CurrentReportScopeAuthorizer $authorizer): GetReportRowsHandler
    {
        $fixture['store']->method('get')->with($this->context, self::RUN_ID)->willReturn($fixture['run']);
        $fixture['store']->method('queryForRun')->with($this->context, self::RUN_ID)->willReturn($fixture['query']);

        return new GetReportRowsHandler(
            $fixture['store'],
            $fixture['registry'],
            $fixture['assembler'],
            $authorizer,
            new ReportExecutionContextFactory(),
            new SignedReportCursorCodec(
                ['cursor-v1' => str_repeat('a', 64)],
                'cursor-v1',
                $this->clock,
            ),
            $this->clock,
        );
    }

    private function drillDownHandler(array $fixture, CurrentReportScopeAuthorizer $authorizer): GetReportDrillDownHandler
    {
        $fixture['store']->method('get')->with($this->context, self::RUN_ID)->willReturn($fixture['run']);
        $fixture['store']->method('queryForRun')->with($this->context, self::RUN_ID)->willReturn($fixture['query']);

        return new GetReportDrillDownHandler(
            $fixture['store'],
            $fixture['registry'],
            $fixture['assembler'],
            $authorizer,
            new ReportExecutionContextFactory(),
            $this->codec(),
            $this->clock,
        );
    }

    private function cellToken(
        array $fixture,
        string $rowKey,
        string $columnId,
        string $runId = self::RUN_ID,
        ?Sha256Hash $queryHash = null,
        ?\App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef $snapshot = null,
    ): string {
        return $this->codec()->encodeDrillDownCell(
            organizationId: $fixture['query']->scope->organizationId,
            reportCode: $fixture['run']->reportCode,
            runId: $runId,
            snapshot: $snapshot ?? $fixture['run']->resultMetadata->snapshot,
            queryHash: $queryHash ?? $fixture['run']->queryHash,
            rowKey: $rowKey,
            columnId: $columnId,
            expiresAt: $fixture['run']->expiresAt,
        );
    }

    private function codec(): SignedReportCursorCodec
    {
        return new SignedReportCursorCodec(
            ['cursor-v1' => str_repeat('a', 64)],
            'cursor-v1',
            $this->clock,
        );
    }

    private function expectReportError(ReportErrorCode $expected, callable $callback): void
    {
        try {
            $callback();
            self::fail('Ожидалась доменная ошибка чтения отчёта.');
        } catch (ReportContractException $exception) {
            self::assertSame($expected, $exception->errorCode);
        }
    }
}
