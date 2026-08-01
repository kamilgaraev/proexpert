<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Award;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Reporting\Award\Contracts\ProcurementAwardEvidenceStore;
use App\BusinessModules\Features\Procurement\Reporting\Award\Contracts\ProcurementAwardSelectionSource;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardEvidenceEvent;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardEventType;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardEvidenceRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardManifestBuilder;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardOwnerEventRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementTransactionBoundary;
use DateTimeImmutable;
use DomainException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProcurementAwardOwnerEventRecorderTest extends TestCase
{
    public function test_supplier_request_owner_boundary_records_selection_and_direct_commit(): void
    {
        $source = new AwardSelectionSource([$this->candidate(10, 11, '100'), $this->candidate(20, 21, '120')], [4]);
        $store = new OwnerAwardEvidenceStore;
        $owner = $this->owner($source, $store);
        $request = new SupplierRequest;
        $request->forceFill(['id' => 4, 'organization_id' => 1]);
        $prepared = $owner->prepareForSupplierRequest(
            $request,
            10,
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        );
        $decision = new SupplierProposalDecision;
        $decision->forceFill([
            'id' => 60,
            'organization_id' => 1,
            'supplier_request_id' => 4,
            'winning_supplier_proposal_id' => 10,
            'winning_supplier_proposal_version_id' => 11,
            'status' => 'selected',
        ]);
        $owner->selected($prepared, $decision, new DateTimeImmutable('2026-08-01T10:00:00+00:00'), 90, null);
        $version = new SupplierProposalVersion;
        $version->forceFill(['id' => 11]);
        $order = new PurchaseOrder;
        $order->forceFill([
            'id' => 70,
            'organization_id' => 1,
            'accepted_supplier_proposal_id' => 10,
            'accepted_supplier_proposal_version_id' => 11,
        ]);
        $owner->committed(
            $decision,
            $version,
            $order,
            new DateTimeImmutable('2026-08-01T10:01:00+00:00'),
            90,
        );

        self::assertSame([
            ProcurementAwardEventType::COMPARISON_CAPTURED,
            ProcurementAwardEventType::AWARD_COMMITTED,
        ], array_map(static fn (ProcurementAwardEvidenceEvent $event): ProcurementAwardEventType => $event->eventType, $store->eventsForDecision(60)));
        self::assertSame(4, $prepared->supplierRequestId);
        self::assertSame(11, $prepared->manifest->selectedProposalVersionId);
    }

    public function test_approval_owner_transition_records_exact_resolution_before_commit(): void
    {
        $source = new AwardSelectionSource([$this->candidate(10, 11, '100'), $this->candidate(20, 21, '120')], [4]);
        $store = new OwnerAwardEvidenceStore;
        $owner = $this->owner($source, $store);
        $request = new SupplierRequest;
        $request->forceFill(['id' => 4, 'organization_id' => 1]);
        $prepared = $owner->prepareForSupplierRequest(
            $request,
            10,
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        );
        $decision = $this->decision('approval_required');
        $owner->selected($prepared, $decision, new DateTimeImmutable('2026-08-01T10:00:00+00:00'), 90, null);
        $decision->status = 'approved';
        $owner->approved($decision, new DateTimeImmutable('2026-08-01T10:02:00.123456+00:00'), 91);
        $owner->committed(
            $decision,
            $this->acceptedVersion(),
            $this->order(),
            new DateTimeImmutable('2026-08-01T10:03:00+00:00'),
            91,
        );

        $events = $store->eventsForDecision(60);

        self::assertSame([
            ProcurementAwardEventType::COMPARISON_CAPTURED,
            ProcurementAwardEventType::AWARD_APPROVED,
            ProcurementAwardEventType::AWARD_COMMITTED,
        ], array_map(static fn (ProcurementAwardEvidenceEvent $event): ProcurementAwardEventType => $event->eventType, $events));
        self::assertSame('2026-08-01T10:02:00.123456Z', $events[1]->occurredAtUtc());
        self::assertSame($events[1]->eventId, $events[2]->predecessorEventId);
    }

    public function test_rejection_owner_transition_prevents_commit(): void
    {
        $source = new AwardSelectionSource([$this->candidate(10, 11, '100'), $this->candidate(20, 21, '120')], [4]);
        $store = new OwnerAwardEvidenceStore;
        $owner = $this->owner($source, $store);
        $request = new SupplierRequest;
        $request->forceFill(['id' => 4, 'organization_id' => 1]);
        $prepared = $owner->prepareForSupplierRequest(
            $request,
            10,
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        );
        $decision = $this->decision('approval_required');
        $owner->selected($prepared, $decision, new DateTimeImmutable('2026-08-01T10:00:00+00:00'), 90, null);
        $decision->status = 'rejected';
        $owner->rejected($decision, new DateTimeImmutable('2026-08-01T10:02:00+00:00'), 92);

        try {
            $owner->committed(
                $decision,
                $this->acceptedVersion(),
                $this->order(),
                new DateTimeImmutable('2026-08-01T10:03:00+00:00'),
                92,
            );
            self::fail('Rejected selection must not be committed.');
        } catch (LogicException $exception) {
            self::assertSame('procurement_award_commit_owner_state_mismatch', $exception->getMessage());
        }

        self::assertSame([
            ProcurementAwardEventType::COMPARISON_CAPTURED,
            ProcurementAwardEventType::AWARD_REJECTED,
        ], array_map(static fn (ProcurementAwardEvidenceEvent $event): ProcurementAwardEventType => $event->eventType, $store->eventsForDecision(60)));
    }

    public function test_purchase_request_owner_boundary_rejects_multiple_supplier_requests(): void
    {
        $source = new AwardSelectionSource([$this->candidate(10, 11, '100')], [4, 5]);
        $purchaseRequest = new PurchaseRequest;
        $purchaseRequest->forceFill(['id' => 3, 'organization_id' => 1]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('procurement_award_purchase_request_round_not_supported');

        $this->owner($source, new OwnerAwardEvidenceStore)->prepareForPurchaseRequest(
            $purchaseRequest,
            10,
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        );
    }

    public function test_selection_rejects_winner_pair_that_differs_from_persisted_decision(): void
    {
        $source = new AwardSelectionSource([$this->candidate(10, 11, '100')], [4]);
        $owner = $this->owner($source, new OwnerAwardEvidenceStore);
        $request = new SupplierRequest;
        $request->forceFill(['id' => 4, 'organization_id' => 1]);
        $prepared = $owner->prepareForSupplierRequest(
            $request,
            10,
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        );
        $decision = $this->decision('selected');
        $decision->winning_supplier_proposal_version_id = 21;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('procurement_award_decision_lineage_mismatch');

        $owner->selected($prepared, $decision, new DateTimeImmutable('2026-08-01T10:00:00+00:00'), 90, null);
    }

    private function owner(
        ProcurementAwardSelectionSource $source,
        ProcurementAwardEvidenceStore $store,
    ): ProcurementAwardOwnerEventRecorder {
        return new ProcurementAwardOwnerEventRecorder(
            new ProcurementAwardManifestBuilder,
            new ProcurementAwardEvidenceRecorder($store, new OwnerAwardTransactionBoundary),
            $source,
        );
    }

    private function candidate(int $proposalId, int $versionId, string $total): array
    {
        return [
            'organization_id' => 1,
            'project_id' => 2,
            'purchase_request_id' => 3,
            'supplier_request_id' => 4,
            'supplier_request_version_id' => 5,
            'supplier_request_version_hash' => str_repeat('b', 64),
            'proposal_id' => $proposalId,
            'proposal_version_id' => $versionId,
            'supplier_party_id' => 100 + $proposalId,
            'proposal_status' => 'submitted',
            'proposal_valid_until' => '2026-08-31',
            'selection_date' => '2026-08-01',
            'version_content_hash' => str_repeat('a', 64),
            'request_lines' => [['id' => 1, 'quantity' => '1', 'unit' => 'pcs']],
            'commercial_snapshot' => [
                'subtotal_amount' => $total,
                'delivery_amount' => '0',
                'vat_amount' => '0',
                'total_amount' => $total,
                'currency' => 'RUB',
                'vat_mode' => 'included',
                'vat_rate' => '20',
                'delivery_due_date' => '2026-08-10',
                'lead_time_days' => 5,
                'lines' => [[
                    'supplier_request_line_id' => 1,
                    'quantity' => '1',
                    'unit' => 'pcs',
                    'unit_price' => $total,
                    'total_amount' => $total,
                ]],
            ],
        ];
    }

    private function decision(string $status): SupplierProposalDecision
    {
        $decision = new SupplierProposalDecision;
        $decision->forceFill([
            'id' => 60,
            'organization_id' => 1,
            'supplier_request_id' => 4,
            'winning_supplier_proposal_id' => 10,
            'winning_supplier_proposal_version_id' => 11,
            'status' => $status,
        ]);

        return $decision;
    }

    private function acceptedVersion(): SupplierProposalVersion
    {
        $version = new SupplierProposalVersion;
        $version->forceFill(['id' => 11]);

        return $version;
    }

    private function order(): PurchaseOrder
    {
        $order = new PurchaseOrder;
        $order->forceFill([
            'id' => 70,
            'organization_id' => 1,
            'accepted_supplier_proposal_id' => 10,
            'accepted_supplier_proposal_version_id' => 11,
        ]);

        return $order;
    }
}

final readonly class AwardSelectionSource implements ProcurementAwardSelectionSource
{
    public function __construct(private array $rows, private array $supplierRequestIds) {}

    public function candidateRows(
        int $organizationId,
        int $supplierRequestId,
        DateTimeImmutable $occurredAt,
    ): array {
        return $this->rows;
    }

    public function supplierRequestIds(
        int $organizationId,
        int $purchaseRequestId,
        DateTimeImmutable $occurredAt,
    ): array {
        return $this->supplierRequestIds;
    }
}

final class OwnerAwardEvidenceStore implements ProcurementAwardEvidenceStore
{
    private array $events = [];

    public function eventsForDecision(int $decisionId): array
    {
        return array_values(array_filter($this->events, static fn (ProcurementAwardEvidenceEvent $event): bool => $event->decisionId === $decisionId));
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

final class OwnerAwardTransactionBoundary implements ProcurementTransactionBoundary
{
    public function isActive(): bool
    {
        return true;
    }
}
