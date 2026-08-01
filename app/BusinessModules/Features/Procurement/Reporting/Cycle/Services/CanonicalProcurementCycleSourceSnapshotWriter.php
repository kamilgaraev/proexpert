<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceSnapshotStore;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
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

    public function persist(ReportQuery $query): ReportSourceSnapshotHeader
    {
        $request = new ProcurementCycleSnapshotRequest(
            $query->scope,
            $query->filters->values,
            $query->asOf,
            null,
        );
        $source = $this->reader->read($request);
        $identity = $this->materializer->identity($request, $source);
        $ready = $this->store->findReady($identity);
        if ($ready !== null) {
            return $ready;
        }

        $results = [];
        foreach ($source->eventsByLine as $events) {
            $policyId = $events[0]->transition->policyVersionId;
            if ($policyId === null || ! isset($source->policiesById[$policyId])) {
                continue;
            }
            $results[] = $this->formula->calculate($events, $source->policiesById[$policyId], $query->asOf);
        }
        $write = $this->materializer->materialize(
            Str::ulid()->toBase32(),
            $request,
            $source,
            $results,
        );

        return $this->store->resolveReady($identity, $write);
    }
}
