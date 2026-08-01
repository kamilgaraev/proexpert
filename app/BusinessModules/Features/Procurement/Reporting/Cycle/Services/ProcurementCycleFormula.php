<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleEvent;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleLineResult;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleMetric;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicySnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementCycleStage;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProcurementCycleFormula
{
    private const STAGES = [
        'request_approval' => ['request_created', 'request_approved'],
        'solicitation' => ['request_approved', 'solicitation_sent'],
        'supplier_response' => ['solicitation_sent', 'supplier_responded'],
        'award' => ['supplier_responded', 'award_decided'],
        'order_dispatch' => ['award_decided', 'order_sent'],
        'first_receipt' => ['order_sent', 'first_receipt'],
        'full_receipt' => ['first_receipt', 'fully_received'],
    ];

    private const BOUNDARIES = [
        'request_created',
        'request_approved',
        'solicitation_sent',
        'supplier_responded',
        'award_decided',
        'order_sent',
        'first_receipt',
        'fully_received',
    ];

    public function __construct(private ProcurementBusinessCalendar $calendar) {}

    public function calculate(
        array $events,
        ProcurementCyclePolicySnapshot $policy,
        DateTimeImmutable $asOf,
    ): ProcurementCycleLineResult {
        $events = $this->prepareEvents($events, $policy, $asOf);
        $first = $events[0]->transition;
        $boundaries = $this->firstBoundaries($events);
        $cancelled = $this->firstEvent($events, ProcurementProcessEventCode::CANCELLED);
        $gaps = $this->sourceGaps($events, $boundaries);

        if ($this->isNonmonotonic($boundaries, $cancelled)) {
            $gaps[] = 'nonmonotonic_event_sequence';
            $gaps = $this->uniqueSorted($gaps);

            return $this->result(
                events: $events,
                policy: $policy,
                boundaries: $boundaries,
                cancelled: $cancelled,
                first: $first,
                stageMetrics: $this->emptyMetrics($policy, 'nonmonotonic_event_sequence'),
                outcome: 'invalid_source',
                currentStage: null,
                openAgeSeconds: null,
                totalCycleSeconds: null,
                timeToCancellationSeconds: null,
                totalSlaEligible: false,
                totalSlaMet: null,
                qualityStatus: 'INVALID_SOURCE',
                gaps: $gaps,
            );
        }

        $stageMetrics = $this->stageMetrics($boundaries, $cancelled, $policy);
        $created = $boundaries[ProcurementProcessEventCode::REQUEST_CREATED->value] ?? null;
        $completed = $boundaries[ProcurementProcessEventCode::FULLY_RECEIVED->value] ?? null;
        $missingRequestCreated = $created === null;
        $cancelledBeforeCompletion = $cancelled !== null
            && ($completed === null || $this->compareEvents($cancelled, $completed) < 0);
        $outcome = $missingRequestCreated
            ? 'incomplete'
            : ($cancelledBeforeCompletion ? 'cancelled' : ($completed !== null ? 'completed' : 'open'));
        $currentStage = $outcome === 'open' ? $this->currentStage($boundaries) : null;
        $openAgeSeconds = $missingRequestCreated ? null : $this->openAgeSeconds($currentStage, $boundaries, $asOf, $policy);
        $totalCycleSeconds = ! $missingRequestCreated && $completed !== null && ! $cancelledBeforeCompletion
            ? $this->duration($created, $completed, $policy)
            : null;
        $timeToCancellationSeconds = ! $missingRequestCreated && $cancelledBeforeCompletion
            ? $this->duration($created, $cancelled, $policy)
            : null;
        $totalSlaEligible = $totalCycleSeconds !== null;
        $qualityStatus = $gaps === [] ? 'FULL' : 'PARTIAL';

        return $this->result(
            events: $events,
            policy: $policy,
            boundaries: $boundaries,
            cancelled: $cancelled,
            first: $first,
            stageMetrics: $stageMetrics,
            outcome: $outcome,
            currentStage: $currentStage,
            openAgeSeconds: $openAgeSeconds,
            totalCycleSeconds: $totalCycleSeconds,
            timeToCancellationSeconds: $timeToCancellationSeconds,
            totalSlaEligible: $totalSlaEligible,
            totalSlaMet: $totalSlaEligible ? $totalCycleSeconds <= $policy->definition->totalSlaSeconds : null,
            qualityStatus: $qualityStatus,
            gaps: $gaps,
        );
    }

    private function prepareEvents(
        array $events,
        ProcurementCyclePolicySnapshot $policy,
        DateTimeImmutable $asOf,
    ): array {
        $events = array_values(array_filter(
            $events,
            static fn (mixed $event): bool => $event instanceof ProcurementCycleEvent
                && $event->transition->occurredAt <= $asOf,
        ));
        if ($events === []) {
            throw new InvalidArgumentException('procurement_cycle_formula_events_required');
        }

        usort($events, fn (ProcurementCycleEvent $left, ProcurementCycleEvent $right): int => $this->compareEvents($left, $right));
        $first = $events[0]->transition;
        foreach ($events as $event) {
            $transition = $event->transition;
            if ($transition->organizationId !== $first->organizationId
                || $transition->projectId !== $first->projectId
                || $transition->purchaseRequestId !== $first->purchaseRequestId
                || $transition->purchaseRequestLineId !== $first->purchaseRequestLineId) {
                throw new InvalidArgumentException('procurement_cycle_formula_lineage_mismatch');
            }
            if ($transition->policyVersionId !== $policy->versionId
                || $transition->policyHash !== $policy->canonicalHash
                || $transition->calendarVersion !== $policy->definition->calendarVersion
                || $transition->calendarHash !== $policy->definition->calendarHash()) {
                throw new InvalidArgumentException('procurement_cycle_formula_policy_mismatch');
            }
        }
        if ($first->organizationId !== $policy->definition->organizationId
            || ($policy->definition->projectId !== null && $first->projectId !== $policy->definition->projectId)) {
            throw new InvalidArgumentException('procurement_cycle_formula_policy_scope_mismatch');
        }

        return $events;
    }

    private function firstBoundaries(array $events): array
    {
        $boundaries = [];
        foreach ($events as $event) {
            $code = $event->transition->eventCode->value;
            if (in_array($code, self::BOUNDARIES, true) && ! isset($boundaries[$code])) {
                $boundaries[$code] = $event;
            }
        }

        return $boundaries;
    }

    private function firstEvent(array $events, ProcurementProcessEventCode $code): ?ProcurementCycleEvent
    {
        foreach ($events as $event) {
            if ($event->transition->eventCode === $code) {
                return $event;
            }
        }

        return null;
    }

    private function isNonmonotonic(array $boundaries, ?ProcurementCycleEvent $cancelled): bool
    {
        $previous = null;
        foreach (self::BOUNDARIES as $code) {
            $event = $boundaries[$code] ?? null;
            if ($event === null) {
                continue;
            }
            if ($previous !== null && $this->compareEvents($event, $previous) < 0) {
                return true;
            }
            $previous = $event;
        }

        $created = $boundaries[ProcurementProcessEventCode::REQUEST_CREATED->value] ?? null;

        return $cancelled !== null && $created !== null && $this->compareEvents($cancelled, $created) < 0;
    }

    private function sourceGaps(array $events, array $boundaries): array
    {
        $gaps = [];
        if (! isset($boundaries[ProcurementProcessEventCode::REQUEST_CREATED->value])) {
            $gaps[] = 'missing_request_created_event';
        }
        foreach ($events as $event) {
            $values = $event->transition->dimensionSnapshot->values;
            if (($values['quality_status'] ?? 'FULL') === 'PARTIAL') {
                $gaps = [...$gaps, ...($values['gap_codes'] ?? [])];
            }
        }

        foreach (self::BOUNDARIES as $index => $code) {
            if (isset($boundaries[$code])) {
                continue;
            }
            foreach (array_slice(self::BOUNDARIES, $index + 1) as $laterCode) {
                if (isset($boundaries[$laterCode])) {
                    $gaps[] = 'missing_'.$code.'_event';
                    break;
                }
            }
        }

        return $this->uniqueSorted($gaps);
    }

    private function stageMetrics(
        array $boundaries,
        ?ProcurementCycleEvent $cancelled,
        ProcurementCyclePolicySnapshot $policy,
    ): array {
        $metrics = [];
        foreach (self::STAGES as $stage => [$startCode, $endCode]) {
            $start = $boundaries[$startCode] ?? null;
            $end = $boundaries[$endCode] ?? null;
            $cancelledBeforeEnd = $cancelled !== null
                && ($end === null || $this->compareEvents($cancelled, $end) < 0);
            $eligible = $start !== null && $end !== null && ! $cancelledBeforeEnd;
            $duration = $eligible ? $this->duration($start, $end, $policy) : null;
            $gapCode = null;
            if ($start === null && $this->hasBoundaryAtOrAfter($boundaries, $endCode)) {
                $gapCode = 'missing_'.$startCode.'_event';
            } elseif ($end === null && $this->hasBoundaryAfter($boundaries, $endCode)) {
                $gapCode = 'missing_'.$endCode.'_event';
            }
            $sla = $policy->definition->stageSlaSeconds[$stage];
            $metrics[$stage] = new ProcurementCycleMetric(
                startAt: $start?->transition->occurredAtUtc(),
                endAt: $end?->transition->occurredAtUtc(),
                durationSeconds: $duration,
                slaSeconds: $sla,
                eligible: $eligible,
                slaMet: $eligible ? $duration <= $sla : null,
                gapCode: $gapCode,
            );
        }

        return $metrics;
    }

    private function emptyMetrics(ProcurementCyclePolicySnapshot $policy, string $gapCode): array
    {
        $metrics = [];
        foreach (ProcurementCycleStage::cases() as $stage) {
            $metrics[$stage->value] = new ProcurementCycleMetric(
                startAt: null,
                endAt: null,
                durationSeconds: null,
                slaSeconds: $policy->definition->stageSlaSeconds[$stage->value],
                eligible: false,
                slaMet: null,
                gapCode: $gapCode,
            );
        }

        return $metrics;
    }

    private function currentStage(array $boundaries): ProcurementCycleStage
    {
        foreach (self::STAGES as $stage => [$startCode, $endCode]) {
            if (isset($boundaries[$startCode]) && ! isset($boundaries[$endCode])) {
                return ProcurementCycleStage::from($stage);
            }
            if (! isset($boundaries[$startCode])) {
                return ProcurementCycleStage::from($stage);
            }
        }

        return ProcurementCycleStage::FULL_RECEIPT;
    }

    private function openAgeSeconds(
        ?ProcurementCycleStage $stage,
        array $boundaries,
        DateTimeImmutable $asOf,
        ProcurementCyclePolicySnapshot $policy,
    ): ?int {
        if ($stage === null) {
            return null;
        }
        $startCode = self::STAGES[$stage->value][0];
        $start = $boundaries[$startCode] ?? null;

        return $start === null
            ? null
            : $this->calendar->businessSeconds($start->transition->occurredAt, $asOf, $policy->definition);
    }

    private function duration(
        ProcurementCycleEvent $start,
        ProcurementCycleEvent $end,
        ProcurementCyclePolicySnapshot $policy,
    ): int {
        return $this->calendar->businessSeconds(
            $start->transition->occurredAt,
            $end->transition->occurredAt,
            $policy->definition,
        );
    }

    private function result(
        array $events,
        ProcurementCyclePolicySnapshot $policy,
        array $boundaries,
        ?ProcurementCycleEvent $cancelled,
        object $first,
        array $stageMetrics,
        string $outcome,
        ?ProcurementCycleStage $currentStage,
        ?int $openAgeSeconds,
        ?int $totalCycleSeconds,
        ?int $timeToCancellationSeconds,
        bool $totalSlaEligible,
        ?bool $totalSlaMet,
        string $qualityStatus,
        array $gaps,
    ): ProcurementCycleLineResult {
        $created = $boundaries[ProcurementProcessEventCode::REQUEST_CREATED->value] ?? null;
        $completed = $boundaries[ProcurementProcessEventCode::FULLY_RECEIVED->value] ?? null;
        $outcomeEvent = $outcome === 'cancelled' ? $cancelled : ($outcome === 'completed' ? $completed : null);
        $dimensions = ($created ?? $events[0])->transition->dimensionSnapshot->values;
        $solicitedSupplierIds = [];
        foreach ($events as $event) {
            if ($event->transition->eventCode === ProcurementProcessEventCode::SOLICITATION_SENT
                && $event->transition->supplierPartyId !== null) {
                $solicitedSupplierIds[] = $event->transition->supplierPartyId;
            }
        }
        $solicitedSupplierIds = array_values(array_unique($solicitedSupplierIds));
        sort($solicitedSupplierIds, SORT_NUMERIC);
        $award = $boundaries[ProcurementProcessEventCode::AWARD_DECIDED->value] ?? null;
        $awardDimensions = $award?->transition->dimensionSnapshot->values ?? [];
        $boundaryTimes = [];
        foreach (self::BOUNDARIES as $code) {
            $boundaryTimes[$code] = isset($boundaries[$code])
                ? $boundaries[$code]->transition->occurredAtUtc()
                : null;
        }
        $boundaryTimes['cancelled'] = $cancelled?->transition->occurredAtUtc();
        $timezone = $policy->definition->timezoneObject;

        return new ProcurementCycleLineResult(
            organizationId: $first->organizationId,
            projectId: $first->projectId,
            purchaseRequestId: $first->purchaseRequestId,
            purchaseRequestLineId: $first->purchaseRequestLineId,
            dimensions: $dimensions,
            solicitedSupplierIds: $solicitedSupplierIds,
            awardedSupplierPartyId: $awardDimensions['awarded_supplier_party_id']
                ?? $award?->transition->supplierPartyId,
            awardedAmount: $awardDimensions['awarded_amount'] ?? null,
            currency: $awardDimensions['currency'] ?? null,
            outcome: $outcome,
            currentStage: $currentStage,
            startCohortDate: ($created ?? $events[0])->transition->occurredAt->setTimezone($timezone)->format('Y-m-d'),
            outcomeCohortDate: $outcomeEvent?->transition->occurredAt->setTimezone($timezone)->format('Y-m-d'),
            boundaryTimes: $boundaryTimes,
            stageMetrics: $stageMetrics,
            openAgeSeconds: $openAgeSeconds,
            totalCycleSeconds: $totalCycleSeconds,
            timeToCancellationSeconds: $timeToCancellationSeconds,
            totalSlaEligible: $totalSlaEligible,
            totalSlaMet: $totalSlaMet,
            qualityStatus: $qualityStatus,
            gapCodes: $this->uniqueSorted($gaps),
        );
    }

    private function compareEvents(ProcurementCycleEvent $left, ProcurementCycleEvent $right): int
    {
        $time = $left->transition->occurredAt <=> $right->transition->occurredAt;

        return $time !== 0 ? $time : $left->id <=> $right->id;
    }

    private function hasBoundaryAtOrAfter(array $boundaries, string $code): bool
    {
        $index = array_search($code, self::BOUNDARIES, true);
        foreach (array_slice(self::BOUNDARIES, (int) $index) as $candidate) {
            if (isset($boundaries[$candidate])) {
                return true;
            }
        }

        return false;
    }

    private function hasBoundaryAfter(array $boundaries, string $code): bool
    {
        $index = array_search($code, self::BOUNDARIES, true);
        foreach (array_slice(self::BOUNDARIES, (int) $index + 1) as $candidate) {
            if (isset($boundaries[$candidate])) {
                return true;
            }
        }

        return false;
    }

    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
