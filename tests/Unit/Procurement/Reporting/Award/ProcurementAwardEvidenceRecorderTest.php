<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Award;

use App\BusinessModules\Features\Procurement\Reporting\Award\Contracts\ProcurementAwardEvidenceStore;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardCandidateEvidence;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardEvidenceEvent;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardManifest;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardPolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardSelectionFact;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardCompleteness;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardEventType;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardEvidenceRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementTransactionBoundary;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProcurementAwardEvidenceRecorderTest extends TestCase
{
    public function test_direct_selection_commit_is_append_only_and_exact_replay_returns_same_fact(): void
    {
        $store = new InMemoryAwardEvidenceStore;
        $recorder = new ProcurementAwardEvidenceRecorder($store, new AwardTransactionBoundary(true));
        $selection = $recorder->captureSelection($this->selection('selected'));
        $replay = $recorder->captureSelection($this->selection('selected'));
        $commit = $recorder->commit(
            decisionId: 60,
            purchaseOrderId: 70,
            acceptedProposalId: 10,
            acceptedProposalVersionId: 11,
            occurredAt: new DateTimeImmutable('2026-08-01T10:01:00.000000+00:00'),
            actorId: 90,
        );

        self::assertSame($selection->eventId, $replay->eventId);
        self::assertSame(ProcurementAwardEventType::COMPARISON_CAPTURED, $selection->eventType);
        self::assertSame(ProcurementAwardEventType::AWARD_COMMITTED, $commit->eventType);
        self::assertSame($selection->eventId, $commit->predecessorEventId);
        self::assertSame(1, $selection->decisionRevision);
        self::assertSame(2, $commit->eventSequence);
        self::assertCount(2, $store->eventsForDecision(60));
    }

    public function test_approval_path_persists_resolution_before_commit_and_reject_blocks_commit(): void
    {
        $approvedStore = new InMemoryAwardEvidenceStore;
        $approved = new ProcurementAwardEvidenceRecorder($approvedStore, new AwardTransactionBoundary(true));
        $selection = $approved->captureSelection($this->selection('approval_required'));
        $approval = $approved->approve(
            decisionId: 60,
            occurredAt: new DateTimeImmutable('2026-08-01T10:02:00.123456+00:00'),
            actorId: 91,
        );
        $commit = $approved->commit(
            decisionId: 60,
            purchaseOrderId: 70,
            acceptedProposalId: 10,
            acceptedProposalVersionId: 11,
            occurredAt: new DateTimeImmutable('2026-08-01T10:03:00.000000+00:00'),
            actorId: 91,
        );

        self::assertSame($selection->eventId, $approval->predecessorEventId);
        self::assertSame($approval->eventId, $commit->predecessorEventId);
        self::assertSame('2026-08-01T10:02:00.123456Z', $approval->occurredAtUtc());

        $rejectedStore = new InMemoryAwardEvidenceStore;
        $rejected = new ProcurementAwardEvidenceRecorder($rejectedStore, new AwardTransactionBoundary(true));
        $rejected->captureSelection($this->selection('approval_required'));
        $rejected->reject(60, new DateTimeImmutable('2026-08-01T10:02:00+00:00'), 92);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('procurement_award_commit_transition_invalid');
        $rejected->commit(
            60,
            70,
            10,
            11,
            new DateTimeImmutable('2026-08-01T10:03:00+00:00'),
            92,
        );
    }

    public function test_reselection_supersedes_uncommitted_revision_without_mutating_old_capture(): void
    {
        $store = new InMemoryAwardEvidenceStore;
        $recorder = new ProcurementAwardEvidenceRecorder($store, new AwardTransactionBoundary(true));
        $first = $recorder->captureSelection($this->selection('approval_required'));
        $secondFact = $this->selection('selected', proposalId: 20, proposalVersionId: 21);
        $second = $recorder->captureSelection($secondFact);

        $events = $store->eventsForDecision(60);
        self::assertSame([
            ProcurementAwardEventType::COMPARISON_CAPTURED,
            ProcurementAwardEventType::SELECTION_SUPERSEDED,
            ProcurementAwardEventType::COMPARISON_CAPTURED,
        ], array_map(static fn (ProcurementAwardEvidenceEvent $event): ProcurementAwardEventType => $event->eventType, $events));
        self::assertSame(1, $first->decisionRevision);
        self::assertSame(2, $second->decisionRevision);
        self::assertSame($first->sourceHash, $events[0]->sourceHash);
    }

    public function test_committed_selection_cannot_be_replayed_or_replaced(): void
    {
        $store = new InMemoryAwardEvidenceStore;
        $recorder = new ProcurementAwardEvidenceRecorder($store, new AwardTransactionBoundary(true));
        $recorder->captureSelection($this->selection('selected'));
        $recorder->commit(
            60,
            70,
            10,
            11,
            new DateTimeImmutable('2026-08-01T10:01:00+00:00'),
            90,
        );

        try {
            $recorder->captureSelection($this->selection('selected'));
            self::fail('Committed selection replay must fail closed.');
        } catch (LogicException $exception) {
            self::assertSame('procurement_award_selection_replay_transition_conflict', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('procurement_award_selection_transition_invalid');
        $recorder->captureSelection($this->selection('selected', proposalId: 20, proposalVersionId: 21));
    }

    public function test_reason_is_reduced_to_presence_length_and_digest(): void
    {
        $fact = $this->selection('approval_required', reason: "  Не самая низкая цена \n по сроку  ");

        self::assertTrue($fact->reasonPresent);
        self::assertSame(29, $fact->reasonNormalizedLength);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $fact->reasonDigest);
        self::assertStringNotContainsString('цена', json_encode($fact->canonicalPayload(), JSON_UNESCAPED_UNICODE));
    }

    public function test_source_write_requires_owner_transaction(): void
    {
        $recorder = new ProcurementAwardEvidenceRecorder(
            new InMemoryAwardEvidenceStore,
            new AwardTransactionBoundary(false),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('procurement_award_owner_transaction_required');

        $recorder->captureSelection($this->selection('selected'));
    }

    public function test_store_conflicting_replay_is_fail_closed(): void
    {
        $store = new InMemoryAwardEvidenceStore;
        $recorder = new ProcurementAwardEvidenceRecorder($store, new AwardTransactionBoundary(true));
        $event = $recorder->captureSelection($this->selection('selected'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('procurement_award_evidence_idempotency_conflict');

        $store->append($event->withSourceHash(str_repeat('f', 64)));
    }

    public function test_resolution_replay_with_different_actor_or_time_fails_closed(): void
    {
        $store = new InMemoryAwardEvidenceStore;
        $recorder = new ProcurementAwardEvidenceRecorder($store, new AwardTransactionBoundary(true));
        $recorder->captureSelection($this->selection('approval_required'));
        $recorder->approve(60, new DateTimeImmutable('2026-08-01T10:02:00+00:00'), 91);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('procurement_award_evidence_idempotency_conflict');

        $recorder->approve(60, new DateTimeImmutable('2026-08-01T10:02:01+00:00'), 92);
    }

    public function test_source_event_rolls_back_with_the_same_owner_transaction(): void
    {
        $journal = new TransactionalAwardEvidenceStore;
        $recorder = new ProcurementAwardEvidenceRecorder($journal, $journal);

        try {
            $journal->within(function () use ($recorder): void {
                $recorder->captureSelection($this->selection('selected'));

                throw new RuntimeException('owner_mutation_failed');
            });
            self::fail('Owner rollback must be propagated.');
        } catch (RuntimeException $exception) {
            self::assertSame('owner_mutation_failed', $exception->getMessage());
        }

        self::assertSame([], $journal->eventsForDecision(60));
    }

    private function selection(
        string $status,
        int $proposalId = 10,
        int $proposalVersionId = 11,
        ?string $reason = null,
    ): ProcurementAwardSelectionFact {
        return ProcurementAwardSelectionFact::create(
            organizationId: 1,
            projectId: 2,
            purchaseRequestId: 3,
            supplierRequestId: 4,
            supplierRequestVersionId: 5,
            supplierRequestVersionHash: str_repeat('b', 64),
            decisionId: 60,
            selectedStatus: $status,
            occurredAt: new DateTimeImmutable('2026-08-01T10:00:00.000000+00:00'),
            actorId: 90,
            manifest: $this->manifest($proposalId, $proposalVersionId),
            policy: ProcurementAwardPolicyDefinition::v1(),
            reason: $reason,
        );
    }

    private function manifest(int $selectedProposalId, int $selectedVersionId): ProcurementAwardManifest
    {
        $candidates = [
            $this->candidate(10, 11, '100'),
            $this->candidate(20, 21, '120'),
        ];
        $selectedRank = $selectedProposalId === 10 ? 1 : 2;

        return new ProcurementAwardManifest(
            candidates: $candidates,
            completeness: ProcurementAwardCompleteness::COMPLETE,
            selectedProposalId: $selectedProposalId,
            selectedProposalVersionId: $selectedVersionId,
            cheapestProposalId: 10,
            cheapestProposalVersionId: 11,
            selectedRank: $selectedRank,
            cheapestRank: 1,
            quarantineCodes: [],
        );
    }

    private function candidate(int $proposalId, int $versionId, string $total): ProcurementAwardCandidateEvidence
    {
        return new ProcurementAwardCandidateEvidence(
            organizationId: 1,
            projectId: 2,
            purchaseRequestId: 3,
            supplierRequestId: 4,
            supplierRequestVersionId: 5,
            supplierRequestVersionHash: str_repeat('b', 64),
            proposalId: $proposalId,
            proposalVersionId: $versionId,
            supplierPartyId: 100 + $proposalId,
            proposalStatus: 'submitted',
            proposalValidUntil: '2026-08-31',
            versionContentHash: str_repeat('a', 64),
            subtotalAmount: $total,
            deliveryAmount: '0',
            vatAmount: '0',
            totalAmount: $total,
            comparisonTotal: $total,
            currency: 'RUB',
            vatMode: 'included',
            vatRate: '20',
            deliveryDueDate: '2026-08-10',
            leadTimeDays: 5,
            requestLineCoverage: [[
                'supplier_request_line_id' => 1,
                'required_quantity' => '1',
                'required_unit' => 'pcs',
                'covered_quantity' => '1',
                'covered_unit' => 'pcs',
                'covered' => true,
            ]],
            comparable: true,
            exclusionCodes: [],
        );
    }
}

final class InMemoryAwardEvidenceStore implements ProcurementAwardEvidenceStore
{
    private array $events = [];

    public function eventsForDecision(int $decisionId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (ProcurementAwardEvidenceEvent $event): bool => $event->decisionId === $decisionId,
        ));
    }

    public function append(ProcurementAwardEvidenceEvent $event): ProcurementAwardEvidenceEvent
    {
        foreach ($this->events as $existing) {
            if ($existing->decisionId === $event->decisionId
                && $existing->decisionRevision === $event->decisionRevision
                && $existing->eventType === $event->eventType) {
                if (! hash_equals($existing->sourceHash, $event->sourceHash)) {
                    throw new LogicException('procurement_award_evidence_idempotency_conflict');
                }

                return $existing;
            }
        }
        $this->events[] = $event;

        return $event;
    }
}

final readonly class AwardTransactionBoundary implements ProcurementTransactionBoundary
{
    public function __construct(private bool $active) {}

    public function isActive(): bool
    {
        return $this->active;
    }
}

final class TransactionalAwardEvidenceStore implements ProcurementAwardEvidenceStore, ProcurementTransactionBoundary
{
    private array $committed = [];

    private array $pending = [];

    private bool $active = false;

    public function within(callable $workflow): mixed
    {
        $this->active = true;
        try {
            $result = $workflow();
            $this->committed = [...$this->committed, ...$this->pending];

            return $result;
        } finally {
            $this->pending = [];
            $this->active = false;
        }
    }

    public function eventsForDecision(int $decisionId): array
    {
        $events = $this->active ? [...$this->committed, ...$this->pending] : $this->committed;

        return array_values(array_filter(
            $events,
            static fn (ProcurementAwardEvidenceEvent $event): bool => $event->decisionId === $decisionId,
        ));
    }

    public function append(ProcurementAwardEvidenceEvent $event): ProcurementAwardEvidenceEvent
    {
        foreach ([...$this->committed, ...$this->pending] as $existing) {
            if ($existing->decisionId === $event->decisionId
                && $existing->decisionRevision === $event->decisionRevision
                && $existing->eventType === $event->eventType) {
                if (! hash_equals($existing->sourceHash, $event->sourceHash)) {
                    throw new LogicException('procurement_award_evidence_idempotency_conflict');
                }

                return $existing;
            }
        }
        $this->pending[] = $event;

        return $event;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
