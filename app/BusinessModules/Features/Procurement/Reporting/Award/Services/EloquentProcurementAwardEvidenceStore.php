<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Award\Contracts\ProcurementAwardEvidenceStore;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardCandidateEvidence;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardEvidenceEvent;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardManifest;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardPolicyDefinition;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardCompleteness;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardEventType;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EloquentProcurementAwardEvidenceStore implements ProcurementAwardEvidenceStore
{
    public function eventsForDecision(int $decisionId): array
    {
        $rows = DB::table('procurement_award_evidence_events')
            ->where('decision_id', $decisionId)
            ->orderBy('event_sequence')
            ->lockForUpdate()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $candidatesByEvent = DB::table('procurement_award_evidence_candidates')
            ->whereIn('event_id', $rows->pluck('id')->all())
            ->orderBy('event_id')
            ->orderBy('ordinal')
            ->get()
            ->groupBy('event_id')
            ->all();

        return $rows->map(fn (object $row): ProcurementAwardEvidenceEvent => $this->hydrate(
            $row,
            $candidatesByEvent[$row->id] ?? [],
        ))->all();
    }

    public function append(ProcurementAwardEvidenceEvent $event): ProcurementAwardEvidenceEvent
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('procurement_award_owner_transaction_required');
        }

        $this->assertPolicyPin($event);
        $identity = [
            'decision_id' => $event->decisionId,
            'decision_revision' => $event->decisionRevision,
            'event_type' => $event->eventType->value,
        ];
        $existing = DB::table('procurement_award_evidence_events')
            ->where($identity)
            ->lockForUpdate()
            ->first(['id', 'source_hash']);
        if ($existing !== null) {
            return $this->exactReplay($event, $existing);
        }

        $inserted = DB::table('procurement_award_evidence_events')->insertOrIgnore([
            'id' => $event->eventId,
            'organization_id' => $event->organizationId,
            'project_id' => $event->projectId,
            'purchase_request_id' => $event->purchaseRequestId,
            'supplier_request_id' => $event->supplierRequestId,
            'supplier_request_version_id' => $event->supplierRequestVersionId,
            'supplier_request_version_hash' => $event->supplierRequestVersionHash,
            'decision_id' => $event->decisionId,
            'decision_revision' => $event->decisionRevision,
            'event_sequence' => $event->eventSequence,
            'event_type' => $event->eventType->value,
            'occurred_at' => $event->occurredAtUtc(),
            'actor_id' => $event->actorId,
            'selected_status' => $event->selectedStatus,
            'policy_id' => $event->policy->policyId,
            'policy_version' => $event->policy->version,
            'policy_hash' => $event->policy->canonicalHash(),
            'selection_fingerprint' => $event->selectionFingerprint,
            'source_hash' => $event->sourceHash,
            'manifest_hash' => $event->manifest->contentHash(),
            'candidate_count' => $event->manifest->candidateCount,
            'comparable_count' => $event->manifest->comparableCount,
            'completeness' => $event->manifest->completeness->value,
            'quarantine_codes' => CanonicalJson::encode($event->manifest->quarantineCodes),
            'selected_proposal_id' => $event->manifest->selectedProposalId,
            'selected_proposal_version_id' => $event->manifest->selectedProposalVersionId,
            'cheapest_proposal_id' => $event->manifest->cheapestProposalId,
            'cheapest_proposal_version_id' => $event->manifest->cheapestProposalVersionId,
            'selected_rank' => $event->manifest->selectedRank,
            'cheapest_rank' => $event->manifest->cheapestRank,
            'reason_present' => $event->reasonPresent,
            'reason_normalized_length' => $event->reasonNormalizedLength,
            'reason_digest' => $event->reasonDigest,
            'predecessor_event_id' => $event->predecessorEventId,
            'purchase_order_id' => $event->purchaseOrderId,
            'created_at' => now(new DateTimeZone('UTC')),
        ]);

        if ($inserted !== 1) {
            $existing = DB::table('procurement_award_evidence_events')
                ->where($identity)
                ->lockForUpdate()
                ->first(['id', 'source_hash']);
            if ($existing === null) {
                throw new LogicException('procurement_award_evidence_insert_conflict');
            }

            return $this->exactReplay($event, $existing);
        }

        foreach ($event->manifest->candidates as $ordinal => $candidate) {
            $this->insertCandidate($event, $candidate, $ordinal + 1);
        }

        return $event;
    }

    private function insertCandidate(
        ProcurementAwardEvidenceEvent $event,
        ProcurementAwardCandidateEvidence $candidate,
        int $ordinal,
    ): void {
        DB::table('procurement_award_evidence_candidates')->insert([
            'event_id' => $event->eventId,
            'ordinal' => $ordinal,
            'organization_id' => $candidate->organizationId,
            'project_id' => $candidate->projectId,
            'purchase_request_id' => $candidate->purchaseRequestId,
            'supplier_request_id' => $candidate->supplierRequestId,
            'supplier_request_version_id' => $candidate->supplierRequestVersionId,
            'supplier_request_version_hash' => $candidate->supplierRequestVersionHash,
            'proposal_id' => $candidate->proposalId,
            'proposal_version_id' => $candidate->proposalVersionId,
            'supplier_party_id' => $candidate->supplierPartyId,
            'proposal_status' => $candidate->proposalStatus,
            'proposal_valid_until' => $candidate->proposalValidUntil,
            'version_content_hash' => $candidate->versionContentHash,
            'subtotal_amount' => $candidate->subtotalAmount,
            'delivery_amount' => $candidate->deliveryAmount,
            'vat_amount' => $candidate->vatAmount,
            'total_amount' => $candidate->totalAmount,
            'comparison_total' => $candidate->comparisonTotal,
            'currency' => $candidate->currency,
            'vat_mode' => $candidate->vatMode,
            'vat_rate' => $candidate->vatRate,
            'delivery_due_date' => $candidate->deliveryDueDate,
            'lead_time_days' => $candidate->leadTimeDays,
            'request_line_coverage' => CanonicalJson::encode($candidate->requestLineCoverage),
            'comparable' => $candidate->comparable,
            'exclusion_codes' => CanonicalJson::encode($candidate->exclusionCodes),
            'candidate_hash' => $candidate->contentHash(),
        ]);
    }

    private function assertPolicyPin(ProcurementAwardEvidenceEvent $event): void
    {
        $exists = DB::table('procurement_award_policy_versions')
            ->where('policy_id', $event->policy->policyId)
            ->where('version', $event->policy->version)
            ->where('policy_hash', $event->policy->canonicalHash())
            ->exists();
        if (! $exists) {
            throw new LogicException('procurement_award_policy_pin_missing');
        }
    }

    private function exactReplay(ProcurementAwardEvidenceEvent $event, object $existing): ProcurementAwardEvidenceEvent
    {
        if (! is_string($existing->source_hash) || ! hash_equals($existing->source_hash, $event->sourceHash)) {
            throw new LogicException('procurement_award_evidence_idempotency_conflict');
        }

        $row = DB::table('procurement_award_evidence_events')->where('id', $existing->id)->first();
        if ($row === null) {
            throw new LogicException('procurement_award_evidence_replay_missing');
        }

        return $this->hydrate($row);
    }

    /** @param iterable<object> $candidateRows */
    private function hydrate(object $row, ?iterable $candidateRows = null): ProcurementAwardEvidenceEvent
    {
        $candidateRows ??= DB::table('procurement_award_evidence_candidates')
            ->where('event_id', $row->id)
            ->orderBy('ordinal')
            ->get();
        $candidates = collect($candidateRows)->map(static fn (object $candidate): ProcurementAwardCandidateEvidence => new ProcurementAwardCandidateEvidence(
            organizationId: (int) $candidate->organization_id,
            projectId: $candidate->project_id === null ? null : (int) $candidate->project_id,
            purchaseRequestId: (int) $candidate->purchase_request_id,
            supplierRequestId: (int) $candidate->supplier_request_id,
            supplierRequestVersionId: $candidate->supplier_request_version_id === null
                ? null
                : (int) $candidate->supplier_request_version_id,
            supplierRequestVersionHash: $candidate->supplier_request_version_hash,
            proposalId: (int) $candidate->proposal_id,
            proposalVersionId: $candidate->proposal_version_id === null
                ? null
                : (int) $candidate->proposal_version_id,
            supplierPartyId: (int) $candidate->supplier_party_id,
            proposalStatus: $candidate->proposal_status,
            proposalValidUntil: $candidate->proposal_valid_until === null
                ? null
                : (string) $candidate->proposal_valid_until,
            versionContentHash: $candidate->version_content_hash,
            subtotalAmount: $candidate->subtotal_amount === null ? null : (string) $candidate->subtotal_amount,
            deliveryAmount: $candidate->delivery_amount === null ? null : (string) $candidate->delivery_amount,
            vatAmount: $candidate->vat_amount === null ? null : (string) $candidate->vat_amount,
            totalAmount: $candidate->total_amount === null ? null : (string) $candidate->total_amount,
            comparisonTotal: $candidate->comparison_total === null ? null : (string) $candidate->comparison_total,
            currency: $candidate->currency,
            vatMode: $candidate->vat_mode,
            vatRate: $candidate->vat_rate,
            deliveryDueDate: $candidate->delivery_due_date,
            leadTimeDays: $candidate->lead_time_days === null ? null : (int) $candidate->lead_time_days,
            requestLineCoverage: self::jsonArray($candidate->request_line_coverage),
            comparable: (bool) $candidate->comparable,
            exclusionCodes: self::jsonArray($candidate->exclusion_codes),
        ))->all();
        $manifest = new ProcurementAwardManifest(
            candidates: $candidates,
            completeness: ProcurementAwardCompleteness::from((string) $row->completeness),
            selectedProposalId: (int) $row->selected_proposal_id,
            selectedProposalVersionId: $row->selected_proposal_version_id === null
                ? null
                : (int) $row->selected_proposal_version_id,
            cheapestProposalId: $row->cheapest_proposal_id === null ? null : (int) $row->cheapest_proposal_id,
            cheapestProposalVersionId: $row->cheapest_proposal_version_id === null
                ? null
                : (int) $row->cheapest_proposal_version_id,
            selectedRank: $row->selected_rank === null ? null : (int) $row->selected_rank,
            cheapestRank: $row->cheapest_rank === null ? null : (int) $row->cheapest_rank,
            quarantineCodes: self::jsonArray($row->quarantine_codes),
        );
        $policy = ProcurementAwardPolicyDefinition::v1();
        if ($policy->policyId !== $row->policy_id
            || $policy->version !== (int) $row->policy_version
            || ! hash_equals($policy->canonicalHash(), (string) $row->policy_hash)) {
            throw new LogicException('procurement_award_policy_pin_mismatch');
        }

        return new ProcurementAwardEvidenceEvent(
            organizationId: (int) $row->organization_id,
            projectId: $row->project_id === null ? null : (int) $row->project_id,
            purchaseRequestId: (int) $row->purchase_request_id,
            supplierRequestId: (int) $row->supplier_request_id,
            supplierRequestVersionId: $row->supplier_request_version_id === null
                ? null
                : (int) $row->supplier_request_version_id,
            supplierRequestVersionHash: $row->supplier_request_version_hash,
            decisionId: (int) $row->decision_id,
            decisionRevision: (int) $row->decision_revision,
            eventSequence: (int) $row->event_sequence,
            eventType: ProcurementAwardEventType::from((string) $row->event_type),
            occurredAt: new DateTimeImmutable((string) $row->occurred_at),
            actorId: $row->actor_id === null ? null : (int) $row->actor_id,
            selectedStatus: (string) $row->selected_status,
            manifest: $manifest,
            policy: $policy,
            selectionFingerprint: (string) $row->selection_fingerprint,
            reasonPresent: (bool) $row->reason_present,
            reasonNormalizedLength: (int) $row->reason_normalized_length,
            reasonDigest: $row->reason_digest,
            predecessorEventId: $row->predecessor_event_id,
            purchaseOrderId: $row->purchase_order_id === null ? null : (int) $row->purchase_order_id,
            forcedSourceHash: (string) $row->source_hash,
        );
    }

    private static function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw new LogicException('procurement_award_evidence_json_invalid');
        }
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : throw new LogicException('procurement_award_evidence_json_invalid');
    }
}
