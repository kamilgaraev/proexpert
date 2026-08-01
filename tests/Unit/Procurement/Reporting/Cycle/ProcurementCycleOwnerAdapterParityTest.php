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
        $state->requestCreated = $store->transitions[0]->dimensionSnapshot;
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
        return $reason === ProcurementTerminalReason::REQUEST_REJECTED;
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
