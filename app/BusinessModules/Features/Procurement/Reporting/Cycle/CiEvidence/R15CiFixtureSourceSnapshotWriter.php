<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotWrite;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementCycleSourceSnapshotWriter;
use InvalidArgumentException;

final readonly class R15CiFixtureSourceSnapshotWriter implements ProcurementCycleSourceSnapshotWriter
{
    public function __construct(private ReportSourceSnapshotWrite $snapshot) {}

    public function persist(ReportQuery $query): ReportSourceSnapshotHeader
    {
        if ($this->snapshot->header->reportQueryHash === null
            || ! hash_equals($this->snapshot->header->reportQueryHash->value, $query->queryHash->value)) {
            throw new InvalidArgumentException('r15_ci_fixture_query_mismatch');
        }

        return $this->snapshot->header;
    }
}
