<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\SourceSnapshots\ReportSourceSnapshotHeader;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementCycleSourceReader;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementCycleSourceSnapshotWriter;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleSnapshotRequest;
use Illuminate\Support\Str;

final readonly class CanonicalProcurementCycleSourceSnapshotWriter implements ProcurementCycleSourceSnapshotWriter
{
    public function __construct(
        private ProcurementCycleSourceReader $reader,
        private ProcurementCycleFormula $formula,
        private ProcurementCycleSourceSnapshotMaterializer $materializer,
        private ReportSourceSnapshotStore $store,
    ) {}

    public function persist(ReportQuery $query, ReportProgress $progress): ReportSourceSnapshotHeader
    {
        $progress->advance(10);
        $request = new ProcurementCycleSnapshotRequest(
            $query->scope,
            $query->filters->values,
            $query->asOf,
            null,
        );
        $results = [];
        $eventsByLine = [];
        $source = $this->reader->read(
            $request,
            function (array $events, $policy) use (&$results, &$eventsByLine, $query): void {
                $lineId = $events[0]->transition->purchaseRequestLineId;
                $eventsByLine[$lineId] = $events;
                $results[] = $this->formula->calculate($events, $policy, $query->asOf);
            },
        );
        $progress->advance(40);
        $identity = $this->materializer->identity($request, $source, $query->identity);
        $ready = $this->store->findReady($identity);
        if ($ready !== null) {
            return $ready;
        }

        $write = $this->materializer->materialize(
            Str::ulid()->toBase32(),
            $request,
            $source,
            $results,
            $eventsByLine,
            $query,
            $progress,
        );

        return $this->store->resolveReady($identity, $write);
    }
}
