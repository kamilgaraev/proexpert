<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotDrillPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotReadRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Features\Budgeting\Contracts\BudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\Contracts\ProjectMarginSourceSnapshotReport;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceWatermark;
use App\BusinessModules\Features\Budgeting\DTOs\CreateBudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginSourceSnapshotRequest;
use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use App\BusinessModules\Features\Budgeting\Services\BudgetingReportSourceCloseService;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotMaterializer;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginSourceSnapshotWriter;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProjectMarginSourceSnapshotWriterCloseTest extends TestCase
{
    public function test_writer_persists_only_after_an_approved_matching_close_is_validated(): void
    {
        $report = new class implements ProjectMarginSourceSnapshotReport {
            public int $calls = 0;

            public function reportForProjectScope(array $input, array $projectIds): array
            {
                $this->calls++;

                return ['filters' => ['budget_version_uuid' => 'budget-1', 'scenario_uuid' => 'scenario-1'], 'period' => ['from' => '2026-01-01', 'to' => '2026-01-31'], 'rows' => []];
            }

            public function drillDownForProjectScope(array $input, array $projectIds): array
            {
                throw new LogicException();
            }
        };
        $store = $this->snapshotStore();

        (new ProjectMarginSourceSnapshotWriter($report, new ProjectMarginSourceSnapshotMaterializer(), $store, $this->closeService($this->close())))->persist($this->request());

        self::assertSame(1, $report->calls);
        self::assertSame('01JZZZZZZZZZZZZZZZZZZZZZZZ', $store->write?->header->watermarks['close_id']);
        self::assertSame('actuals:1', $store->write?->header->watermarks['source_watermarks'][0]['watermark']);
    }

    public function test_writer_rejects_expired_restated_and_wrong_organization_closes_before_live_report_calls(): void
    {
        foreach ([
            $this->close(status: BudgetingReportSourceCloseStatus::EXPIRED),
            $this->close(status: BudgetingReportSourceCloseStatus::RESTATED),
            $this->close(identity: new BudgetingReportSourceCloseIdentity(2, '2026-01-01', '2026-01-31', 'scenario-1', 'budget-1')),
        ] as $close) {
            $report = new class implements ProjectMarginSourceSnapshotReport {
                public int $calls = 0;

                public function reportForProjectScope(array $input, array $projectIds): array
                {
                    $this->calls++;

                    throw new LogicException();
                }

                public function drillDownForProjectScope(array $input, array $projectIds): array
                {
                    throw new LogicException();
                }
            };

            try {
                (new ProjectMarginSourceSnapshotWriter($report, new ProjectMarginSourceSnapshotMaterializer(), $this->snapshotStore(), $this->closeService($close)))->persist($this->request());
                self::fail('Expected the invalid close to be rejected.');
            } catch (DomainException) {
                self::assertSame(0, $report->calls);
            }
        }
    }

    private function request(): ProjectMarginSourceSnapshotRequest
    {
        return new ProjectMarginSourceSnapshotRequest(
            scope: new ReportScope(1, [1], [], [], new DateTimeZone('UTC')),
            filters: [
                'organization_id' => 1,
                'period_start' => '2026-01-01',
                'period_end' => '2026-01-31',
                'budget_version_uuid' => 'budget-1',
                'scenario_uuid' => 'scenario-1',
            ],
            closeId: '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            closeIdentity: $this->identity(),
            asOf: new DateTimeImmutable('2026-07-31T10:00:00+00:00'),
            staleAt: null,
            snapshotId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );
    }

    private function identity(): BudgetingReportSourceCloseIdentity
    {
        return new BudgetingReportSourceCloseIdentity(1, '2026-01-01', '2026-01-31', 'scenario-1', 'budget-1');
    }

    private function close(
        BudgetingReportSourceCloseStatus $status = BudgetingReportSourceCloseStatus::APPROVED,
        ?BudgetingReportSourceCloseIdentity $identity = null,
    ): BudgetingReportSourceClose {
        return new BudgetingReportSourceClose(
            closeId: '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            identity: $identity ?? $this->identity(),
            sourceWatermarks: [new BudgetingReportSourceWatermark('actuals', new DateTimeImmutable('2026-01-31T17:00:00+00:00'), 'actuals:1', 'actuals-v1')],
            formulaVersion: 'margin-v1',
            sourceManifest: ['actuals' => ['version' => 'actuals:1']],
            contentHash: str_repeat('a', 64),
            approvedBy: 1,
            approvedAt: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
            retainedUntil: new DateTimeImmutable('2033-01-31T00:00:00+00:00'),
            status: $status,
            restatesCloseId: null,
        );
    }

    private function closeService(BudgetingReportSourceClose $close): BudgetingReportSourceCloseService
    {
        return new BudgetingReportSourceCloseService(new class($close) implements BudgetingReportSourceCloseStore {
            public function __construct(private readonly BudgetingReportSourceClose $close)
            {
            }

            public function createApproved(CreateBudgetingReportSourceClose $request): BudgetingReportSourceClose
            {
                throw new LogicException();
            }

            public function find(string $closeId): ?BudgetingReportSourceClose
            {
                return $closeId === $this->close->closeId ? $this->close : null;
            }
        });
    }

    private function snapshotStore(): ReportSourceSnapshotStore
    {
        return new class implements ReportSourceSnapshotStore {
            public ?ReportSourceSnapshotWrite $write = null;

            public function persistReady(ReportSourceSnapshotWrite $snapshot): ReportSourceSnapshotHeader
            {
                $this->write = $snapshot;

                return $snapshot->header;
            }

            public function header(ReportSourceSnapshotReadRequest $request): ReportSourceSnapshotHeader
            {
                throw new LogicException();
            }

            public function page(ReportSourceSnapshotReadRequest $request, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotPage
            {
                throw new LogicException();
            }

            public function drillPage(ReportSourceSnapshotReadRequest $request, string $rowKey, string $columnId, ?ReportSourceSnapshotCursor $cursor, int $limit): ReportSourceSnapshotDrillPage
            {
                throw new LogicException();
            }
        };
    }
}
