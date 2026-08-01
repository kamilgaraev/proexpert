<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ProcurementProcessTransition
{
    public const EVENT_VERSION = 'procurement-process-events.v1';

    public string $eventVersion;

    public function __construct(
        public ProcurementProcessEventCode $eventCode,
        public int $organizationId,
        public ?int $projectId,
        public int $purchaseRequestId,
        public int $purchaseRequestLineId,
        public DateTimeImmutable $occurredAt,
        public string $sourceKind,
        public int $sourceId,
        public ProcurementProcessDimensionSnapshot $dimensionSnapshot,
        public ?int $actorId = null,
        public ?int $supplierRequestId = null,
        public ?int $supplierRequestLineId = null,
        public ?int $supplierPartyId = null,
        public ?int $supplierProposalId = null,
        public ?int $supplierProposalVersionId = null,
        public ?int $supplierProposalDecisionId = null,
        public ?int $purchaseOrderId = null,
        public ?int $purchaseOrderItemId = null,
        public ?int $purchaseReceiptId = null,
        public ?int $purchaseReceiptLineId = null,
        public ?int $policyVersionId = null,
        public ?string $policyHash = null,
        public ?string $calendarVersion = null,
        public ?string $calendarHash = null,
        public ?ProcurementTerminalReason $terminalReason = null,
        public ?int $sourceEventId = null,
        string $eventVersion = self::EVENT_VERSION,
    ) {
        $this->eventVersion = $eventVersion;
        $this->assertValid();
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    public function idempotencyIdentity(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'purchase_request_line_id' => $this->purchaseRequestLineId,
            'event_code' => $this->eventCode->value,
            'source_kind' => $this->sourceKind,
            'source_id' => $this->sourceId,
            'event_version' => $this->eventVersion,
        ];
    }

    public function payloadHash(): string
    {
        return hash('sha256', CanonicalJson::encode($this->canonicalPayload()));
    }

    public function canonicalPayload(): array
    {
        return [
            ...$this->idempotencyIdentity(),
            'project_id' => $this->projectId,
            'purchase_request_id' => $this->purchaseRequestId,
            'actor_id' => $this->actorId,
            'supplier_request_id' => $this->supplierRequestId,
            'supplier_request_line_id' => $this->supplierRequestLineId,
            'supplier_party_id' => $this->supplierPartyId,
            'supplier_proposal_id' => $this->supplierProposalId,
            'supplier_proposal_version_id' => $this->supplierProposalVersionId,
            'supplier_proposal_decision_id' => $this->supplierProposalDecisionId,
            'purchase_order_id' => $this->purchaseOrderId,
            'purchase_order_item_id' => $this->purchaseOrderItemId,
            'purchase_receipt_id' => $this->purchaseReceiptId,
            'purchase_receipt_line_id' => $this->purchaseReceiptLineId,
            'policy_version_id' => $this->policyVersionId,
            'policy_hash' => $this->policyHash,
            'calendar_version' => $this->calendarVersion,
            'calendar_hash' => $this->calendarHash,
            'terminal_reason' => $this->terminalReason?->value,
            'source_event_id' => $this->sourceEventId,
            'occurred_at' => $this->occurredAtUtc(),
            'dimension_snapshot' => $this->dimensionSnapshot->values,
        ];
    }

    private function assertValid(): void
    {
        foreach ([
            $this->organizationId,
            $this->purchaseRequestId,
            $this->purchaseRequestLineId,
            $this->sourceId,
        ] as $identifier) {
            if ($identifier < 1) {
                throw new InvalidArgumentException('procurement_process_transition_lineage_invalid');
            }
        }

        if ($this->projectId !== null && $this->projectId < 1) {
            throw new InvalidArgumentException('procurement_process_transition_lineage_invalid');
        }

        if (preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D', $this->sourceKind) !== 1
            || $this->eventVersion !== self::EVENT_VERSION) {
            throw new InvalidArgumentException('procurement_process_transition_schema_invalid');
        }

        $dimensions = $this->dimensionSnapshot->values;
        if ($dimensions['organization_id'] !== $this->organizationId
            || $dimensions['project_id'] !== $this->projectId
            || $dimensions['purchase_request_id'] !== $this->purchaseRequestId
            || $dimensions['purchase_request_line_id'] !== $this->purchaseRequestLineId) {
            throw new InvalidArgumentException('procurement_process_transition_dimension_lineage_mismatch');
        }

        foreach ($this->nullableIdentifiers() as $identifier) {
            if ($identifier !== null && $identifier < 1) {
                throw new InvalidArgumentException('procurement_process_transition_optional_lineage_invalid');
            }
        }

        if (($this->supplierRequestLineId !== null && $this->supplierRequestId === null)
            || ($this->supplierProposalId !== null && $this->supplierRequestId === null)
            || (($this->supplierProposalId === null) !== ($this->supplierProposalVersionId === null))
            || (($this->supplierRequestId !== null
                    || $this->supplierProposalId !== null
                    || $this->purchaseOrderId !== null)
                && $this->supplierPartyId === null)
            || ($this->supplierProposalDecisionId !== null
                && ($this->supplierRequestId === null
                    || $this->supplierProposalId === null
                    || $this->supplierProposalVersionId === null))
            || ($this->purchaseOrderItemId !== null && $this->purchaseOrderId === null)
            || ($this->purchaseReceiptId !== null && $this->purchaseOrderId === null)
            || ($this->purchaseReceiptLineId !== null
                && ($this->purchaseReceiptId === null
                    || $this->purchaseOrderId === null
                    || $this->purchaseOrderItemId === null))) {
            throw new InvalidArgumentException('procurement_process_transition_optional_lineage_incomplete');
        }

        $hasPolicy = $this->policyVersionId !== null;
        $hasCompletePins = is_string($this->policyHash)
            && is_string($this->calendarVersion)
            && is_string($this->calendarHash)
            && preg_match('/^[a-f0-9]{64}$/D', $this->policyHash) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $this->calendarHash) === 1
            && $this->calendarVersion === 'procurement-business-calendar.v1';
        if ($hasPolicy !== $hasCompletePins) {
            throw new InvalidArgumentException('procurement_process_transition_policy_pins_incomplete');
        }

        if (($dimensions['policy_version_id'] ?? null) !== $this->policyVersionId
            || ($dimensions['policy_hash'] ?? null) !== $this->policyHash
            || ($dimensions['calendar_version'] ?? null) !== $this->calendarVersion
            || ($dimensions['calendar_hash'] ?? null) !== $this->calendarHash) {
            throw new InvalidArgumentException('procurement_process_transition_policy_pins_mismatch');
        }

        if (($this->eventCode === ProcurementProcessEventCode::CANCELLED) !== ($this->terminalReason !== null)) {
            throw new InvalidArgumentException('procurement_process_transition_terminal_reason_invalid');
        }
    }

    private function nullableIdentifiers(): array
    {
        return [
            $this->actorId,
            $this->supplierRequestId,
            $this->supplierRequestLineId,
            $this->supplierPartyId,
            $this->supplierProposalId,
            $this->supplierProposalVersionId,
            $this->supplierProposalDecisionId,
            $this->purchaseOrderId,
            $this->purchaseOrderItemId,
            $this->purchaseReceiptId,
            $this->purchaseReceiptLineId,
            $this->policyVersionId,
            $this->sourceEventId,
        ];
    }
}
