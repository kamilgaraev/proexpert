<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportCursorCodec;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\FakeReportExecutionClock;

final class LineagePaginationCursorTest extends TestCase
{
    private const RUN_ID = '01J00000000000000000000000';

    #[Test]
    public function signed_lineage_cursor_is_bound_to_parent_snapshot_and_tuple_position(): void
    {
        $codec = $this->codec();
        $snapshot = $this->snapshot();
        $queryHash = new Sha256Hash(str_repeat('b', 64));
        $token = $codec->encodeDrillDownPage(
            organizationId: 3,
            reportCode: 'lookahead_readiness',
            runId: self::RUN_ID,
            snapshot: $snapshot,
            queryHash: $queryHash,
            parentRowKey: '7:11:13:17',
            lastStableRowKey: 'e:7:91',
            expiresAt: new DateTimeImmutable('2030-01-01T01:00:00+00:00'),
        );

        self::assertSame(
            'e:7:91',
            $codec->decodeDrillDownPage(
                $token,
                3,
                'lookahead_readiness',
                self::RUN_ID,
                $snapshot,
                $queryHash,
                '7:11:13:17',
            ),
        );
        $contextToken = $codec->encodeDrillDownPage(
            organizationId: 3,
            reportCode: 'lookahead_readiness',
            runId: self::RUN_ID,
            snapshot: $snapshot,
            queryHash: $queryHash,
            parentRowKey: '7:11:13:17',
            lastStableRowKey: 'c:2',
            expiresAt: new DateTimeImmutable('2030-01-01T01:00:00+00:00'),
        );
        self::assertSame(
            'c:2',
            $codec->decodeDrillDownPage(
                $contextToken,
                3,
                'lookahead_readiness',
                self::RUN_ID,
                $snapshot,
                $queryHash,
                '7:11:13:17',
            ),
        );

        $this->expectException(ReportContractException::class);
        $codec->decodeDrillDownPage(
            $token,
            3,
            'lookahead_readiness',
            self::RUN_ID,
            $snapshot,
            $queryHash,
            '7:11:13:18',
        );
    }

    private function codec(): SignedReportCursorCodec
    {
        return new SignedReportCursorCodec(
            ['cursor-v1' => str_repeat('a', 64)],
            'cursor-v1',
            new FakeReportExecutionClock(new DateTimeImmutable('2030-01-01T00:00:00+00:00')),
        );
    }

    private function snapshot(): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            kind: 'lookahead_readiness',
            id: '01J11111111111111111111111',
            scope: new ReportScope(3, [3], [7], [], new DateTimeZone('UTC')),
            definitionHash: new Sha256Hash(str_repeat('c', 64)),
            formulaVersion: 'lookahead_readiness.v1',
            sourceHash: new Sha256Hash(str_repeat('d', 64)),
            generatedAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            staleAt: new DateTimeImmutable('2030-01-01T00:15:00+00:00'),
            watermarks: ['events' => 'event_91'],
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
    }
}
