<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Options;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Services\CompletedWork\Reporting\AcceptedProduction\DTO\AcceptedProductionUniverseEntry;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionEventUniverse;
use InvalidArgumentException;

final readonly class CanonicalAcceptedProductionOptionsSource implements AcceptedProductionOptionsSource
{
    public function __construct(private AcceptedProductionEventUniverse $universe) {}

    public function snapshot(ReportScope $scope, ReportQuery $query): AcceptedProductionOptionsSourceSnapshot
    {
        try {
            $stream = $this->universe->stream($scope, $query);
            if ($stream->gapCount() > 0) {
                return AcceptedProductionOptionsSourceSnapshot::unavailable('source_incomplete');
            }

            $workIds = [];
            $actIds = [];
            $contractorIds = [];
            $unitCodes = [];
            $zones = [];
            $statuses = [];

            foreach ($stream->entries() as $entry) {
                if (! $entry instanceof AcceptedProductionUniverseEntry) {
                    return AcceptedProductionOptionsSourceSnapshot::unavailable('source_unavailable');
                }
                $event = $entry->latestEvent();
                if (! $this->valid($entry, $event, $scope)) {
                    return AcceptedProductionOptionsSourceSnapshot::unavailable('source_unavailable');
                }

                $workIds[] = (int) $event->work_id;
                $actIds[] = (int) $event->performance_act_id;
                if ($event->contractor_id !== null) {
                    $contractorIds[] = (int) $event->contractor_id;
                }
                $unitCodes[] = trim((string) $event->unit_code);
                if ($event->zone !== null && trim((string) $event->zone) !== '') {
                    $zones[] = trim((string) $event->zone);
                }
                $statuses[] = (string) $event->event_type;
            }

            return AcceptedProductionOptionsSourceSnapshot::available(
                $workIds,
                $actIds,
                $contractorIds,
                $unitCodes,
                $zones,
                $statuses,
            );
        } catch (InvalidArgumentException) {
            return AcceptedProductionOptionsSourceSnapshot::unavailable('source_unavailable');
        }
    }

    private function valid(
        AcceptedProductionUniverseEntry $entry,
        ?ProductionAcceptanceEvent $event,
        ReportScope $scope,
    ): bool {
        if ($event === null
            || (int) $event->work_id < 1
            || (int) $event->performance_act_id < 1
            || (int) $event->project_id < 1
            || ! in_array((int) $event->project_id, $scope->projectIds, true)
            || trim((string) $event->unit_code) === ''
            || ! in_array((string) $event->event_type, ['accepted', 'reversed'], true)
            || (int) ($entry->candidate['work_id'] ?? 0) !== (int) $event->work_id
            || (int) ($entry->candidate['performance_act_id'] ?? 0) !== (int) $event->performance_act_id
            || (int) ($entry->candidate['project_id'] ?? 0) !== (int) $event->project_id
            || (string) ($entry->candidate['event_type'] ?? '') !== (string) $event->event_type
        ) {
            return false;
        }

        return $event->contractor_id === null || (int) $event->contractor_id > 0;
    }
}
