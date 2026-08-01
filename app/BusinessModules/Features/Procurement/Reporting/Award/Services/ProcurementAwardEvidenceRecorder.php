<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Features\Procurement\Reporting\Award\Contracts\ProcurementAwardEvidenceStore;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardEvidenceEvent;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardSelectionFact;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardEventType;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementTransactionBoundary;
use DateTimeImmutable;
use LogicException;

final class ProcurementAwardEvidenceRecorder
{
    public function __construct(
        private readonly ProcurementAwardEvidenceStore $store,
        private readonly ProcurementTransactionBoundary $transactionBoundary,
    ) {}

    public function captureSelection(ProcurementAwardSelectionFact $fact): ProcurementAwardEvidenceEvent
    {
        $this->assertTransaction();
        $events = $this->events($fact->decisionId);
        $latestSelection = $this->latestSelection($events);
        if ($latestSelection !== null && hash_equals($latestSelection->selectionFingerprint, $fact->fingerprint())) {
            if ($this->latestForRevision($events, $latestSelection->decisionRevision)->eventType
                === ProcurementAwardEventType::COMPARISON_CAPTURED) {
                return $latestSelection;
            }

            throw new LogicException('procurement_award_selection_replay_transition_conflict');
        }

        $sequence = $this->nextSequence($events);
        if ($latestSelection !== null) {
            $latestForRevision = $this->latestForRevision($events, $latestSelection->decisionRevision);
            if (! in_array($latestForRevision->eventType, [
                ProcurementAwardEventType::COMPARISON_CAPTURED,
                ProcurementAwardEventType::AWARD_APPROVED,
            ], true)) {
                throw new LogicException('procurement_award_selection_transition_invalid');
            }
            $this->assertTime($latestForRevision, $fact->occurredAt);
            $superseded = $latestSelection->outcome(
                eventType: ProcurementAwardEventType::SELECTION_SUPERSEDED,
                eventSequence: $sequence,
                occurredAt: $fact->occurredAt,
                actorId: $fact->actorId,
                predecessorEventId: $latestForRevision->eventId,
            );
            $this->store->append($superseded);
            $events[] = $superseded;
            $sequence++;
        }

        $revision = $latestSelection === null ? 1 : $latestSelection->decisionRevision + 1;

        return $this->store->append(ProcurementAwardEvidenceEvent::fromSelection($fact, $revision, $sequence));
    }

    public function approve(int $decisionId, DateTimeImmutable $occurredAt, ?int $actorId): ProcurementAwardEvidenceEvent
    {
        return $this->resolution(
            decisionId: $decisionId,
            eventType: ProcurementAwardEventType::AWARD_APPROVED,
            occurredAt: $occurredAt,
            actorId: $actorId,
        );
    }

    public function reject(int $decisionId, DateTimeImmutable $occurredAt, ?int $actorId): ProcurementAwardEvidenceEvent
    {
        return $this->resolution(
            decisionId: $decisionId,
            eventType: ProcurementAwardEventType::AWARD_REJECTED,
            occurredAt: $occurredAt,
            actorId: $actorId,
        );
    }

    public function commit(
        int $decisionId,
        int $purchaseOrderId,
        int $acceptedProposalId,
        int $acceptedProposalVersionId,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): ProcurementAwardEvidenceEvent {
        $this->assertTransaction();
        $events = $this->events($decisionId);
        $selection = $this->latestSelection($events);
        if ($selection === null
            || $selection->manifest->selectedProposalId !== $acceptedProposalId
            || $selection->manifest->selectedProposalVersionId !== $acceptedProposalVersionId) {
            throw new LogicException('procurement_award_commit_lineage_invalid');
        }

        $existing = $this->eventForRevision($events, $selection->decisionRevision, ProcurementAwardEventType::AWARD_COMMITTED);
        if ($existing !== null) {
            return $this->store->append($selection->outcome(
                eventType: ProcurementAwardEventType::AWARD_COMMITTED,
                eventSequence: $existing->eventSequence,
                occurredAt: $occurredAt,
                actorId: $actorId,
                predecessorEventId: $existing->predecessorEventId,
                purchaseOrderId: $purchaseOrderId,
            ));
        }

        $latest = $this->latestForRevision($events, $selection->decisionRevision);
        $allowed = $latest->eventType === ProcurementAwardEventType::AWARD_APPROVED
            || ($latest->eventType === ProcurementAwardEventType::COMPARISON_CAPTURED
                && $selection->selectedStatus === 'selected');
        if (! $allowed) {
            throw new LogicException('procurement_award_commit_transition_invalid');
        }
        $this->assertTime($latest, $occurredAt);

        return $this->store->append($selection->outcome(
            eventType: ProcurementAwardEventType::AWARD_COMMITTED,
            eventSequence: $this->nextSequence($events),
            occurredAt: $occurredAt,
            actorId: $actorId,
            predecessorEventId: $latest->eventId,
            purchaseOrderId: $purchaseOrderId,
        ));
    }

    private function resolution(
        int $decisionId,
        ProcurementAwardEventType $eventType,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): ProcurementAwardEvidenceEvent {
        $this->assertTransaction();
        $events = $this->events($decisionId);
        $selection = $this->latestSelection($events);
        if ($selection === null || $selection->selectedStatus !== 'approval_required') {
            throw new LogicException('procurement_award_resolution_transition_invalid');
        }

        $existing = $this->eventForRevision($events, $selection->decisionRevision, $eventType);
        if ($existing !== null) {
            return $this->store->append($selection->outcome(
                eventType: $eventType,
                eventSequence: $existing->eventSequence,
                occurredAt: $occurredAt,
                actorId: $actorId,
                predecessorEventId: $existing->predecessorEventId,
            ));
        }

        $latest = $this->latestForRevision($events, $selection->decisionRevision);
        if ($latest->eventType !== ProcurementAwardEventType::COMPARISON_CAPTURED) {
            throw new LogicException('procurement_award_resolution_transition_invalid');
        }
        $this->assertTime($latest, $occurredAt);

        return $this->store->append($selection->outcome(
            eventType: $eventType,
            eventSequence: $this->nextSequence($events),
            occurredAt: $occurredAt,
            actorId: $actorId,
            predecessorEventId: $selection->eventId,
        ));
    }

    private function events(int $decisionId): array
    {
        $events = $this->store->eventsForDecision($decisionId);
        usort($events, static fn (ProcurementAwardEvidenceEvent $left, ProcurementAwardEvidenceEvent $right): int => $left->eventSequence <=> $right->eventSequence);

        return $events;
    }

    private function latestSelection(array $events): ?ProcurementAwardEvidenceEvent
    {
        $selections = array_values(array_filter(
            $events,
            static fn (ProcurementAwardEvidenceEvent $event): bool => $event->eventType === ProcurementAwardEventType::COMPARISON_CAPTURED,
        ));

        return $selections === [] ? null : $selections[array_key_last($selections)];
    }

    private function latestForRevision(array $events, int $revision): ProcurementAwardEvidenceEvent
    {
        $revisionEvents = array_values(array_filter(
            $events,
            static fn (ProcurementAwardEvidenceEvent $event): bool => $event->decisionRevision === $revision,
        ));
        if ($revisionEvents === []) {
            throw new LogicException('procurement_award_revision_missing');
        }

        return $revisionEvents[array_key_last($revisionEvents)];
    }

    private function eventForRevision(
        array $events,
        int $revision,
        ProcurementAwardEventType $type,
    ): ?ProcurementAwardEvidenceEvent {
        foreach ($events as $event) {
            if ($event->decisionRevision === $revision && $event->eventType === $type) {
                return $event;
            }
        }

        return null;
    }

    private function nextSequence(array $events): int
    {
        return $events === [] ? 1 : max(array_map(
            static fn (ProcurementAwardEvidenceEvent $event): int => $event->eventSequence,
            $events,
        )) + 1;
    }

    private function assertTime(ProcurementAwardEvidenceEvent $predecessor, DateTimeImmutable $occurredAt): void
    {
        if ($occurredAt < $predecessor->occurredAt) {
            throw new LogicException('procurement_award_event_time_regression');
        }
    }

    private function assertTransaction(): void
    {
        if (! $this->transactionBoundary->isActive()) {
            throw new LogicException('procurement_award_owner_transaction_required');
        }
    }
}
