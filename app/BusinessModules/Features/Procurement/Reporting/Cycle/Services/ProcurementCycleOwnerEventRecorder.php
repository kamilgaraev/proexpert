<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierRequestVersion;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementCycleSourceState;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCyclePolicyVersion;
use DateTimeImmutable;
use BackedEnum;
use Illuminate\Support\Collection;
use LogicException;

final readonly class ProcurementCycleOwnerEventRecorder
{
    public function __construct(
        private ProcurementProcessEventRecorder $events,
        private ProcurementCycleSourceState $sourceState,
    ) {}

    public function recordRequestCreated(
        PurchaseRequest $request,
        int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        $request->loadMissing(['siteRequest', 'lines.material']);
        foreach ($request->lines as $line) {
            $snapshot = $this->newLineSnapshot($line, $occurredAt);
            $this->recordLine(
                $line,
                ProcurementProcessEventCode::REQUEST_CREATED,
                $occurredAt,
                'purchase_request',
                (int) $request->id,
                $actorId,
                $snapshot,
            );
        }
    }

    public function recordRequestApproved(
        PurchaseRequest $request,
        int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        $this->recordRequestTerminalEvent(
            $request,
            ProcurementProcessEventCode::REQUEST_APPROVED,
            $actorId,
            $occurredAt,
        );
    }

    public function recordRequestCancelled(
        PurchaseRequest $request,
        int $actorId,
        DateTimeImmutable $occurredAt,
        ProcurementTerminalReason $reason,
    ): void {
        $this->recordRequestTerminalEvent(
            $request,
            ProcurementProcessEventCode::CANCELLED,
            $actorId,
            $occurredAt,
            $reason,
        );
    }

    public function recordSolicitationSent(
        SupplierRequest $request,
        SupplierRequestVersion $version,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        $request->loadMissing(['lines.purchaseRequestLine.purchaseRequest.siteRequest']);
        foreach ($request->lines as $supplierLine) {
            $line = $supplierLine->purchaseRequestLine;
            if (! $line instanceof PurchaseRequestLine) {
                continue;
            }
            $this->recordLine(
                $line,
                ProcurementProcessEventCode::SOLICITATION_SENT,
                $occurredAt,
                'supplier_request_version',
                (int) $version->id,
                $actorId,
                $this->existingOrGapSnapshot($line, ['missing_request_created_event']),
                supplierRequestId: (int) $request->id,
                supplierRequestLineId: (int) $supplierLine->id,
                supplierPartyId: $this->positive($request->supplier_party_id),
            );
        }
    }

    public function recordSupplierResponded(
        SupplierProposal $proposal,
        SupplierProposalVersion $version,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        $proposal->loadMissing([
            'lines.supplierRequestLine.purchaseRequestLine.purchaseRequest.siteRequest',
        ]);
        foreach ($proposal->lines as $proposalLine) {
            $supplierLine = $proposalLine->supplierRequestLine;
            $line = $supplierLine?->purchaseRequestLine;
            if (! $line instanceof PurchaseRequestLine) {
                continue;
            }
            $this->recordLine(
                $line,
                ProcurementProcessEventCode::SUPPLIER_RESPONDED,
                $occurredAt,
                'supplier_proposal_version',
                (int) $version->id,
                $actorId,
                $this->existingOrGapSnapshot($line, ['missing_request_created_event']),
                supplierRequestId: $this->positive($proposal->supplier_request_id),
                supplierRequestLineId: $this->positive($proposalLine->supplier_request_line_id),
                supplierPartyId: $this->positive($proposal->supplier_party_id),
                supplierProposalId: (int) $proposal->id,
                supplierProposalVersionId: (int) $version->id,
            );
        }
    }

    public function recordAwardDecided(
        SupplierProposalDecision $decision,
        SupplierProposalVersion $version,
        PurchaseOrder $order,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        $items = $this->lineageItems($order, true);
        foreach ($items->groupBy('purchase_request_line_id') as $lineItems) {
            $first = $lineItems->first();
            $line = $first?->purchaseRequestLine;
            if (! $line instanceof PurchaseRequestLine) {
                throw new LogicException('procurement_award_request_line_lineage_required');
            }
            $snapshot = $this->existingOrGapSnapshot($line, ['missing_request_created_event']);
            $snapshot = $this->withDimensions($snapshot, [
                'awarded_supplier_party_id' => $this->positive($order->supplier_party_id),
                'awarded_amount' => $this->sumDecimal($lineItems->pluck('total_price')->all(), 2),
                'currency' => (string) $order->currency,
            ]);
            $this->recordLine(
                $line,
                ProcurementProcessEventCode::AWARD_DECIDED,
                $occurredAt,
                'supplier_proposal_decision',
                (int) $decision->id,
                $actorId,
                $snapshot,
                supplierRequestId: $this->positive($decision->supplier_request_id),
                supplierRequestLineId: $this->positive($first->supplier_request_line_id),
                supplierPartyId: $this->positive($order->supplier_party_id),
                supplierProposalId: $this->positive($decision->winning_supplier_proposal_id),
                supplierProposalVersionId: (int) $version->id,
                supplierProposalDecisionId: (int) $decision->id,
                purchaseOrderId: (int) $order->id,
                purchaseOrderItemId: (int) $first->id,
            );
        }
    }

    public function recordOrderSent(
        PurchaseOrder $order,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        foreach ($this->lineageItems($order, false)->groupBy('purchase_request_line_id') as $lineItems) {
            $first = $lineItems->first();
            $line = $first?->purchaseRequestLine;
            if (! $line instanceof PurchaseRequestLine) {
                throw new LogicException('procurement_order_request_line_lineage_required');
            }
            $this->recordLine(
                $line,
                ProcurementProcessEventCode::ORDER_SENT,
                $occurredAt,
                'purchase_order',
                (int) $order->id,
                $actorId,
                $this->existingOrGapSnapshot($line, ['missing_request_created_event']),
                supplierRequestId: $this->positive($first->supplierRequestLine?->supplier_request_id),
                supplierRequestLineId: $this->positive($first->supplier_request_line_id),
                supplierPartyId: $this->positive($order->supplier_party_id),
                supplierProposalId: $this->positive($order->accepted_supplier_proposal_id),
                supplierProposalVersionId: $this->positive($order->accepted_supplier_proposal_version_id),
                purchaseOrderId: (int) $order->id,
                purchaseOrderItemId: (int) $first->id,
            );
        }
    }

    public function recordReceiptMilestones(
        PurchaseOrder $order,
        PurchaseReceipt $receipt,
        int $actorId,
        DateTimeImmutable $occurredAt,
        ?ProcurementTerminalReason $terminalReason = null,
    ): void {
        $receipt->loadMissing([
            'lines.purchaseOrderItem.purchaseRequestLine.purchaseRequest.siteRequest',
            'lines.purchaseOrderItem.supplierRequestLine',
        ]);
        $receiptLines = $receipt->lines->filter(
            static fn ($line): bool => $line->purchaseOrderItem?->purchase_request_line_id !== null,
        );
        foreach ($receiptLines->groupBy(
            static fn ($line): int => (int) $line->purchaseOrderItem->purchase_request_line_id,
        ) as $requestLineId => $lines) {
            $firstReceiptLine = $lines->first();
            $item = $firstReceiptLine?->purchaseOrderItem;
            $requestLine = $item?->purchaseRequestLine;
            if (! $requestLine instanceof PurchaseRequestLine) {
                continue;
            }

            if (! $this->sourceState->eventExists(
                (int) $order->organization_id,
                (int) $requestLineId,
                ProcurementProcessEventCode::FIRST_RECEIPT,
            )) {
                $this->recordReceiptEvent(
                    $requestLine,
                    $order,
                    $receipt,
                    $firstReceiptLine,
                    ProcurementProcessEventCode::FIRST_RECEIPT,
                    $actorId,
                    $occurredAt,
                );
            }

            if ($this->sourceState->isFullyReceived((int) $order->id, (int) $requestLineId)
                && ! $this->sourceState->eventExists(
                    (int) $order->organization_id,
                    (int) $requestLineId,
                    ProcurementProcessEventCode::FULLY_RECEIVED,
                )) {
                $this->recordReceiptEvent(
                    $requestLine,
                    $order,
                    $receipt,
                    $firstReceiptLine,
                    ProcurementProcessEventCode::FULLY_RECEIVED,
                    $actorId,
                    $occurredAt,
                );
            }
        }
    }

    private function recordRequestTerminalEvent(
        PurchaseRequest $request,
        ProcurementProcessEventCode $eventCode,
        int $actorId,
        DateTimeImmutable $occurredAt,
        ?ProcurementTerminalReason $terminalReason = null,
    ): void {
        $request->loadMissing(['siteRequest', 'lines.purchaseRequest.siteRequest']);
        foreach ($request->lines as $line) {
            $snapshot = $this->existingOrGapSnapshot($line, ['missing_request_created_event']);
            if ($terminalReason !== null && ! $this->sourceState->policyAllows($snapshot, $terminalReason)) {
                continue;
            }
            $this->recordLine(
                $line,
                $eventCode,
                $occurredAt,
                'purchase_request',
                (int) $request->id,
                $actorId,
                $snapshot,
                terminalReason: $terminalReason,
            );
        }
    }

    private function recordReceiptEvent(
        PurchaseRequestLine $requestLine,
        PurchaseOrder $order,
        PurchaseReceipt $receipt,
        object $receiptLine,
        ProcurementProcessEventCode $eventCode,
        int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        $item = $receiptLine->purchaseOrderItem;
        $this->recordLine(
            $requestLine,
            $eventCode,
            $occurredAt,
            'purchase_receipt',
            (int) $receipt->id,
            $actorId,
            $this->existingOrGapSnapshot($requestLine, ['missing_request_created_event']),
            supplierRequestId: $this->positive($item->supplierRequestLine?->supplier_request_id),
            supplierRequestLineId: $this->positive($item->supplier_request_line_id),
            supplierPartyId: $this->positive($order->supplier_party_id),
            supplierProposalId: $this->positive($order->accepted_supplier_proposal_id),
            supplierProposalVersionId: $this->positive($order->accepted_supplier_proposal_version_id),
            purchaseOrderId: (int) $order->id,
            purchaseOrderItemId: (int) $item->id,
            purchaseReceiptId: (int) $receipt->id,
            purchaseReceiptLineId: (int) $receiptLine->id,
        );
    }

    private function recordLine(
        PurchaseRequestLine $line,
        ProcurementProcessEventCode $eventCode,
        DateTimeImmutable $occurredAt,
        string $sourceKind,
        int $sourceId,
        ?int $actorId,
        ProcurementProcessDimensionSnapshot $snapshot,
        ?int $supplierRequestId = null,
        ?int $supplierRequestLineId = null,
        ?int $supplierPartyId = null,
        ?int $supplierProposalId = null,
        ?int $supplierProposalVersionId = null,
        ?int $supplierProposalDecisionId = null,
        ?int $purchaseOrderId = null,
        ?int $purchaseOrderItemId = null,
        ?int $purchaseReceiptId = null,
        ?int $purchaseReceiptLineId = null,
        ?ProcurementTerminalReason $terminalReason = null,
    ): void {
        $request = $line->purchaseRequest;
        if (! $request instanceof PurchaseRequest) {
            throw new LogicException('procurement_process_request_line_parent_required');
        }
        $values = $snapshot->values;
        $this->events->record(new ProcurementProcessTransition(
            eventCode: $eventCode,
            organizationId: (int) $request->organization_id,
            projectId: $this->positive($values['project_id'] ?? null),
            purchaseRequestId: (int) $request->id,
            purchaseRequestLineId: (int) $line->id,
            occurredAt: $occurredAt,
            sourceKind: $sourceKind,
            sourceId: $sourceId,
            dimensionSnapshot: $snapshot,
            actorId: $actorId,
            supplierRequestId: $supplierRequestId,
            supplierRequestLineId: $supplierRequestLineId,
            supplierPartyId: $supplierPartyId,
            supplierProposalId: $supplierProposalId,
            supplierProposalVersionId: $supplierProposalVersionId,
            supplierProposalDecisionId: $supplierProposalDecisionId,
            purchaseOrderId: $purchaseOrderId,
            purchaseOrderItemId: $purchaseOrderItemId,
            purchaseReceiptId: $purchaseReceiptId,
            purchaseReceiptLineId: $purchaseReceiptLineId,
            policyVersionId: $this->positive($values['policy_version_id'] ?? null),
            policyHash: $this->stringOrNull($values['policy_hash'] ?? null),
            calendarVersion: $this->stringOrNull($values['calendar_version'] ?? null),
            calendarHash: $this->stringOrNull($values['calendar_hash'] ?? null),
            terminalReason: $terminalReason,
        ));
    }

    private function newLineSnapshot(
        PurchaseRequestLine $line,
        DateTimeImmutable $occurredAt,
    ): ProcurementProcessDimensionSnapshot {
        $line->loadMissing(['material', 'purchaseRequest.siteRequest']);
        $request = $line->purchaseRequest;
        if (! $request instanceof PurchaseRequest) {
            throw new LogicException('procurement_process_request_line_parent_required');
        }
        $projectId = $this->positive($request->siteRequest?->project_id);
        $policy = $this->sourceState->activePolicy((int) $request->organization_id, $projectId, $occurredAt);
        $gaps = [];
        if ($projectId === null) {
            $gaps[] = 'missing_project_lineage';
        }
        if (! $policy instanceof ProcurementCyclePolicyVersion) {
            $gaps[] = 'missing_policy_version';
        }
        sort($gaps, SORT_STRING);

        $values = [
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => (int) $request->organization_id,
            'project_id' => $projectId,
            'purchase_request_id' => (int) $request->id,
            'purchase_request_line_id' => (int) $line->id,
            'request_number' => (string) $request->request_number,
            'requester_id' => $this->positive($request->siteRequest?->user_id),
            'buyer_id' => $this->positive($request->assigned_to),
            'material_id' => $this->positive($line->material_id),
            'material_name' => (string) $line->name,
            'material_category_name' => $this->stringOrNull($line->material?->category),
            'priority' => $this->enumOrString($request->siteRequest?->priority),
            'quantity' => (string) $line->quantity,
            'unit' => (string) $line->unit,
            'needed_by' => $line->needed_by?->format('Y-m-d'),
            'quality_status' => $gaps === [] ? 'FULL' : 'PARTIAL',
            'gap_codes' => $gaps,
        ];
        if ($policy instanceof ProcurementCyclePolicyVersion) {
            $values['policy_version_id'] = (int) $policy->id;
            $values['policy_hash'] = (string) $policy->canonical_hash;
            $values['calendar_version'] = (string) $policy->calendar_version;
            $values['calendar_hash'] = (string) $policy->calendar_hash;
        }

        return ProcurementProcessDimensionSnapshot::fromArray($values);
    }

    private function existingOrGapSnapshot(
        PurchaseRequestLine $line,
        array $missingGaps,
    ): ProcurementProcessDimensionSnapshot {
        $snapshot = $this->sourceState->requestCreatedSnapshot(
            (int) $line->purchaseRequest?->organization_id,
            (int) $line->id,
        );
        if ($snapshot instanceof ProcurementProcessDimensionSnapshot) {
            return $snapshot;
        }

        return $this->missingCreatedEventSnapshot($line, $missingGaps);
    }

    private function missingCreatedEventSnapshot(
        PurchaseRequestLine $line,
        array $missingGaps,
    ): ProcurementProcessDimensionSnapshot {
        $line->loadMissing(['purchaseRequest.siteRequest']);
        $request = $line->purchaseRequest;
        if (! $request instanceof PurchaseRequest) {
            throw new LogicException('procurement_process_request_line_parent_required');
        }

        $projectId = $this->positive($request->siteRequest?->project_id);
        $gaps = array_values(array_unique([
            ...$missingGaps,
            'missing_policy_version',
            ...($projectId === null ? ['missing_project_lineage'] : []),
        ]));
        sort($gaps, SORT_STRING);

        return ProcurementProcessDimensionSnapshot::fromArray([
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => (int) $request->organization_id,
            'project_id' => $projectId,
            'purchase_request_id' => (int) $request->id,
            'purchase_request_line_id' => (int) $line->id,
            'quality_status' => 'PARTIAL',
            'gap_codes' => $gaps,
        ]);
    }

    private function withGaps(
        ProcurementProcessDimensionSnapshot $snapshot,
        array $gaps,
    ): ProcurementProcessDimensionSnapshot {
        $values = $snapshot->values;
        $values['gap_codes'] = array_values(array_unique([
            ...(array) ($values['gap_codes'] ?? []),
            ...$gaps,
        ]));
        sort($values['gap_codes'], SORT_STRING);
        $values['quality_status'] = 'PARTIAL';

        return ProcurementProcessDimensionSnapshot::fromArray($values);
    }

    private function withDimensions(
        ProcurementProcessDimensionSnapshot $snapshot,
        array $dimensions,
    ): ProcurementProcessDimensionSnapshot {
        return ProcurementProcessDimensionSnapshot::fromArray([
            ...$snapshot->values,
            ...array_filter($dimensions, static fn (mixed $value): bool => $value !== null),
        ]);
    }

    private function lineageItems(PurchaseOrder $order, bool $strict): Collection
    {
        $order->loadMissing([
            'items.purchaseRequestLine.purchaseRequest.siteRequest',
            'items.supplierRequestLine',
        ]);
        foreach ($order->items as $item) {
            if ($strict && (! $item instanceof PurchaseOrderItem || $item->purchase_request_line_id === null)) {
                throw new LogicException('procurement_order_request_line_lineage_required');
            }
        }

        return $order->items->filter(
            static fn ($item): bool => $item instanceof PurchaseOrderItem
                && $item->purchase_request_line_id !== null,
        );
    }

    private function sumDecimal(array $values, int $scale): string
    {
        $sum = 0;
        foreach ($values as $value) {
            $sum += $this->decimalUnits((string) $value, $scale);
        }

        return $this->decimalFromUnits($sum, $scale);
    }

    private function decimalUnits(string $value, int $scale): int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/D', $value, $matches) !== 1) {
            throw new LogicException('procurement_decimal_value_invalid');
        }
        $fraction = str_pad(substr($matches[2] ?? '', 0, $scale), $scale, '0');

        return ((int) $matches[1] * (10 ** $scale)) + (int) $fraction;
    }

    private function decimalFromUnits(int $value, int $scale): string
    {
        $factor = 10 ** $scale;

        return sprintf('%d.%0'.$scale.'d', intdiv($value, $factor), $value % $factor);
    }

    private function positive(mixed $value): ?int
    {
        $value = is_numeric($value) ? (int) $value : 0;

        return $value > 0 ? $value : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function enumOrString(mixed $value): ?string
    {
        return $value instanceof BackedEnum
            ? (string) $value->value
            : $this->stringOrNull($value);
    }

}
