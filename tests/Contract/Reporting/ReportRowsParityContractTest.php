<?php

declare(strict_types=1);

namespace Tests\Contract\Reporting;

use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunkReader;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\FakeReportRowQuery;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class ReportRowsParityContractTest extends TestCase
{
    public function test_online_page_and_export_cursor_use_the_same_context_snapshot_and_sort(): void
    {
        $context = (new ReportExecutionContextBuilder())->build();
        $run = (new ReportRunBuilder())->ready();
        $snapshot = $run->resultMetadata->snapshot;
        $sort = new ReportWindowSort('name', ReportSortDirection::ASC);
        $page = new ReportPage(
            [['row_key' => 'row-1', 'name' => 'Строка']],
            [],
            ReportFreshnessStatus::FRESH,
            new ReportQuality(
                ReportQualityStatus::COMPLETE,
                null,
                [],
                0,
                ReportReconciliationStatus::MATCHED,
                [],
                [],
            ),
            null,
            100,
            false,
            $sort,
        );
        $query = new FakeReportRowQuery($page, [[
            'row_key' => 'row-1',
            'values' => ['name' => 'Строка'],
            'snapshot_id' => $snapshot->id,
            'query_hash' => $run->queryHash->value,
            'source_hash' => $snapshot->sourceHash->value,
        ]]);

        $query->page($context, $snapshot, $sort, null, 100);
        iterator_to_array((new ReportRowChunkReader())->read(
            $context,
            $snapshot,
            $run->queryHash,
            $sort,
            5000,
            $query,
        ));

        self::assertSame($context, $query->pageCalls()[0][0]);
        self::assertSame($context, $query->cursorCalls()[0][0]);
        self::assertSame($snapshot, $query->pageCalls()[0][1]);
        self::assertSame($snapshot, $query->cursorCalls()[0][1]);
        self::assertSame($sort, $query->pageCalls()[0][2]);
        self::assertSame($sort, $query->cursorCalls()[0][2]);
    }
}
