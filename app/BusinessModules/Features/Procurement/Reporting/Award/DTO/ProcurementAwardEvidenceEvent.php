<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\DTO;

use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardEventType;
use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ProcurementAwardEvidenceEvent
{
    public string $eventId;

    public string $sourceHash;

    public function __construct(
        public int $organizationId,
        public ?int $projectId,
        public int $purchaseRequestId,
        public int $supplierRequestId,
        public ?int $supplierRequestVersionId,
        public ?string $supplierRequestVersionHash,
        public int $decisionId,
        public int $decisionRevision,
        public int $eventSequence,
        public ProcurementAwardEventType $eventType,
        public DateTimeImmutable $occurredAt,
        public ?int $actorId,
        public string $selectedStatus,
        public ProcurementAwardManifest $manifest,
        public ProcurementAwardPolicyDefinition $policy,
        public string $selectionFingerprint,
        public bool $reasonPresent,
        public int $reasonNormalizedLength,
        public ?string $reasonDigest,
        public ?string $predecessorEventId = null,
        public ?int $purchaseOrderId = null,
        ?string $forcedSourceHash = null,
    ) {
        if ($decisionRevision < 1
            || $eventSequence < 1
            || preg_match('/^[a-f0-9]{64}$/D', $selectionFingerprint) !== 1
            || ($predecessorEventId !== null
                && preg_match('/^[a-f0-9-]{36}$/D', $predecessorEventId) !== 1)) {
            throw new InvalidArgumentException('procurement_award_evidence_event_invalid');
        }

        $calculated = ProcurementAwardCanonicalizer::framedHash([
            $this->organizationId,
            $this->projectId,
            $this->purchaseRequestId,
            $this->supplierRequestId,
            $this->supplierRequestVersionId,
            $this->supplierRequestVersionHash,
            $this->decisionId,
            $this->decisionRevision,
            $this->eventSequence,
            $this->eventType->value,
            $this->occurredAtUtc(),
            $this->actorId,
            $this->selectedStatus,
            $this->manifest->contentHash(),
            $this->policy->policyId,
            $this->policy->version,
            $this->policy->canonicalHash(),
            $this->selectionFingerprint,
            $this->reasonPresent,
            $this->reasonNormalizedLength,
            $this->reasonDigest,
            $this->predecessorEventId,
            $this->purchaseOrderId,
        ]);
        $this->sourceHash = $forcedSourceHash ?? $calculated;
        if (preg_match('/^[a-f0-9]{64}$/D', $this->sourceHash) !== 1) {
            throw new InvalidArgumentException('procurement_award_source_hash_invalid');
        }
        $this->eventId = self::uuidFromHash(hash('sha256', implode('|', [
            (string) $decisionId,
            (string) $decisionRevision,
            $eventType->value,
            $this->sourceHash,
        ])));
    }

    public static function fromSelection(
        ProcurementAwardSelectionFact $fact,
        int $decisionRevision,
        int $eventSequence,
    ): self {
        return new self(
            organizationId: $fact->organizationId,
            projectId: $fact->projectId,
            purchaseRequestId: $fact->purchaseRequestId,
            supplierRequestId: $fact->supplierRequestId,
            supplierRequestVersionId: $fact->supplierRequestVersionId,
            supplierRequestVersionHash: $fact->supplierRequestVersionHash,
            decisionId: $fact->decisionId,
            decisionRevision: $decisionRevision,
            eventSequence: $eventSequence,
            eventType: ProcurementAwardEventType::COMPARISON_CAPTURED,
            occurredAt: $fact->occurredAt,
            actorId: $fact->actorId,
            selectedStatus: $fact->selectedStatus,
            manifest: $fact->manifest,
            policy: $fact->policy,
            selectionFingerprint: $fact->fingerprint(),
            reasonPresent: $fact->reasonPresent,
            reasonNormalizedLength: $fact->reasonNormalizedLength,
            reasonDigest: $fact->reasonDigest,
        );
    }

    public function outcome(
        ProcurementAwardEventType $eventType,
        int $eventSequence,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
        ?string $predecessorEventId,
        ?int $purchaseOrderId = null,
    ): self {
        return new self(
            organizationId: $this->organizationId,
            projectId: $this->projectId,
            purchaseRequestId: $this->purchaseRequestId,
            supplierRequestId: $this->supplierRequestId,
            supplierRequestVersionId: $this->supplierRequestVersionId,
            supplierRequestVersionHash: $this->supplierRequestVersionHash,
            decisionId: $this->decisionId,
            decisionRevision: $this->decisionRevision,
            eventSequence: $eventSequence,
            eventType: $eventType,
            occurredAt: $occurredAt,
            actorId: $actorId,
            selectedStatus: $this->selectedStatus,
            manifest: $this->manifest,
            policy: $this->policy,
            selectionFingerprint: $this->selectionFingerprint,
            reasonPresent: $this->reasonPresent,
            reasonNormalizedLength: $this->reasonNormalizedLength,
            reasonDigest: $this->reasonDigest,
            predecessorEventId: $predecessorEventId,
            purchaseOrderId: $purchaseOrderId,
        );
    }

    public function canonicalPayload(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'purchase_request_id' => $this->purchaseRequestId,
            'supplier_request_id' => $this->supplierRequestId,
            'supplier_request_version_id' => $this->supplierRequestVersionId,
            'supplier_request_version_hash' => $this->supplierRequestVersionHash,
            'decision_id' => $this->decisionId,
            'decision_revision' => $this->decisionRevision,
            'event_sequence' => $this->eventSequence,
            'event_type' => $this->eventType->value,
            'occurred_at' => $this->occurredAtUtc(),
            'actor_id' => $this->actorId,
            'selected_status' => $this->selectedStatus,
            'manifest_hash' => $this->manifest->contentHash(),
            'policy_id' => $this->policy->policyId,
            'policy_version' => $this->policy->version,
            'policy_hash' => $this->policy->canonicalHash(),
            'selection_fingerprint' => $this->selectionFingerprint,
            'reason_present' => $this->reasonPresent,
            'reason_normalized_length' => $this->reasonNormalizedLength,
            'reason_digest' => $this->reasonDigest,
            'predecessor_event_id' => $this->predecessorEventId,
            'purchase_order_id' => $this->purchaseOrderId,
        ];
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function withSourceHash(string $sourceHash): self
    {
        return new self(
            organizationId: $this->organizationId,
            projectId: $this->projectId,
            purchaseRequestId: $this->purchaseRequestId,
            supplierRequestId: $this->supplierRequestId,
            supplierRequestVersionId: $this->supplierRequestVersionId,
            supplierRequestVersionHash: $this->supplierRequestVersionHash,
            decisionId: $this->decisionId,
            decisionRevision: $this->decisionRevision,
            eventSequence: $this->eventSequence,
            eventType: $this->eventType,
            occurredAt: $this->occurredAt,
            actorId: $this->actorId,
            selectedStatus: $this->selectedStatus,
            manifest: $this->manifest,
            policy: $this->policy,
            selectionFingerprint: $this->selectionFingerprint,
            reasonPresent: $this->reasonPresent,
            reasonNormalizedLength: $this->reasonNormalizedLength,
            reasonDigest: $this->reasonDigest,
            predecessorEventId: $this->predecessorEventId,
            purchaseOrderId: $this->purchaseOrderId,
            forcedSourceHash: $sourceHash,
        );
    }

    private static function uuidFromHash(string $hash): string
    {
        $hex = substr($hash, 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}
