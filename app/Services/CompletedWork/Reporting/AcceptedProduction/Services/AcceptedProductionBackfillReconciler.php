<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\ContractPerformanceAct;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceBackfillLedger;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceEvent;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Models\ProductionAcceptanceOwnerVersion;
use Carbon\CarbonImmutable;

final readonly class AcceptedProductionBackfillReconciler
{
    public function reconcile(
        ContractPerformanceAct $act,
        CarbonImmutable $recognizedAt,
    ): array {
        $owner = ProductionAcceptanceOwnerVersion::query()
            ->where('organization_id', $act->contract->organization_id)
            ->where('performance_act_id', $act->id)
            ->where('event_type', 'accepted')
            ->where('effective_at', $recognizedAt)
            ->orderByDesc('version')
            ->first();
        if ($owner === null) {
            $this->recordGap($act, $recognizedAt, 'historical_membership_unprovable');

            return ['event_ids' => [], 'projected' => false];
        }

        $events = ProductionAcceptanceEvent::query()
            ->where('organization_id', $act->contract->organization_id)
            ->where('performance_act_id', $act->id)
            ->where('recognized_at', '<=', $recognizedAt)
            ->orderBy('source_line_type')
            ->orderBy('source_line_id')
            ->orderBy('transition_version')
            ->get()
            ->groupBy(static fn (ProductionAcceptanceEvent $event): string => implode(':', [
                (string) $event->source_line_type,
                (int) $event->source_line_id,
            ]));
        $eventIds = [];
        foreach ((array) $owner->members as $member) {
            $key = implode(':', [
                (string) ($member['source_line_type'] ?? ''),
                (int) ($member['source_line_id'] ?? 0),
            ]);
            $latest = $events->get($key)?->last();
            if (! $latest instanceof ProductionAcceptanceEvent || $latest->event_type !== 'accepted') {
                $this->recordGap($act, $recognizedAt, 'historical_event_unprovable');

                return ['event_ids' => [], 'projected' => false];
            }
            $eventIds[] = (int) $latest->id;
        }
        sort($eventIds, SORT_NUMERIC);
        $this->record($act, $recognizedAt, 'reconciled', 'immutable_owner_and_events_present');

        return ['event_ids' => $eventIds, 'projected' => true];
    }

    private function recordGap(
        ContractPerformanceAct $act,
        CarbonImmutable $recognizedAt,
        string $reason,
    ): void {
        $this->record($act, $recognizedAt, 'unprovable', $reason);
    }

    private function record(
        ContractPerformanceAct $act,
        CarbonImmutable $recognizedAt,
        string $status,
        string $reason,
    ): void {
        $identity = [
            'organization_id' => (int) $act->contract->organization_id,
            'performance_act_id' => (int) $act->id,
            'project_id' => (int) $act->project_id,
            'reason' => $reason,
            'recognized_at' => $recognizedAt->format(DATE_ATOM),
            'status' => $status,
        ];
        $sourceHash = hash('sha256', CanonicalJson::encode($identity));
        ProductionAcceptanceBackfillLedger::query()->firstOrCreate(
            [
                'organization_id' => $identity['organization_id'],
                'source_hash' => $sourceHash,
            ],
            [
                ...$identity,
                'recorded_at' => CarbonImmutable::now(),
            ],
        );
    }
}
