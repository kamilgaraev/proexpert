<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalLine;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierRequestLine;
use App\BusinessModules\Features\Procurement\Models\SupplierRequestVersion;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementCycleSourceState;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementProcessEventStore;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementTransactionBoundary;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCyclePolicyVersion;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleOwnerEventRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementProcessEventRecorder;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\Material;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProcurementCycleOwnerAdapterParityTest extends TestCase
{
    public function test_owner_adapters_emit_all_nine_typed_transitions_at_line_grain(): void
    {
        [$request, $requestLine] = $this->requestGraph();
        $store = new OwnerParityStore();
        $state = new OwnerParitySourceState();
        $owner = new ProcurementCycleOwnerEventRecorder(
            new ProcurementProcessEventRecorder($store, new OwnerParityTransactionBoundary()),
            $state,
        );
        $occurredAt = new DateTimeImmutable('2026-08-01T10:00:00.123456+00:00');

        $owner->recordRequestCreated($request, 50, $occurredAt);
        $state->requestCreated = ProcurementProcessDimensionSnapshot::fromArray([
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => 10,
            'project_id' => 20,
            'purchase_request_id' => 30,
            'purchase_request_line_id' => 40,
            'policy_version_id' => 90,
            'policy_hash' => str_repeat('a', 64),
            'calendar_version' => 'procurement-business-calendar.v1',
            'calendar_hash' => str_repeat('b', 64),
            'quality_status' => 'FULL',
            'gap_codes' => [],
        ]);
        $owner->recordRequestApproved($request, 50, $occurredAt->modify('+1 second'));
        $owner->recordRequestCancelled(
            $request,
            50,
            $occurredAt->modify('+2 seconds'),
            ProcurementTerminalReason::REQUEST_REJECTED,
        );

        $supplierLine = $this->model(SupplierRequestLine::class, 61, [
            'supplier_request_id' => 60,
            'purchase_request_line_id' => 40,
        ]);
        $supplierLine->setRelation('purchaseRequestLine', $requestLine);
        $supplierRequest = $this->model(SupplierRequest::class, 60, [
            'organization_id' => 10,
            'purchase_request_id' => 30,
            'supplier_party_id' => 70,
        ]);
        $supplierRequest->setRelation('lines', new Collection([$supplierLine]));
        $supplierVersion = $this->model(SupplierRequestVersion::class, 62);
        $owner->recordSolicitationSent($supplierRequest, $supplierVersion, 50, $occurredAt->modify('+3 seconds'));

        $proposalLine = $this->model(SupplierProposalLine::class, 81, [
            'supplier_request_line_id' => 61,
        ]);
        $proposalLine->setRelation('supplierRequestLine', $supplierLine);
        $proposal = $this->model(SupplierProposal::class, 80, [
            'organization_id' => 10,
            'supplier_request_id' => 60,
            'supplier_party_id' => 70,
        ]);
        $proposal->setRelation('lines', new Collection([$proposalLine]));
        $proposalVersion = $this->model(SupplierProposalVersion::class, 82);
        $owner->recordSupplierResponded($proposal, $proposalVersion, 50, $occurredAt->modify('+4 seconds'));

        $decision = $this->model(SupplierProposalDecision::class, 90, [
            'organization_id' => 10,
            'supplier_request_id' => 60,
            'winning_supplier_proposal_id' => 80,
            'winning_supplier_proposal_version_id' => 82,
        ]);
        $orderItem = $this->model(PurchaseOrderItem::class, 101, [
            'purchase_order_id' => 100,
            'purchase_request_line_id' => 40,
            'supplier_request_line_id' => 61,
            'supplier_proposal_line_id' => 81,
            'quantity' => '2.500',
            'total_price' => '250.00',
        ]);
        $orderItem->setRelation('purchaseRequestLine', $requestLine);
        $orderItem->setRelation('supplierRequestLine', $supplierLine);
        $order = $this->model(PurchaseOrder::class, 100, [
            'organization_id' => 10,
            'purchase_request_id' => 30,
            'supplier_party_id' => 70,
            'accepted_supplier_proposal_id' => 80,
            'accepted_supplier_proposal_version_id' => 82,
            'currency' => 'RUB',
        ]);
        $order->setRelation('items', new Collection([$orderItem]));
        $owner->recordAwardDecided($decision, $proposalVersion, $order, 50, $occurredAt->modify('+5 seconds'));
        $owner->recordOrderSent($order, 50, $occurredAt->modify('+6 seconds'));

        $receiptLine = $this->model(PurchaseReceiptLine::class, 111, [
            'purchase_receipt_id' => 110,
            'purchase_order_item_id' => 101,
            'quantity_received' => '2.500',
        ]);
        $receiptLine->setRelation('purchaseOrderItem', $orderItem);
        $receipt = $this->model(PurchaseReceipt::class, 110, [
            'organization_id' => 10,
            'purchase_order_id' => 100,
        ]);
        $receipt->setRelation('lines', new Collection([$receiptLine]));
        $owner->recordReceiptMilestones($order, $receipt, 50, $occurredAt->modify('+7 seconds'));

        self::assertEqualsCanonicalizing(
            array_map(static fn (ProcurementProcessEventCode $code): string => $code->value, ProcurementProcessEventCode::cases()),
            array_map(static fn (ProcurementProcessTransition $event): string => $event->eventCode->value, $store->transitions),
        );
        self::assertCount(9, $store->transitions);
        self::assertSame(40, $store->transitions[8]->purchaseRequestLineId);
        $cancelled = (new Collection($store->transitions))->first(
            static fn (ProcurementProcessTransition $event): bool => $event->eventCode === ProcurementProcessEventCode::CANCELLED,
        );
        self::assertSame(ProcurementTerminalReason::REQUEST_REJECTED, $cancelled?->terminalReason);
    }

    public function test_terminal_reason_outside_pinned_policy_fails_closed_without_event(): void
    {
        [$request] = $this->requestGraph();
        $store = new OwnerParityStore();
        $state = new OwnerParitySourceState();
        $state->requestCreated = ProcurementProcessDimensionSnapshot::fromArray([
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => 10,
            'project_id' => 20,
            'purchase_request_id' => 30,
            'purchase_request_line_id' => 40,
            'policy_version_id' => 90,
            'policy_hash' => str_repeat('a', 64),
            'calendar_version' => 'procurement-business-calendar.v1',
            'calendar_hash' => str_repeat('b', 64),
            'quality_status' => 'FULL',
            'gap_codes' => [],
        ]);
        $state->allowedTerminalReasons = [];
        $owner = new ProcurementCycleOwnerEventRecorder(
            new ProcurementProcessEventRecorder($store, new OwnerParityTransactionBoundary()),
            $state,
        );

        try {
            $owner->recordRequestCancelled(
                $request,
                50,
                new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
                ProcurementTerminalReason::REQUEST_CANCELLED,
            );
            self::fail('Expected terminal policy invariant failure.');
        } catch (LogicException $exception) {
            self::assertSame('procurement_cycle_terminal_reason_not_allowed', $exception->getMessage());
        }

        self::assertSame([], $store->transitions);
    }

    public function test_award_without_request_created_appends_minimal_quarantine_transition(): void
    {
        [, $requestLine] = $this->requestGraph();
        $store = new OwnerParityStore();
        $owner = new ProcurementCycleOwnerEventRecorder(
            new ProcurementProcessEventRecorder($store, new OwnerParityTransactionBoundary()),
            new OwnerParitySourceState(),
        );
        $supplierLine = $this->model(SupplierRequestLine::class, 61, [
            'supplier_request_id' => 60,
            'purchase_request_line_id' => 40,
        ]);
        $supplierLine->setRelation('purchaseRequestLine', $requestLine);
        $orderItem = $this->model(PurchaseOrderItem::class, 101, [
            'purchase_order_id' => 100,
            'purchase_request_line_id' => 40,
            'supplier_request_line_id' => 61,
            'supplier_proposal_line_id' => 81,
            'quantity' => '2.500',
            'total_price' => '250.00',
        ]);
        $orderItem->setRelation('purchaseRequestLine', $requestLine);
        $orderItem->setRelation('supplierRequestLine', $supplierLine);
        $order = $this->model(PurchaseOrder::class, 100, [
            'organization_id' => 10,
            'purchase_request_id' => 30,
            'supplier_party_id' => 70,
            'accepted_supplier_proposal_id' => 80,
            'accepted_supplier_proposal_version_id' => 82,
            'currency' => 'RUB',
        ]);
        $order->setRelation('items', new Collection([$orderItem]));
        $decision = $this->model(SupplierProposalDecision::class, 90, [
            'organization_id' => 10,
            'supplier_request_id' => 60,
            'winning_supplier_proposal_id' => 80,
            'winning_supplier_proposal_version_id' => 82,
        ]);
        $version = $this->model(SupplierProposalVersion::class, 82);

        $owner->recordAwardDecided(
            $decision,
            $version,
            $order,
            50,
            new DateTimeImmutable('2026-08-01T10:00:00.123456+00:00'),
        );

        self::assertCount(1, $store->transitions);
        $transition = $store->transitions[0];
        self::assertSame(ProcurementProcessEventCode::AWARD_DECIDED, $transition->eventCode);
        self::assertSame(90, $transition->supplierProposalDecisionId);
        self::assertSame(82, $transition->supplierProposalVersionId);
        self::assertSame(100, $transition->purchaseOrderId);
        self::assertSame(101, $transition->purchaseOrderItemId);
        self::assertSame([
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => 10,
            'project_id' => null,
            'purchase_request_id' => 30,
            'purchase_request_line_id' => 40,
            'quality_status' => 'PARTIAL',
            'gap_codes' => [
                'missing_policy_version',
                'missing_project_lineage',
                'missing_request_created_event',
            ],
        ], $transition->dimensionSnapshot->values);
    }

    public function test_request_rejected_without_published_policy_is_rejected_without_event(): void
    {
        [$request] = $this->requestGraph();
        $store = new OwnerParityStore();
        $state = new OwnerParitySourceState();
        $state->requestCreated = ProcurementProcessDimensionSnapshot::fromArray([
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => 10,
            'project_id' => 20,
            'purchase_request_id' => 30,
            'purchase_request_line_id' => 40,
            'quality_status' => 'PARTIAL',
            'gap_codes' => ['missing_policy_version'],
        ]);
        $state->allowedTerminalReasons = [];
        $owner = new ProcurementCycleOwnerEventRecorder(
            new ProcurementProcessEventRecorder($store, new OwnerParityTransactionBoundary()),
            $state,
        );

        try {
            $owner->recordRequestCancelled(
                $request,
                50,
                new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
                ProcurementTerminalReason::REQUEST_REJECTED,
            );
            self::fail('Expected an unpinned terminal reason to be rejected.');
        } catch (\LogicException $exception) {
            self::assertSame('procurement_cycle_terminal_reason_not_allowed', $exception->getMessage());
        }

        self::assertSame([], $store->transitions);
    }

    public function test_missing_request_created_uses_quarantine_without_current_project_reconstruction(): void
    {
        [$request] = $this->requestGraph();
        $store = new OwnerParityStore();
        $state = new OwnerParitySourceState();
        $owner = new ProcurementCycleOwnerEventRecorder(
            new ProcurementProcessEventRecorder($store, new OwnerParityTransactionBoundary()),
            $state,
        );

        $owner->recordRequestApproved(
            $request,
            50,
            new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        );

        self::assertCount(1, $store->transitions);
        $transition = $store->transitions[0];
        self::assertNull($transition->projectId);
        self::assertSame([
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => 10,
            'project_id' => null,
            'purchase_request_id' => 30,
            'purchase_request_line_id' => 40,
            'quality_status' => 'PARTIAL',
            'gap_codes' => [
                'missing_policy_version',
                'missing_project_lineage',
                'missing_request_created_event',
            ],
        ], $transition->dimensionSnapshot->values);
    }

    private function requestGraph(): array
    {
        $siteRequest = $this->model(SiteRequest::class, 20, [
            'project_id' => 20,
            'user_id' => 50,
            'priority' => 'high',
        ]);
        $material = $this->model(Material::class, 200, ['category' => 'Металл']);
        $request = $this->model(PurchaseRequest::class, 30, [
            'organization_id' => 10,
            'site_request_id' => 20,
            'assigned_to' => 51,
            'request_number' => 'PR-30',
        ]);
        $line = $this->model(PurchaseRequestLine::class, 40, [
            'purchase_request_id' => 30,
            'material_id' => 200,
            'name' => 'Сталь',
            'quantity' => '2.500',
            'unit' => 'кг',
        ]);
        $request->setRelation('siteRequest', $siteRequest);
        $request->setRelation('lines', new Collection([$line]));
        $line->setRelation('purchaseRequest', $request);
        $line->setRelation('material', $material);

        return [$request, $line];
    }

    private function model(string $class, int $id, array $attributes = []): object
    {
        $model = new $class();
        $model->forceFill(['id' => $id, ...$attributes]);
        $model->exists = true;

        return $model;
    }
}

final class OwnerParityStore implements ProcurementProcessEventStore
{
    public array $transitions = [];

    public function append(ProcurementProcessTransition $transition): void
    {
        $this->transitions[] = $transition;
    }
}

final class OwnerParityTransactionBoundary implements ProcurementTransactionBoundary
{
    public function isActive(): bool
    {
        return true;
    }
}

final class OwnerParitySourceState implements ProcurementCycleSourceState
{
    public ?ProcurementProcessDimensionSnapshot $requestCreated = null;

    public array $allowedTerminalReasons = [ProcurementTerminalReason::REQUEST_REJECTED];

    public function activePolicy(
        int $organizationId,
        ?int $projectId,
        DateTimeImmutable $occurredAt,
    ): ?ProcurementCyclePolicyVersion {
        return null;
    }

    public function requestCreatedSnapshot(
        int $organizationId,
        int $purchaseRequestLineId,
    ): ?ProcurementProcessDimensionSnapshot {
        return $this->requestCreated;
    }

    public function policyAllows(
        ProcurementProcessDimensionSnapshot $snapshot,
        ProcurementTerminalReason $reason,
    ): bool {
        return in_array($reason, $this->allowedTerminalReasons, true);
    }

    public function eventExists(
        int $organizationId,
        int $purchaseRequestLineId,
        ProcurementProcessEventCode $eventCode,
    ): bool {
        return false;
    }

    public function isFullyReceived(int $purchaseOrderId, int $purchaseRequestLineId): bool
    {
        return true;
    }
}
