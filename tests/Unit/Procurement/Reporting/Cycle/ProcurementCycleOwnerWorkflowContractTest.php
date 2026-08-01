<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\Procurement\Enums\ProcurementApprovalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
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
use App\BusinessModules\Features\Procurement\Reporting\Award\Contracts\ProcurementAwardOwnerEventWriter;
use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardPreparedSelection;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementCycleSourceState;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementOwnerWorkflowRuntime;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementProcessEventStore;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementTransactionBoundary;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessDimensionSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTransition;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementProcessEventCode;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Enums\ProcurementTerminalReason;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\ProcurementCyclePolicyVersion;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementAwardTimeResolver;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleOwnerEventRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementProcessEventRecorder;
use App\BusinessModules\Features\Procurement\Services\ProcurementApprovalPolicyService;
use App\BusinessModules\Features\Procurement\Services\ProcurementApprovalService;
use App\BusinessModules\Features\Procurement\Services\ProcurementAuditService;
use App\BusinessModules\Features\Procurement\Services\ProcurementDutySeparationService;
use App\BusinessModules\Features\Procurement\Services\ProcurementLifecycleService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPaymentGateService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPdfService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderService;
use App\BusinessModules\Features\Procurement\Services\PurchaseRequestNumberGenerator;
use App\BusinessModules\Features\Procurement\Services\PurchaseRequestService;
use App\BusinessModules\Features\Procurement\Services\SupplierPartyService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalIntakeService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService;
use App\BusinessModules\Features\Procurement\Services\SupplierRequestService;
use App\BusinessModules\Features\Procurement\Services\SupplierRequestVersionService;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Material;
use App\Models\Supplier;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class ProcurementCycleOwnerWorkflowContractTest extends TestCase
{
    public function test_public_request_approve_uses_owner_transaction_and_records_line_events(): void
    {
        [$request, $lines] = $this->requestGraph();
        $request->status = PurchaseRequestStatusEnum::PENDING;
        [$journal, $state, $recorder] = $this->source();
        foreach ($lines as $line) {
            $state->snapshots[(int) $line->id] = $this->pinnedSnapshot((int) $line->id);
        }
        $service = $this->purchaseRequestService($recorder, $journal);

        try {
            $service->approve($request, 502);
        } catch (Throwable $exception) {
            self::fail(
                'Public owner workflow is not DB-free contract-testable: '
                .$exception->getMessage()."\n".$exception->getTraceAsString(),
            );
        }

        self::assertSame(PurchaseRequestStatusEnum::APPROVED, $request->status);
        self::assertCount(2, $journal->events(ProcurementProcessEventCode::REQUEST_APPROVED));
    }

    public function test_request_owner_contract_commits_line_events_with_typed_terminal_signatures(): void
    {
        [$createdRequest, $createdLines] = $this->requestGraph();
        [$journal, $state, $recorder] = $this->source();
        $service = $this->purchaseRequestService($recorder, $journal);
        $createdAt = new DateTimeImmutable('2026-08-01T08:00:00.123456+00:00');
        $journal->now = $createdAt;
        $service->createdRequest = $createdRequest;

        $service->create(10, 501, [
            'lines' => [
                ['name' => 'Материал 1', 'quantity' => 2.5, 'unit' => 'кг'],
                ['name' => 'Материал 2', 'quantity' => 2.5, 'unit' => 'кг'],
            ],
        ]);
        self::assertCount(2, $journal->events(ProcurementProcessEventCode::REQUEST_CREATED));
        foreach ($journal->events(ProcurementProcessEventCode::REQUEST_CREATED) as $transition) {
            $state->snapshots[$transition->purchaseRequestLineId] = $transition->dimensionSnapshot;
        }

        [$approvedRequest, $approvedLines] = $this->requestGraph();
        $approvedRequest->status = PurchaseRequestStatusEnum::PENDING;
        $this->seedSnapshots($state, $approvedRequest, $approvedLines);
        $journal->now = $createdAt->modify('+1 hour');
        $service->approve($approvedRequest, 502);

        [$rejectedRequest, $rejectedLines] = $this->requestGraph();
        $rejectedRequest->status = PurchaseRequestStatusEnum::PENDING;
        foreach ($rejectedLines as $line) {
            $state->snapshots[(int) $line->id] = $this->pinnedSnapshot((int) $line->id);
        }
        $journal->now = $createdAt->modify('+2 hours');
        $service->reject($rejectedRequest, 503, 'Не согласовано');

        self::assertCount(2, $journal->events(ProcurementProcessEventCode::REQUEST_APPROVED));
        self::assertCount(2, $journal->events(ProcurementProcessEventCode::CANCELLED));
        self::assertSame(PurchaseRequestStatusEnum::APPROVED, $approvedRequest->status);
        self::assertSame(PurchaseRequestStatusEnum::REJECTED, $rejectedRequest->status);
        self::assertNull($journal->events(ProcurementProcessEventCode::REQUEST_APPROVED)[0]->terminalReason);
        self::assertSame(
            ProcurementTerminalReason::REQUEST_REJECTED,
            $journal->events(ProcurementProcessEventCode::CANCELLED)[0]->terminalReason,
        );
        self::assertSame(
            array_map(static fn (PurchaseRequestLine $line): int => (int) $line->id, $createdLines),
            array_map(
                static fn (ProcurementProcessTransition $transition): int => $transition->purchaseRequestLineId,
                $journal->events(ProcurementProcessEventCode::REQUEST_CREATED),
            ),
        );
    }

    public function test_request_owner_contract_rolls_back_and_rejects_disallowed_pinned_terminal_reason(): void
    {
        [$request, $lines] = $this->requestGraph();
        [$journal, $state, $recorder] = $this->source();
        $service = $this->purchaseRequestService($recorder, $journal);
        foreach ($lines as $line) {
            $state->snapshots[(int) $line->id] = $this->pinnedSnapshot((int) $line->id);
        }
        $state->allowedTerminalReasons = [ProcurementTerminalReason::REQUEST_REJECTED];
        $request->status = PurchaseRequestStatusEnum::PENDING;
        $journal->failBeforeCommit = true;
        try {
            $service->approve($request, 502);
            self::fail('Expected owner rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame('owner_mutation_failed', $exception->getMessage());
        }

        self::assertSame(PurchaseRequestStatusEnum::PENDING, $request->status);
        self::assertSame([], $journal->committed);

        $journal->failBeforeCommit = false;
        $request->status = PurchaseRequestStatusEnum::PENDING;
        $state->allowedTerminalReasons = [];
        try {
            $service->reject($request, 503, 'Не согласовано');
            self::fail('Expected pinned terminal policy rejection.');
        } catch (LogicException $exception) {
            self::assertSame('procurement_cycle_terminal_reason_not_allowed', $exception->getMessage());
        }

        self::assertSame([], $journal->committed);
    }

    public function test_supplier_send_and_response_use_persisted_versions_with_exact_replay(): void
    {
        [$request, $requestLines] = $this->requestGraph();
        [$journal, $state, $recorder] = $this->source();
        $this->seedSnapshots($state, $request, $requestLines);
        [$supplierRequest, $requestVersion, $proposal, $proposalVersion] = $this->supplierGraph($requestLines);
        $requestService = $this->supplierRequestService($recorder, $journal);
        $proposalService = $this->supplierProposalService($recorder, $journal);
        $sentAt = new DateTimeImmutable('2026-08-02T08:30:00.654321+00:00');
        $respondedAt = new DateTimeImmutable('2026-08-02T11:45:00.987654+00:00');

        $supplierRequest->status = \App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum::DRAFT;
        self::assertTrue($supplierRequest->canBeSent());
        $requestService->sentVersion = $requestVersion;
        $journal->now = $sentAt;
        $requestService->send($supplierRequest, 501);
        $supplierRequest->status = \App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum::DRAFT;
        $requestService->send($supplierRequest, 501);
        $supplierRequest->status = \App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum::SENT;
        $supplierRequest->public_token = 'public-token';
        $proposalService->responseProposal = $proposal;
        $proposalService->responseProposalVersion = $proposalVersion;
        $proposalService->responseSupplierRequestVersion = $requestVersion;
        $journal->now = $respondedAt;
        $proposalService->createFromSupplierRequest($supplierRequest, [
            'items' => array_map(
                static fn (SupplierRequestLine $line): array => [
                    'supplier_request_line_id' => (int) $line->id,
                    'name' => 'Материал',
                    'quantity' => 2.5,
                    'unit' => 'кг',
                    'unit_price' => 100,
                ],
                $supplierRequest->lines->all(),
            ),
        ], 502);

        $sent = $journal->events(ProcurementProcessEventCode::SOLICITATION_SENT);
        $responded = $journal->events(ProcurementProcessEventCode::SUPPLIER_RESPONDED);
        self::assertCount(2, $sent);
        self::assertCount(2, $responded);
        self::assertSame((int) $requestVersion->id, $sent[0]->sourceId);
        self::assertSame($sentAt->format('U.u'), $requestService->persistedSentAt?->format('U.u'));
        self::assertSame((int) $proposalVersion->id, $responded[0]->sourceId);
        self::assertSame($sentAt->format('U.u'), $sent[0]->occurredAt->format('U.u'));
        self::assertSame($respondedAt->format('U.u'), $responded[0]->occurredAt->format('U.u'));
        self::assertSame($respondedAt->format('U.u'), $proposalService->persistedRespondedAt?->format('U.u'));
    }

    public function test_award_owner_contract_keeps_lineage_and_rejects_conflicting_replay(): void
    {
        [$request, $requestLines] = $this->requestGraph();
        [$journal, $state, $recorder] = $this->source();
        $this->seedSnapshots($state, $request, $requestLines);
        [, , $proposal, $proposalVersion, $decision, $order] = $this->supplierGraph($requestLines);
        $service = $this->supplierProposalService($recorder, $journal);
        $decidedAt = new DateTimeImmutable('2026-08-03T13:15:00.111222+00:00');
        $proposal->status = SupplierProposalStatusEnum::SUBMITTED;
        $decision->status = \App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum::SELECTED;
        $decision->setRawAttributes([
            ...$decision->getAttributes(),
            'selected_at' => $decidedAt,
        ], true);
        $service->acceptanceDecision = $decision;
        $service->acceptanceVersion = $proposalVersion;
        $service->acceptanceOrder = $order;

        $service->accept($proposal, 503);
        $proposal->status = SupplierProposalStatusEnum::SUBMITTED;
        $service->accept($proposal, 503);

        $awards = $journal->events(ProcurementProcessEventCode::AWARD_DECIDED);
        self::assertCount(2, $awards);
        self::assertSame(90, $awards[0]->supplierProposalDecisionId);
        self::assertSame(82, $awards[0]->supplierProposalVersionId);
        self::assertSame(100, $awards[0]->purchaseOrderId);
        self::assertTrue(in_array($awards[0]->purchaseOrderItemId, [101, 102], true));

        try {
            $decision->setRawAttributes([
                ...$decision->getAttributes(),
                'selected_at' => $decidedAt->modify('+1 second'),
            ], true);
            $proposal->status = SupplierProposalStatusEnum::SUBMITTED;
            $service->accept($proposal, 503);
            self::fail('Expected conflicting owner replay.');
        } catch (LogicException $exception) {
            self::assertSame('procurement_process_event_idempotency_conflict', $exception->getMessage());
        }

        self::assertCount(2, $journal->events(ProcurementProcessEventCode::AWARD_DECIDED));
    }

    public function test_public_proposal_response_rejects_foreign_line_before_cycle_event(): void
    {
        [$request, $requestLines] = $this->requestGraph();
        [$journal, $state, $recorder] = $this->source();
        $this->seedSnapshots($state, $request, $requestLines);
        [$supplierRequest] = $this->supplierGraph($requestLines);
        $supplierRequest->status = \App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum::SENT;
        $supplierRequest->public_token = 'public-token';
        $service = $this->supplierProposalService($recorder, $journal);

        try {
            $service->createFromSupplierRequest($supplierRequest, [
                'items' => [[
                    'supplier_request_line_id' => 999999,
                    'name' => 'Чужая строка',
                    'quantity' => 1,
                    'unit' => 'шт',
                    'unit_price' => 1,
                ]],
            ], 502);
            self::fail('Expected foreign supplier-request line to be rejected.');
        } catch (LogicException $exception) {
            self::assertSame('proposal_line_lineage_required', $exception->getMessage());
        }

        self::assertSame([], $journal->events(ProcurementProcessEventCode::SUPPLIER_RESPONDED));
    }

    public function test_approval_handoff_accepts_submitted_winner_once(): void
    {
        [$request, $requestLines] = $this->requestGraph();
        [$journal, $state, $recorder] = $this->source();
        $this->seedSnapshots($state, $request, $requestLines);
        [, , $proposal, $proposalVersion, $decision, $order] = $this->supplierGraph($requestLines);
        $proposal->status = SupplierProposalStatusEnum::SUBMITTED;
        $decision->status = SupplierProposalDecisionEnum::APPROVAL_REQUIRED;
        $decision->setRelation('winningProposal', $proposal);
        $proposalService = $this->supplierProposalService($recorder, $journal);
        $proposalService->acceptanceDecision = $decision;
        $proposalService->acceptanceVersion = $proposalVersion;
        $proposalService->acceptanceOrder = $order;
        $approvalService = $this->approvalService($proposalService, $journal);
        $approvalService->decision = $decision;
        $approvalService->blockingApprovals = [true, false, false];

        $firstApproval = $this->model(ProcurementApproval::class, 201, [
            'status' => ProcurementApprovalStatusEnum::PENDING,
        ]);
        $secondApproval = $this->model(ProcurementApproval::class, 202, [
            'status' => ProcurementApprovalStatusEnum::PENDING,
        ]);
        $replayApproval = $this->model(ProcurementApproval::class, 203, [
            'status' => ProcurementApprovalStatusEnum::PENDING,
        ]);

        $approvalService->approve($firstApproval, 503);
        self::assertSame([], $journal->events(ProcurementProcessEventCode::AWARD_DECIDED));

        $resolvedAt = new DateTimeImmutable('2026-08-03T14:15:00.445566+00:00');
        $journal->now = $resolvedAt;
        $approvalService->approve($secondApproval, 503);
        $approvalService->approve($replayApproval, 503);

        self::assertCount(2, $journal->events(ProcurementProcessEventCode::AWARD_DECIDED));
        self::assertSame(SupplierProposalStatusEnum::ACCEPTED, $proposal->status);
        self::assertSame($resolvedAt->format('U.u'), $secondApproval->resolved_at?->format('U.u'));
        self::assertSame(
            $resolvedAt->format('U.u'),
            $journal->events(ProcurementProcessEventCode::AWARD_DECIDED)[0]->occurredAt->format('U.u'),
        );
    }

    public function test_order_and_receipt_owner_contracts_preserve_exact_time_and_milestones(): void
    {
        [$request, $requestLines] = $this->requestGraph();
        [$journal, $state, $recorder] = $this->source();
        $this->seedSnapshots($state, $request, $requestLines);
        [, , , , , $order] = $this->supplierGraph($requestLines);
        [$firstReceipt, $secondReceipt] = $this->receiptGraph($order);
        $service = $this->purchaseOrderService($recorder, $journal);
        $sentAt = new DateTimeImmutable('2026-08-04T07:00:00.333444+00:00');
        $order->status = PurchaseOrderStatusEnum::DRAFT;
        $supplier = $this->model(Supplier::class, 200, ['email' => 'supplier@example.test']);
        $order->setRelation('supplier', $supplier);
        $journal->now = $sentAt;

        $journal->failBeforeCommit = true;
        try {
            $service->sendToSupplier($order);
            self::fail('Expected order send rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame('owner_mutation_failed', $exception->getMessage());
        }
        self::assertSame(PurchaseOrderStatusEnum::DRAFT, $order->status);
        self::assertSame([], $journal->events(ProcurementProcessEventCode::ORDER_SENT));

        $journal->failBeforeCommit = false;
        $service->sendToSupplier($order);
        self::assertSame(PurchaseOrderStatusEnum::SENT, $order->status);
        self::assertSame($sentAt->format('U.u'), $order->sent_at_exact?->format('U.u'));
        $state->fullyReceived = false;
        $journal->now = new DateTimeImmutable('2026-08-05T07:00:00.111111+00:00');
        $service->receiveMaterials(
            $order,
            1,
            [],
            505,
            ['receipt' => $firstReceipt],
        );
        self::assertSame(PurchaseOrderStatusEnum::PARTIALLY_DELIVERED, $order->status);
        foreach ($requestLines as $line) {
            $state->existingEvents[$this->eventKey((int) $line->id, ProcurementProcessEventCode::FIRST_RECEIPT)] = true;
        }
        $state->fullyReceived = true;
        $journal->now = new DateTimeImmutable('2026-08-06T07:00:00.222222+00:00');
        $service->receiveMaterials(
            $order,
            1,
            [],
            505,
            ['receipt' => $secondReceipt],
        );
        self::assertSame(PurchaseOrderStatusEnum::DELIVERED, $order->status);
        foreach ($requestLines as $line) {
            $state->existingEvents[$this->eventKey((int) $line->id, ProcurementProcessEventCode::FULLY_RECEIVED)] = true;
        }
        $service->receiveMaterials(
            $order,
            1,
            [],
            505,
            ['receipt' => $secondReceipt],
        );

        self::assertCount(2, $journal->events(ProcurementProcessEventCode::ORDER_SENT));
        self::assertCount(2, $journal->events(ProcurementProcessEventCode::FIRST_RECEIPT));
        self::assertCount(2, $journal->events(ProcurementProcessEventCode::FULLY_RECEIVED));
        self::assertSame(
            $sentAt->format('U.u'),
            $journal->events(ProcurementProcessEventCode::ORDER_SENT)[0]->occurredAt->format('U.u'),
        );
        self::assertSame($sentAt->format('U.u'), $service->persistedSentAt?->format('U.u'));
    }

    private function source(): array
    {
        $journal = new OwnerWorkflowTransactionJournal;
        $state = new OwnerWorkflowSourceState;
        $recorder = new ProcurementCycleOwnerEventRecorder(
            new ProcurementProcessEventRecorder($journal, $journal),
            $state,
        );

        return [$journal, $state, $recorder];
    }

    private function requestGraph(): array
    {
        $siteRequest = $this->model(SiteRequest::class, 20, [
            'project_id' => 20,
            'user_id' => 501,
            'priority' => 'high',
        ]);
        $request = $this->model(PurchaseRequest::class, 30, [
            'organization_id' => 10,
            'site_request_id' => 20,
            'assigned_to' => 501,
            'request_number' => 'PR-30',
        ]);
        $material = $this->model(Material::class, 200, ['category' => 'Металл']);
        $lines = [];
        foreach ([40, 41] as $index => $id) {
            $line = $this->model(PurchaseRequestLine::class, $id, [
                'purchase_request_id' => 30,
                'material_id' => 200,
                'name' => 'Материал '.($index + 1),
                'quantity' => '2.500',
                'unit' => 'кг',
            ]);
            $line->setRelation('purchaseRequest', $request);
            $line->setRelation('material', $material);
            $lines[] = $line;
        }
        $request->setRelation('siteRequest', $siteRequest);
        $request->setRelation('lines', new Collection($lines));

        return [$request, $lines];
    }

    private function supplierGraph(array $requestLines): array
    {
        $supplierLines = [];
        $proposalLines = [];
        $orderItems = [];
        foreach ($requestLines as $index => $requestLine) {
            $supplierLineId = 61 + $index;
            $proposalLineId = 81 + $index;
            $orderItemId = 101 + $index;
            $supplierLine = $this->model(SupplierRequestLine::class, $supplierLineId, [
                'supplier_request_id' => 60,
                'purchase_request_line_id' => (int) $requestLine->id,
            ]);
            $supplierLine->setRelation('purchaseRequestLine', $requestLine);
            $supplierLines[] = $supplierLine;
            $proposalLine = $this->model(SupplierProposalLine::class, $proposalLineId, [
                'supplier_request_line_id' => $supplierLineId,
            ]);
            $proposalLine->setRelation('supplierRequestLine', $supplierLine);
            $proposalLines[] = $proposalLine;
            $orderItem = $this->model(PurchaseOrderItem::class, $orderItemId, [
                'purchase_order_id' => 100,
                'purchase_request_line_id' => (int) $requestLine->id,
                'supplier_request_line_id' => $supplierLineId,
                'supplier_proposal_line_id' => $proposalLineId,
                'quantity' => '2.500',
                'total_price' => '250.00',
            ]);
            $orderItem->setRelation('purchaseRequestLine', $requestLine);
            $orderItem->setRelation('supplierRequestLine', $supplierLine);
            $orderItems[] = $orderItem;
        }
        $supplierRequest = $this->model(SupplierRequest::class, 60, [
            'organization_id' => 10,
            'purchase_request_id' => 30,
            'supplier_party_id' => 70,
        ]);
        $supplierRequest->setRelation('lines', new \Illuminate\Database\Eloquent\Collection($supplierLines));
        $requestVersion = $this->model(SupplierRequestVersion::class, 62);
        $proposal = $this->model(SupplierProposal::class, 80, [
            'organization_id' => 10,
            'supplier_request_id' => 60,
            'supplier_party_id' => 70,
        ]);
        $proposal->setRelation('lines', new Collection($proposalLines));
        $proposalVersion = $this->model(SupplierProposalVersion::class, 82);
        $decision = $this->model(SupplierProposalDecision::class, 90, [
            'organization_id' => 10,
            'supplier_request_id' => 60,
            'winning_supplier_proposal_id' => 80,
            'winning_supplier_proposal_version_id' => 82,
        ]);
        $order = $this->model(PurchaseOrder::class, 100, [
            'organization_id' => 10,
            'purchase_request_id' => 30,
            'supplier_party_id' => 70,
            'accepted_supplier_proposal_id' => 80,
            'accepted_supplier_proposal_version_id' => 82,
            'currency' => 'RUB',
        ]);
        $order->setRelation('items', new Collection($orderItems));

        return [$supplierRequest, $requestVersion, $proposal, $proposalVersion, $decision, $order];
    }

    private function receiptGraph(PurchaseOrder $order): array
    {
        $firstLines = [];
        $secondLines = [];
        foreach ($order->items as $index => $item) {
            $first = $this->model(PurchaseReceiptLine::class, 111 + $index, [
                'purchase_receipt_id' => 110,
                'purchase_order_item_id' => (int) $item->id,
                'quantity_received' => '1.000',
            ]);
            $first->setRelation('purchaseOrderItem', $item);
            $firstLines[] = $first;
            $second = $this->model(PurchaseReceiptLine::class, 121 + $index, [
                'purchase_receipt_id' => 120,
                'purchase_order_item_id' => (int) $item->id,
                'quantity_received' => '1.500',
            ]);
            $second->setRelation('purchaseOrderItem', $item);
            $secondLines[] = $second;
        }
        $firstReceipt = $this->model(PurchaseReceipt::class, 110, [
            'organization_id' => 10,
            'purchase_order_id' => 100,
        ]);
        $firstReceipt->setRelation('lines', new Collection($firstLines));
        $secondReceipt = $this->model(PurchaseReceipt::class, 120, [
            'organization_id' => 10,
            'purchase_order_id' => 100,
        ]);
        $secondReceipt->setRelation('lines', new Collection($secondLines));

        return [$firstReceipt, $secondReceipt];
    }

    private function seedSnapshots(
        OwnerWorkflowSourceState $state,
        PurchaseRequest $request,
        array $lines,
    ): void {
        foreach ($lines as $line) {
            $state->snapshots[(int) $line->id] = ProcurementProcessDimensionSnapshot::fromArray([
                'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
                'organization_id' => (int) $request->organization_id,
                'project_id' => 20,
                'purchase_request_id' => (int) $request->id,
                'purchase_request_line_id' => (int) $line->id,
                'quality_status' => 'PARTIAL',
                'gap_codes' => ['missing_policy_version'],
            ]);
        }
    }

    private function pinnedSnapshot(int $lineId): ProcurementProcessDimensionSnapshot
    {
        return ProcurementProcessDimensionSnapshot::fromArray([
            'schema_version' => ProcurementProcessDimensionSnapshot::SCHEMA_VERSION,
            'organization_id' => 10,
            'project_id' => 20,
            'purchase_request_id' => 30,
            'purchase_request_line_id' => $lineId,
            'policy_version_id' => 90,
            'policy_hash' => str_repeat('a', 64),
            'calendar_version' => 'procurement-business-calendar.v1',
            'calendar_hash' => str_repeat('b', 64),
            'quality_status' => 'FULL',
            'gap_codes' => [],
        ]);
    }

    private function purchaseRequestService(
        ProcurementCycleOwnerEventRecorder $recorder,
        OwnerWorkflowTransactionJournal $runtime,
    ): PurchaseRequestOwnerContractHarness {
        return new PurchaseRequestOwnerContractHarness(
            (new ReflectionClass(PurchaseRequestNumberGenerator::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProjectMaterialDeliveryService::class))->newInstanceWithoutConstructor(),
            $recorder,
            $runtime,
        );
    }

    private function supplierRequestService(
        ProcurementCycleOwnerEventRecorder $recorder,
        OwnerWorkflowTransactionJournal $runtime,
    ): SupplierRequestOwnerContractHarness {
        return new SupplierRequestOwnerContractHarness(
            (new ReflectionClass(SupplierPartyService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementAuditService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SupplierRequestVersionService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementLifecycleService::class))->newInstanceWithoutConstructor(),
            $recorder,
            $runtime,
        );
    }

    private function supplierProposalService(
        ProcurementCycleOwnerEventRecorder $recorder,
        OwnerWorkflowTransactionJournal $runtime,
    ): SupplierProposalOwnerContractHarness {
        return new SupplierProposalOwnerContractHarness(
            (new ReflectionClass(ProcurementAuditService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SupplierProposalIntakeService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SupplierProposalVersionService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SupplierRequestVersionService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementLifecycleService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SupplierPartyService::class))->newInstanceWithoutConstructor(),
            $recorder,
            (new ReflectionClass(ProcurementAwardTimeResolver::class))->newInstanceWithoutConstructor(),
            $runtime,
        );
    }

    private function purchaseOrderService(
        ProcurementCycleOwnerEventRecorder $recorder,
        OwnerWorkflowTransactionJournal $runtime,
    ): PurchaseOrderOwnerContractHarness {
        return new PurchaseOrderOwnerContractHarness(
            (new ReflectionClass(PurchaseOrderPdfService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SupplierPartyService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementAuditService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementLifecycleService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(PurchaseOrderPaymentGateService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProjectMaterialDeliveryService::class))->newInstanceWithoutConstructor(),
            $recorder,
            $runtime,
        );
    }

    private function approvalService(
        SupplierProposalService $proposalService,
        OwnerWorkflowTransactionJournal $runtime,
    ): ProcurementApprovalOwnerContractHarness {
        return new ProcurementApprovalOwnerContractHarness(
            (new ReflectionClass(ProcurementAuditService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementApprovalPolicyService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementDutySeparationService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(AuthorizationService::class))->newInstanceWithoutConstructor(),
            $proposalService,
            $runtime,
        );
    }

    private function model(string $class, int $id, array $attributes = []): object
    {
        $model = new $class;
        $model->forceFill(['id' => $id, ...$attributes]);
        $model->exists = true;

        return $model;
    }

    private function eventKey(int $lineId, ProcurementProcessEventCode $eventCode): string
    {
        return $lineId.':'.$eventCode->value;
    }
}

final class PurchaseRequestOwnerContractHarness extends PurchaseRequestService
{
    public ?PurchaseRequest $createdRequest = null;

    public function __construct(
        PurchaseRequestNumberGenerator $numberGenerator,
        ProjectMaterialDeliveryService $deliveryService,
        ProcurementCycleOwnerEventRecorder $cycleEventRecorder,
        private readonly OwnerWorkflowTransactionJournal $runtime,
    ) {
        parent::__construct($numberGenerator, $deliveryService, $cycleEventRecorder, $runtime);
    }

    protected function checkLimits(int $organizationId): void {}

    protected function persistCreatedPurchaseRequest(
        int $organizationId,
        array $data,
        ?SiteRequest $siteRequest,
        ?int $siteRequestId,
        DateTimeImmutable $occurredAt,
    ): PurchaseRequest {
        if (! $this->createdRequest instanceof PurchaseRequest) {
            throw new LogicException('created_request_fixture_required');
        }

        $request = $this->createdRequest;
        $this->createdRequest = null;

        return $request;
    }

    protected function dispatchCreatedAfterCommit(PurchaseRequest $purchaseRequest): void {}

    protected function lockPurchaseRequestForOwnerWorkflow(PurchaseRequest $request): PurchaseRequest
    {
        return $request;
    }

    protected function persistApprovedPurchaseRequest(
        PurchaseRequest $request,
        DateTimeImmutable $occurredAt,
    ): void {
        $previousStatus = $request->status;
        $request->status = PurchaseRequestStatusEnum::APPROVED;
        $this->runtime->afterRollback(static function () use ($request, $previousStatus): void {
            $request->status = $previousStatus;
        });
    }

    protected function persistRejectedPurchaseRequest(
        PurchaseRequest $request,
        string $reason,
        DateTimeImmutable $occurredAt,
    ): void {
        $previousStatus = $request->status;
        $request->status = PurchaseRequestStatusEnum::REJECTED;
        $this->runtime->afterRollback(static function () use ($request, $previousStatus): void {
            $request->status = $previousStatus;
        });
    }

    protected function afterPurchaseRequestApproved(PurchaseRequest $request, int $userId): void {}

    protected function afterPurchaseRequestRejected(PurchaseRequest $request): void {}

    protected function freshPurchaseRequest(PurchaseRequest $request): PurchaseRequest
    {
        return $request;
    }
}

final class SupplierRequestOwnerContractHarness extends SupplierRequestService
{
    public ?SupplierRequestVersion $sentVersion = null;

    public ?DateTimeImmutable $persistedSentAt = null;

    protected function lockSupplierRequestForSend(SupplierRequest $supplierRequest): SupplierRequest
    {
        return $supplierRequest;
    }

    protected function syncSupplierRequestForSend(SupplierRequest $supplierRequest): SupplierRequest
    {
        return $supplierRequest;
    }

    protected function persistSupplierRequestSent(
        SupplierRequest $supplierRequest,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
    ): array {
        if (! $this->sentVersion instanceof SupplierRequestVersion) {
            throw new LogicException('sent_version_fixture_required');
        }

        $previousStatus = $supplierRequest->status->value;
        $supplierRequest->status = \App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum::SENT;
        $this->persistedSentAt = $occurredAt;

        return [
            'supplier_request' => $supplierRequest,
            'version' => $this->sentVersion,
            'previous_status' => $previousStatus,
            'snapshot' => [],
            'email_queued_to' => null,
        ];
    }

    protected function recordSupplierRequestSentAudit(
        SupplierRequest $supplierRequest,
        SupplierRequestVersion $version,
        ?int $actorId,
        string $previousStatus,
        array $snapshot,
        ?string $emailQueuedTo,
    ): void {}

    protected function freshSentSupplierRequest(SupplierRequest $supplierRequest): SupplierRequest
    {
        return $supplierRequest;
    }
}

final class SupplierProposalOwnerContractHarness extends SupplierProposalService
{
    public ?SupplierProposal $responseProposal = null;

    public ?SupplierProposalVersion $responseProposalVersion = null;

    public ?SupplierRequestVersion $responseSupplierRequestVersion = null;

    public ?DateTimeImmutable $persistedRespondedAt = null;

    public ?SupplierProposalDecision $acceptanceDecision = null;

    public ?SupplierProposalVersion $acceptanceVersion = null;

    public ?PurchaseOrder $acceptanceOrder = null;

    public ?DateTimeImmutable $persistedAcceptedAt = null;

    public function __construct(
        ProcurementAuditService $auditService,
        SupplierProposalIntakeService $intakeService,
        SupplierProposalVersionService $versionService,
        SupplierRequestVersionService $requestVersionService,
        ProcurementLifecycleService $lifecycleService,
        SupplierPartyService $supplierPartyService,
        ProcurementCycleOwnerEventRecorder $cycleEventRecorder,
        ProcurementAwardTimeResolver $awardTimeResolver,
        private readonly OwnerWorkflowTransactionJournal $runtime,
    ) {
        parent::__construct(
            $auditService,
            $intakeService,
            $versionService,
            $requestVersionService,
            $lifecycleService,
            $supplierPartyService,
            $cycleEventRecorder,
            $awardTimeResolver,
            $runtime,
            new CycleOwnerAwardWriter,
        );
    }

    protected function lockSupplierRequestForProposalResponse(SupplierRequest $supplierRequest): SupplierRequest
    {
        return $supplierRequest;
    }

    protected function proposalLineLineageRequiredMessage(): string
    {
        return 'Строка запроса поставщику не принадлежит заявке.';
    }

    protected function throwProposalLineLineageValidation(): never
    {
        throw new LogicException('proposal_line_lineage_required');
    }

    protected function syncSupplierRequestForProposalResponse(SupplierRequest $supplierRequest): SupplierRequest
    {
        return $supplierRequest;
    }

    protected function loadSupplierRequestForProposalResponse(SupplierRequest $supplierRequest): SupplierRequest
    {
        return $supplierRequest;
    }

    protected function persistSupplierProposalResponse(
        SupplierRequest $supplierRequest,
        array $data,
        ?int $actorId,
        DateTimeImmutable $respondedAt,
        string &$stage,
    ): array {
        if (
            ! $this->responseProposal instanceof SupplierProposal
            || ! $this->responseProposalVersion instanceof SupplierProposalVersion
            || ! $this->responseSupplierRequestVersion instanceof SupplierRequestVersion
        ) {
            throw new LogicException('proposal_response_fixture_required');
        }

        $this->persistedRespondedAt = $respondedAt;
        $supplierRequest->status = \App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum::RESPONDED;

        return [
            'supplier_request_version' => $this->responseSupplierRequestVersion,
            'proposal' => $this->responseProposal,
            'proposal_version' => $this->responseProposalVersion,
        ];
    }

    protected function finishSupplierProposalResponse(
        SupplierRequest $supplierRequest,
        SupplierRequestVersion $supplierRequestVersion,
        SupplierProposal $proposal,
        SupplierProposalVersion $proposalVersion,
        array $data,
        ?int $actorId,
        string &$stage,
    ): SupplierProposal {
        return $proposal;
    }

    protected function reportSupplierProposalCreationFailure(
        SupplierRequest $supplierRequest,
        array $data,
        string $stage,
        Throwable $exception,
    ): void {}

    protected function lockSupplierProposalForAcceptance(SupplierProposal $proposal): SupplierProposal
    {
        return $proposal;
    }

    protected function assertProposalCanBeAccepted(SupplierProposal $proposal): void {}

    protected function lockSupplierRequestForAcceptance(int $supplierRequestId): void {}

    protected function lockSupplierProposalDecisionForAcceptance(
        SupplierProposal $proposal,
    ): ?SupplierProposalDecision {
        return $this->acceptanceDecision;
    }

    protected function lockAcceptedSupplierProposalVersion(
        SupplierProposal $proposal,
        SupplierProposalDecision $decision,
    ): ?SupplierProposalVersion {
        return $this->acceptanceVersion;
    }

    protected function lockExistingPurchaseOrderForAcceptance(SupplierProposal $proposal): ?PurchaseOrder
    {
        return null;
    }

    protected function loadSupplierProposalForAcceptance(SupplierProposal $proposal): void {}

    protected function persistAcceptedSupplierProposal(
        SupplierProposal $proposal,
        SupplierProposalVersion $acceptedVersion,
        DateTimeImmutable $acceptedAt,
    ): PurchaseOrder {
        if (! $this->acceptanceOrder instanceof PurchaseOrder) {
            throw new LogicException('proposal_acceptance_fixture_required');
        }

        $previousStatus = $proposal->status;
        $previousOrderId = $proposal->purchase_order_id;
        $proposal->status = SupplierProposalStatusEnum::ACCEPTED;
        $proposal->purchase_order_id = (int) $this->acceptanceOrder->id;
        $this->persistedAcceptedAt = $acceptedAt;
        $this->runtime->afterRollback(static function () use (
            $proposal,
            $previousStatus,
            $previousOrderId,
        ): void {
            $proposal->status = $previousStatus;
            $proposal->purchase_order_id = $previousOrderId;
        });

        return $this->acceptanceOrder;
    }

    protected function markAcceptedSupplierParty(SupplierProposal $proposal, PurchaseOrder $order): void {}

    protected function recordAcceptedSupplierProposalAudit(
        SupplierProposal $proposal,
        PurchaseOrder $order,
        ?int $actorId,
    ): void {}

    protected function dispatchAcceptedPurchaseOrderAfterCommit(PurchaseOrder $order): void {}

    protected function freshAcceptedSupplierProposal(SupplierProposal $proposal): SupplierProposal
    {
        return $proposal;
    }
}

final class PurchaseOrderOwnerContractHarness extends PurchaseOrderService
{
    public ?DateTimeImmutable $persistedSentAt = null;

    public function __construct(
        PurchaseOrderPdfService $pdfService,
        SupplierPartyService $supplierPartyService,
        ProcurementAuditService $auditService,
        ProcurementLifecycleService $lifecycleService,
        PurchaseOrderPaymentGateService $paymentGateService,
        ProjectMaterialDeliveryService $deliveryService,
        ProcurementCycleOwnerEventRecorder $cycleEventRecorder,
        private readonly OwnerWorkflowTransactionJournal $runtime,
    ) {
        parent::__construct(
            $pdfService,
            $supplierPartyService,
            $auditService,
            $lifecycleService,
            $paymentGateService,
            $deliveryService,
            $cycleEventRecorder,
            $runtime,
        );
    }

    protected function lockPurchaseOrderForSend(PurchaseOrder $order): PurchaseOrder
    {
        return $order;
    }

    protected function currentPurchaseOrderActorId(): ?int
    {
        return 504;
    }

    protected function persistPurchaseOrderSent(
        PurchaseOrder $order,
        ?int $actorId,
        DateTimeImmutable $sentAt,
    ): void {
        $previousStatus = $order->status;
        $previousSentAtExact = $order->sent_at_exact;
        $order->status = PurchaseOrderStatusEnum::SENT;
        $order->setRawAttributes([
            ...$order->getAttributes(),
            'sent_at_exact' => $sentAt,
        ], true);
        $this->persistedSentAt = $sentAt;
        $this->runtime->afterRollback(static function () use (
            $order,
            $previousStatus,
            $previousSentAtExact,
        ): void {
            $order->status = $previousStatus;
            $order->setRawAttributes([
                ...$order->getAttributes(),
                'sent_at_exact' => $previousSentAtExact,
            ], true);
        });
    }

    protected function dispatchPurchaseOrderSentAfterCommit(PurchaseOrder $order): void {}

    protected function freshSentPurchaseOrder(PurchaseOrder $order): PurchaseOrder
    {
        return $order;
    }

    protected function lockPurchaseOrderForReceipt(PurchaseOrder $order): PurchaseOrder
    {
        return $order;
    }

    protected function assertPurchaseOrderCanReceiveMaterials(PurchaseOrder $order, array $items): void {}

    protected function persistPurchaseReceiptOwnerState(
        PurchaseOrder $order,
        int $warehouseId,
        array $items,
        int $userId,
        array $receiptData,
    ): array {
        $receipt = $receiptData['receipt'] ?? null;
        if (! $receipt instanceof PurchaseReceipt) {
            throw new LogicException('purchase_receipt_fixture_required');
        }

        $previousStatus = $order->status;
        $order->status = (int) $receipt->id === 110
            ? PurchaseOrderStatusEnum::PARTIALLY_DELIVERED
            : PurchaseOrderStatusEnum::DELIVERED;
        $this->runtime->afterRollback(static function () use ($order, $previousStatus): void {
            $order->status = $previousStatus;
        });
        $warehouse = new OrganizationWarehouse;
        $warehouse->forceFill(['id' => $warehouseId, 'name' => 'Contract warehouse']);
        $warehouse->exists = true;

        return [
            'receipt' => $receipt,
            'warehouse' => $warehouse,
            'order_items' => $order->items,
        ];
    }

    protected function loadPurchaseReceiptMilestoneRelations(
        PurchaseOrder $order,
        PurchaseReceipt $receipt,
    ): void {}

    protected function recordPurchaseReceiptAudit(
        PurchaseOrder $order,
        PurchaseReceipt $receipt,
        OrganizationWarehouse $warehouse,
        array $items,
        int $userId,
    ): void {}

    protected function dispatchPurchaseReceiptAfterCommit(
        PurchaseOrder $order,
        OrganizationWarehouse $warehouse,
        Collection $orderItems,
        int $userId,
    ): void {}

    protected function freshReceivedPurchaseOrder(PurchaseOrder $order): PurchaseOrder
    {
        return $order;
    }
}

final class ProcurementApprovalOwnerContractHarness extends ProcurementApprovalService
{
    public ?SupplierProposalDecision $decision = null;

    public array $blockingApprovals = [];

    public function __construct(
        ProcurementAuditService $auditService,
        ProcurementApprovalPolicyService $policyService,
        ProcurementDutySeparationService $dutySeparationService,
        AuthorizationService $authorizationService,
        SupplierProposalService $proposalService,
        private readonly OwnerWorkflowTransactionJournal $runtime,
    ) {
        parent::__construct(
            $auditService,
            $policyService,
            $dutySeparationService,
            $authorizationService,
            $proposalService,
            $runtime,
            new CycleOwnerAwardWriter,
        );
    }

    protected function resolveApprovedOwnerState(
        ProcurementApproval $approval,
        int $actorId,
        ?string $comment,
    ): array {
        if (! $this->decision instanceof SupplierProposalDecision) {
            throw new LogicException('approval_decision_fixture_required');
        }

        $previousStatus = $approval->status;
        $previousResolvedAt = $approval->resolved_at;
        $approval->status = ProcurementApprovalStatusEnum::APPROVED;
        $approval->setRawAttributes([
            ...$approval->getAttributes(),
            'resolved_at' => $this->runtime->occurredAt(),
        ], true);
        $this->runtime->afterRollback(static function () use (
            $approval,
            $previousStatus,
            $previousResolvedAt,
        ): void {
            $approval->status = $previousStatus;
            $approval->setRawAttributes([
                ...$approval->getAttributes(),
                'resolved_at' => $previousResolvedAt,
            ], true);
        });

        return [
            'approval' => $approval,
            'decision' => $this->decision,
            'blocking_approvals_exist' => array_shift($this->blockingApprovals) ?? false,
        ];
    }

    protected function markProposalDecisionApproved(SupplierProposalDecision $decision): void
    {
        $previousStatus = $decision->status;
        $decision->status = SupplierProposalDecisionEnum::APPROVED;
        $this->runtime->afterRollback(static function () use ($decision, $previousStatus): void {
            $decision->status = $previousStatus;
        });
    }

    protected function finishApprovedOwnerState(
        ProcurementApproval $lockedApproval,
        SupplierProposalDecision $decision,
        int $actorId,
        ?string $comment,
    ): ProcurementApproval {
        return $lockedApproval;
    }
}

final class CycleOwnerAwardWriter implements ProcurementAwardOwnerEventWriter
{
    public function prepareForSupplierRequest(
        SupplierRequest $supplierRequest,
        int $selectedProposalId,
        DateTimeImmutable $occurredAt,
    ): ProcurementAwardPreparedSelection {
        throw new LogicException('award_selection_not_used_by_cycle_harness');
    }

    public function prepareForPurchaseRequest(
        PurchaseRequest $purchaseRequest,
        int $selectedProposalId,
        DateTimeImmutable $occurredAt,
    ): ProcurementAwardPreparedSelection {
        throw new LogicException('award_selection_not_used_by_cycle_harness');
    }

    public function selected(
        ProcurementAwardPreparedSelection $prepared,
        SupplierProposalDecision $decision,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
        ?string $reason,
    ): void {}

    public function approved(
        SupplierProposalDecision $decision,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): void {}

    public function rejected(
        SupplierProposalDecision $decision,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): void {}

    public function committed(
        SupplierProposalDecision $decision,
        SupplierProposalVersion $acceptedVersion,
        PurchaseOrder $order,
        DateTimeImmutable $occurredAt,
        ?int $actorId,
    ): void {}
}

final class OwnerWorkflowTransactionJournal implements ProcurementOwnerWorkflowRuntime, ProcurementProcessEventStore, ProcurementTransactionBoundary
{
    public array $committed = [];

    private array $pending = [];

    private array $rollbackCallbacks = [];

    private bool $active = false;

    private int $depth = 0;

    private array $commitCallbacks = [];

    public bool $failBeforeCommit = false;

    public DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-08-01T09:00:00.123456+00:00');
    }

    public function within(callable $workflow): mixed
    {
        $outermost = $this->depth === 0;
        if ($outermost) {
            $this->active = true;
            $this->pending = [];
            $this->rollbackCallbacks = [];
            $this->commitCallbacks = [];
        }
        $this->depth++;
        try {
            $result = $workflow();
            $this->depth--;
            if ($outermost) {
                if ($this->failBeforeCommit) {
                    throw new RuntimeException('owner_mutation_failed');
                }
                foreach ($this->pending as $identity => $transition) {
                    $this->committed[$identity] = $transition;
                }
                foreach ($this->commitCallbacks as $callback) {
                    $callback();
                }
            }

            return $result;
        } catch (Throwable $exception) {
            if ($this->depth > 0) {
                $this->depth--;
            }
            if ($outermost) {
                foreach (array_reverse($this->rollbackCallbacks) as $callback) {
                    $callback();
                }
            }
            throw $exception;
        } finally {
            if ($outermost) {
                $this->pending = [];
                $this->rollbackCallbacks = [];
                $this->commitCallbacks = [];
                $this->active = false;
                $this->depth = 0;
            }
        }
    }

    public function afterRollback(callable $callback): void
    {
        if (! $this->active) {
            throw new LogicException('owner_workflow_transaction_required');
        }

        $this->rollbackCallbacks[] = $callback;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->now;
    }

    public function afterCommit(callable $callback): void
    {
        if (! $this->active) {
            $callback();

            return;
        }

        $this->commitCallbacks[] = $callback;
    }

    public function append(ProcurementProcessTransition $transition): void
    {
        $identity = json_encode($transition->idempotencyIdentity(), JSON_THROW_ON_ERROR);
        $existing = $this->pending[$identity] ?? $this->committed[$identity] ?? null;
        if ($existing instanceof ProcurementProcessTransition) {
            if ($existing->payloadHash() !== $transition->payloadHash()) {
                throw new LogicException('procurement_process_event_idempotency_conflict');
            }

            return;
        }

        $this->pending[$identity] = $transition;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function events(ProcurementProcessEventCode $eventCode): array
    {
        return array_values(array_filter(
            $this->committed,
            static fn (ProcurementProcessTransition $transition): bool => $transition->eventCode === $eventCode,
        ));
    }
}

final class OwnerWorkflowSourceState implements ProcurementCycleSourceState
{
    public array $snapshots = [];

    public array $allowedTerminalReasons = [ProcurementTerminalReason::REQUEST_REJECTED];

    public array $existingEvents = [];

    public bool $fullyReceived = false;

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
        return $this->snapshots[$purchaseRequestLineId] ?? null;
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
        return $this->existingEvents[$purchaseRequestLineId.':'.$eventCode->value] ?? false;
    }

    public function isFullyReceived(int $purchaseOrderId, int $purchaseRequestLineId): bool
    {
        return $this->fullyReceived;
    }
}
