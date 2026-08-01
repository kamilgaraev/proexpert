<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Cycle;

use App\BusinessModules\Features\BasicWarehouse\Services\ProjectMaterialDeliveryService;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalStatusEnum;
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
use DateTimeImmutable;
use Illuminate\Support\Collection;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class ProcurementCycleOwnerWorkflowContractTest extends TestCase
{
    public function test_request_owner_contract_commits_line_events_with_typed_terminal_signatures(): void
    {
        [$request, $lines] = $this->requestGraph();
        [$journal, $state, $recorder] = $this->source();
        $service = $this->purchaseRequestService($recorder);
        $createdAt = new DateTimeImmutable('2026-08-01T08:00:00.123456+00:00');

        $journal->within(fn () => $service->created($request, 501, $createdAt));
        self::assertCount(2, $journal->events(ProcurementProcessEventCode::REQUEST_CREATED));
        foreach ($journal->events(ProcurementProcessEventCode::REQUEST_CREATED) as $transition) {
            $state->snapshots[$transition->purchaseRequestLineId] = $transition->dimensionSnapshot;
        }

        $journal->within(fn () => $service->approved($request, 502, $createdAt->modify('+1 hour')));
        $journal->within(fn () => $service->cancelled(
            $request,
            503,
            $createdAt->modify('+2 hours'),
            ProcurementTerminalReason::REQUEST_REJECTED,
        ));

        self::assertCount(2, $journal->events(ProcurementProcessEventCode::REQUEST_APPROVED));
        self::assertCount(2, $journal->events(ProcurementProcessEventCode::CANCELLED));
        self::assertNull($journal->events(ProcurementProcessEventCode::REQUEST_APPROVED)[0]->terminalReason);
        self::assertSame(
            ProcurementTerminalReason::REQUEST_REJECTED,
            $journal->events(ProcurementProcessEventCode::CANCELLED)[0]->terminalReason,
        );
        self::assertSame(
            array_map(static fn (PurchaseRequestLine $line): int => (int) $line->id, $lines),
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
        $service = $this->purchaseRequestService($recorder);
        foreach ($lines as $line) {
            $state->snapshots[(int) $line->id] = $this->pinnedSnapshot((int) $line->id);
        }
        $state->allowedTerminalReasons = [ProcurementTerminalReason::REQUEST_REJECTED];
        $ownerStatus = 'pending';

        try {
            $journal->within(function () use ($service, $request, &$ownerStatus, $journal): void {
                $ownerStatus = 'approved';
                $journal->afterRollback(static function () use (&$ownerStatus): void {
                    $ownerStatus = 'pending';
                });
                $service->approved($request, 502, new DateTimeImmutable('2026-08-01T09:00:00+00:00'));
                throw new RuntimeException('owner_mutation_failed');
            });
            self::fail('Expected owner rollback.');
        } catch (RuntimeException $exception) {
            self::assertSame('owner_mutation_failed', $exception->getMessage());
        }

        self::assertSame('pending', $ownerStatus);
        self::assertSame([], $journal->committed);

        try {
            $journal->within(fn () => $service->cancelled(
                $request,
                503,
                new DateTimeImmutable('2026-08-01T10:00:00+00:00'),
                ProcurementTerminalReason::REQUEST_CANCELLED,
            ));
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
        $requestService = $this->supplierRequestService($recorder);
        $proposalService = $this->supplierProposalService($recorder);
        $sentAt = new DateTimeImmutable('2026-08-02T08:30:00.654321+00:00');
        $respondedAt = new DateTimeImmutable('2026-08-02T11:45:00.987654+00:00');

        $journal->within(fn () => $requestService->sent($supplierRequest, $requestVersion, 501, $sentAt));
        $journal->within(fn () => $requestService->sent($supplierRequest, $requestVersion, 501, $sentAt));
        $journal->within(fn () => $proposalService->responded($proposal, $proposalVersion, 502, $respondedAt));

        $sent = $journal->events(ProcurementProcessEventCode::SOLICITATION_SENT);
        $responded = $journal->events(ProcurementProcessEventCode::SUPPLIER_RESPONDED);
        self::assertCount(2, $sent);
        self::assertCount(2, $responded);
        self::assertSame((int) $requestVersion->id, $sent[0]->sourceId);
        self::assertSame((int) $proposalVersion->id, $responded[0]->sourceId);
        self::assertSame($sentAt->format('U.u'), $sent[0]->occurredAt->format('U.u'));
        self::assertSame($respondedAt->format('U.u'), $responded[0]->occurredAt->format('U.u'));
    }

    public function test_award_owner_contract_keeps_lineage_and_rejects_conflicting_replay(): void
    {
        [$request, $requestLines] = $this->requestGraph();
        [$journal, $state, $recorder] = $this->source();
        $this->seedSnapshots($state, $request, $requestLines);
        [, , , $proposalVersion, $decision, $order] = $this->supplierGraph($requestLines);
        $service = $this->supplierProposalService($recorder);
        $decidedAt = new DateTimeImmutable('2026-08-03T13:15:00.111222+00:00');

        $journal->within(fn () => $service->awarded($decision, $proposalVersion, $order, 503, $decidedAt));
        $journal->within(fn () => $service->awarded($decision, $proposalVersion, $order, 503, $decidedAt));

        $awards = $journal->events(ProcurementProcessEventCode::AWARD_DECIDED);
        self::assertCount(2, $awards);
        self::assertSame(90, $awards[0]->supplierProposalDecisionId);
        self::assertSame(82, $awards[0]->supplierProposalVersionId);
        self::assertSame(100, $awards[0]->purchaseOrderId);
        self::assertTrue(in_array($awards[0]->purchaseOrderItemId, [101, 102], true));

        try {
            $journal->within(fn () => $service->awarded(
                $decision,
                $proposalVersion,
                $order,
                503,
                $decidedAt->modify('+1 second'),
            ));
            self::fail('Expected conflicting owner replay.');
        } catch (LogicException $exception) {
            self::assertSame('procurement_process_event_idempotency_conflict', $exception->getMessage());
        }

        self::assertCount(2, $journal->events(ProcurementProcessEventCode::AWARD_DECIDED));
    }

    public function test_approval_handoff_accepts_submitted_winner_once(): void
    {
        $proposalService = new RecordingSupplierProposalService();
        $approvalService = $this->approvalService($proposalService);
        $proposal = $this->model(SupplierProposal::class, 80, [
            'status' => SupplierProposalStatusEnum::SUBMITTED,
        ]);
        $decision = $this->model(SupplierProposalDecision::class, 90);
        $decision->setRelation('winningProposal', $proposal);

        $approvalService->handoff($decision, 503);
        $approvalService->handoff($decision, 503);

        self::assertSame([80], $proposalService->acceptedProposalIds);
    }

    public function test_order_and_receipt_owner_contracts_preserve_exact_time_and_milestones(): void
    {
        [$request, $requestLines] = $this->requestGraph();
        [$journal, $state, $recorder] = $this->source();
        $this->seedSnapshots($state, $request, $requestLines);
        [, , , , , $order] = $this->supplierGraph($requestLines);
        [$firstReceipt, $secondReceipt] = $this->receiptGraph($order);
        $service = $this->purchaseOrderService($recorder);
        $sentAt = new DateTimeImmutable('2026-08-04T07:00:00.333444+00:00');

        $journal->within(fn () => $service->sent($order, 504, $sentAt));
        $state->fullyReceived = false;
        $journal->within(fn () => $service->received(
            $order,
            $firstReceipt,
            505,
            new DateTimeImmutable('2026-08-05T07:00:00.111111+00:00'),
        ));
        foreach ($requestLines as $line) {
            $state->existingEvents[$this->eventKey((int) $line->id, ProcurementProcessEventCode::FIRST_RECEIPT)] = true;
        }
        $state->fullyReceived = true;
        $journal->within(fn () => $service->received(
            $order,
            $secondReceipt,
            505,
            new DateTimeImmutable('2026-08-06T07:00:00.222222+00:00'),
        ));
        foreach ($requestLines as $line) {
            $state->existingEvents[$this->eventKey((int) $line->id, ProcurementProcessEventCode::FULLY_RECEIVED)] = true;
        }
        $journal->within(fn () => $service->received(
            $order,
            $secondReceipt,
            505,
            new DateTimeImmutable('2026-08-06T07:00:00.222222+00:00'),
        ));

        self::assertCount(2, $journal->events(ProcurementProcessEventCode::ORDER_SENT));
        self::assertCount(2, $journal->events(ProcurementProcessEventCode::FIRST_RECEIPT));
        self::assertCount(2, $journal->events(ProcurementProcessEventCode::FULLY_RECEIVED));
        self::assertSame(
            $sentAt->format('U.u'),
            $journal->events(ProcurementProcessEventCode::ORDER_SENT)[0]->occurredAt->format('U.u'),
        );
    }

    public function test_real_owner_adapter_cannot_append_outside_transaction(): void
    {
        [$request] = $this->requestGraph();
        [, , $recorder] = $this->source();
        $service = $this->purchaseRequestService($recorder);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('procurement_process_event_owner_transaction_required');

        $service->created($request, 501, new DateTimeImmutable('2026-08-01T08:00:00+00:00'));
    }

    private function source(): array
    {
        $journal = new OwnerWorkflowTransactionJournal();
        $state = new OwnerWorkflowSourceState();
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
        $supplierRequest->setRelation('lines', new Collection($supplierLines));
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
    ): PurchaseRequestOwnerContractHarness {
        return new PurchaseRequestOwnerContractHarness(
            (new ReflectionClass(PurchaseRequestNumberGenerator::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProjectMaterialDeliveryService::class))->newInstanceWithoutConstructor(),
            $recorder,
        );
    }

    private function supplierRequestService(
        ProcurementCycleOwnerEventRecorder $recorder,
    ): SupplierRequestOwnerContractHarness {
        return new SupplierRequestOwnerContractHarness(
            (new ReflectionClass(SupplierPartyService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementAuditService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SupplierRequestVersionService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementLifecycleService::class))->newInstanceWithoutConstructor(),
            $recorder,
        );
    }

    private function supplierProposalService(
        ProcurementCycleOwnerEventRecorder $recorder,
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
        );
    }

    private function purchaseOrderService(
        ProcurementCycleOwnerEventRecorder $recorder,
    ): PurchaseOrderOwnerContractHarness {
        return new PurchaseOrderOwnerContractHarness(
            (new ReflectionClass(PurchaseOrderPdfService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(SupplierPartyService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementAuditService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementLifecycleService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(PurchaseOrderPaymentGateService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProjectMaterialDeliveryService::class))->newInstanceWithoutConstructor(),
            $recorder,
        );
    }

    private function approvalService(
        RecordingSupplierProposalService $proposalService,
    ): ProcurementApprovalOwnerContractHarness {
        return new ProcurementApprovalOwnerContractHarness(
            (new ReflectionClass(ProcurementAuditService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementApprovalPolicyService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(ProcurementDutySeparationService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(AuthorizationService::class))->newInstanceWithoutConstructor(),
            $proposalService,
        );
    }

    private function model(string $class, int $id, array $attributes = []): object
    {
        $model = new $class();
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
    public function created(PurchaseRequest $request, int $actorId, DateTimeImmutable $occurredAt): void
    {
        $this->recordRequestCreatedCycleEvent($request, $actorId, $occurredAt);
    }

    public function approved(PurchaseRequest $request, int $actorId, DateTimeImmutable $occurredAt): void
    {
        $this->recordRequestApprovedCycleEvent($request, $actorId, $occurredAt);
    }

    public function cancelled(
        PurchaseRequest $request,
        int $actorId,
        DateTimeImmutable $occurredAt,
        ProcurementTerminalReason $reason,
    ): void {
        $this->recordRequestCancelledCycleEvent($request, $actorId, $occurredAt, $reason);
    }
}

final class SupplierRequestOwnerContractHarness extends SupplierRequestService
{
    public function sent(
        SupplierRequest $request,
        SupplierRequestVersion $version,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        $this->recordSolicitationSentCycleEvent($request, $version, $actorId, $occurredAt);
    }
}

final class SupplierProposalOwnerContractHarness extends SupplierProposalService
{
    public function responded(
        SupplierProposal $proposal,
        SupplierProposalVersion $version,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        $this->recordSupplierRespondedCycleEvent($proposal, $version, $actorId, $occurredAt);
    }

    public function awarded(
        SupplierProposalDecision $decision,
        SupplierProposalVersion $version,
        PurchaseOrder $order,
        ?int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        $this->recordAwardDecidedCycleEvent($decision, $version, $order, $actorId, $occurredAt);
    }
}

final class PurchaseOrderOwnerContractHarness extends PurchaseOrderService
{
    public function sent(PurchaseOrder $order, ?int $actorId, DateTimeImmutable $occurredAt): void
    {
        $this->recordOrderSentCycleEvent($order, $actorId, $occurredAt);
    }

    public function received(
        PurchaseOrder $order,
        PurchaseReceipt $receipt,
        int $actorId,
        DateTimeImmutable $occurredAt,
    ): void {
        $this->recordReceiptMilestonesCycleEvent($order, $receipt, $actorId, $occurredAt);
    }
}

final class ProcurementApprovalOwnerContractHarness extends ProcurementApprovalService
{
    public function handoff(SupplierProposalDecision $decision, int $actorId): void
    {
        $this->acceptApprovedWinningProposal($decision, $actorId);
    }
}

final class RecordingSupplierProposalService extends SupplierProposalService
{
    public array $acceptedProposalIds = [];

    public function __construct()
    {
    }

    public function accept(SupplierProposal $proposal, ?int $actorId = null): SupplierProposal
    {
        $this->acceptedProposalIds[] = (int) $proposal->id;
        $proposal->status = SupplierProposalStatusEnum::ACCEPTED;

        return $proposal;
    }
}

final class OwnerWorkflowTransactionJournal implements ProcurementProcessEventStore, ProcurementTransactionBoundary
{
    public array $committed = [];

    private array $pending = [];

    private array $rollbackCallbacks = [];

    private bool $active = false;

    public function within(callable $workflow): mixed
    {
        if ($this->active) {
            throw new LogicException('owner_workflow_nested_transaction_not_supported');
        }

        $this->active = true;
        $this->pending = [];
        $this->rollbackCallbacks = [];
        try {
            $result = $workflow();
            foreach ($this->pending as $identity => $transition) {
                $this->committed[$identity] = $transition;
            }

            return $result;
        } catch (Throwable $exception) {
            foreach (array_reverse($this->rollbackCallbacks) as $callback) {
                $callback();
            }
            throw $exception;
        } finally {
            $this->pending = [];
            $this->rollbackCallbacks = [];
            $this->active = false;
        }
    }

    public function afterRollback(callable $callback): void
    {
        if (! $this->active) {
            throw new LogicException('owner_workflow_transaction_required');
        }

        $this->rollbackCallbacks[] = $callback;
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
