<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Http\Middleware\EnsureProcurementActive;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementAuditService;
use App\BusinessModules\Features\Procurement\Services\ProcurementApprovalService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalComparisonService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Http\Middleware\JwtMiddleware;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProcurementAuditTest extends TestCase
{
    public function test_decision_approval_and_order_creation_write_domain_audit_events(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $approver = User::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization, '001', budgetAmount: 1000);
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-AUD-001', 1200, true);

        $decision = app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $proposal->id,
            null,
            $actor->id
        );

        $approval = ProcurementApproval::query()
            ->where('approvable_type', $decision->getMorphClass())
            ->where('approvable_id', $decision->id)
            ->firstOrFail();

        app(ProcurementApprovalService::class)->approve($approval, $approver->id, 'Approved for urgent delivery.');
        app(SupplierProposalService::class)->accept($proposal, $actor->id);

        $eventTypes = ProcurementAuditEvent::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType instanceof ProcurementAuditEventTypeEnum ? $eventType->value : $eventType)
            ->all();

        $this->assertContains('supplier_proposal_selected', $eventTypes);
        $this->assertContains('procurement_approval_requested', $eventTypes);
        $this->assertContains('procurement_approval_approved', $eventTypes);
        $this->assertContains('purchase_order_created', $eventTypes);

        $this->assertDatabaseHas('procurement_audit_events', [
            'organization_id' => $organization->id,
            'subject_type' => $decision->getMorphClass(),
            'subject_id' => $decision->id,
            'event_type' => 'supplier_proposal_selected',
            'actor_id' => $actor->id,
            'supplier_party_id' => $proposal->supplier_party_id,
        ]);

        $orderEvent = ProcurementAuditEvent::query()
            ->where('event_type', 'purchase_order_created')
            ->firstOrFail();

        $this->assertSame('KP-AUD-001', $orderEvent->payload['accepted_supplier_proposal_number']);
        $this->assertSame('KP-AUD-001 supplier', $orderEvent->payload['supplier_name']);
        $this->assertEquals(1200.0, $orderEvent->payload['total_amount']);
    }

    public function test_required_procurement_audit_event_types_are_supported(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization, '010');
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-AUD-010', 1000);
        $decision = $this->createDecision($organization, $supplierRequest, $proposal);
        $order = $this->createPurchaseOrder($organization, $supplierRequest, $proposal);

        $subjectsByEventType = [
            ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_CREATED->value => $supplierRequest,
            ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_SENT->value => $supplierRequest,
            ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_CANCELLED->value => $supplierRequest,
            ProcurementAuditEventTypeEnum::SUPPLIER_PROPOSAL_CREATED->value => $proposal,
            ProcurementAuditEventTypeEnum::SUPPLIER_PROPOSAL_SELECTED->value => $decision,
            ProcurementAuditEventTypeEnum::PROCUREMENT_APPROVAL_REQUESTED->value => $decision,
            ProcurementAuditEventTypeEnum::PROCUREMENT_APPROVAL_APPROVED->value => $decision,
            ProcurementAuditEventTypeEnum::PROCUREMENT_APPROVAL_REJECTED->value => $decision,
            ProcurementAuditEventTypeEnum::PURCHASE_ORDER_CREATED->value => $order,
            ProcurementAuditEventTypeEnum::MATERIALS_RECEIVED->value => $order,
        ];

        foreach ($subjectsByEventType as $eventType => $subject) {
            app(ProcurementAuditService::class)->record(
                $eventType,
                $subject,
                $organization->id,
                $actor->id,
                null,
                ['test_event_type' => $eventType]
            );
        }

        $eventTypes = ProcurementAuditEvent::query()
            ->where('organization_id', $organization->id)
            ->pluck('event_type')
            ->map(static fn ($eventType) => $eventType instanceof ProcurementAuditEventTypeEnum ? $eventType->value : $eventType)
            ->all();

        foreach (array_keys($subjectsByEventType) as $eventType) {
            $this->assertContains($eventType, $eventTypes);
        }
    }

    public function test_document_endpoints_do_not_expose_audit_events_and_dedicated_endpoint_does(): void
    {
        $this->withoutMiddleware([
            JwtMiddleware::class,
            AuthorizeMiddleware::class,
            EnsureProcurementActive::class,
        ]);

        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['is_owner' => true, 'is_active' => true]);

        $supplierRequest = $this->createSupplierRequest($organization, '020');
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-AUD-020', 900);
        $order = $this->createPurchaseOrder($organization, $supplierRequest, $proposal);

        $this->createAuditEvent($organization, $supplierRequest, ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_CREATED->value);
        $this->createAuditEvent($organization, $proposal, ProcurementAuditEventTypeEnum::SUPPLIER_PROPOSAL_CREATED->value);
        $this->createAuditEvent($organization, $order, ProcurementAuditEventTypeEnum::PURCHASE_ORDER_CREATED->value);
        ProcurementAuditEvent::query()->create([
            'organization_id' => $otherOrganization->id,
            'subject_type' => $supplierRequest->getMorphClass(),
            'subject_id' => $supplierRequest->id,
            'event_type' => ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_SENT->value,
            'occurred_at' => now(),
            'payload' => ['outside_current_org' => true],
        ]);

        $supplierRequestList = $this->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/procurement/supplier-requests');
        $supplierRequestList->assertOk()->assertJsonMissingPath('data.0.audit_events');

        $supplierRequestDetail = $this->actingAs($user, 'api_admin')
            ->getJson("/api/v1/admin/procurement/supplier-requests/{$supplierRequest->id}");
        $supplierRequestDetail->assertOk()->assertJsonMissingPath('data.audit_events');

        $proposalList = $this->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/procurement/proposals');
        $proposalList->assertOk()->assertJsonMissingPath('data.0.audit_events');

        $proposalDetail = $this->actingAs($user, 'api_admin')
            ->getJson("/api/v1/admin/procurement/proposals/{$proposal->id}");
        $proposalDetail->assertOk()->assertJsonMissingPath('data.audit_events');

        $orderList = $this->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/procurement/purchase-orders');
        $orderList->assertOk()->assertJsonMissingPath('data.0.audit_events');

        $orderDetail = $this->actingAs($user, 'api_admin')
            ->getJson("/api/v1/admin/procurement/purchase-orders/{$order->id}");
        $orderDetail->assertOk()->assertJsonMissingPath('data.audit_events');

        $auditEvents = $this->actingAs($user, 'api_admin')
            ->getJson("/api/v1/admin/procurement/audit-events?subject_type=SupplierRequest&subject_id={$supplierRequest->id}");

        $auditEvents
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event_type', ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_CREATED->value);
    }

    public function test_audit_events_route_is_guarded_by_audit_permission(): void
    {
        $route = Route::getRoutes()->getByName('admin.procurement.audit_events.index');

        $this->assertNotNull($route);
        $this->assertContains('authorize:procurement.audit.view', $route->gatherMiddleware());
    }

    private function createSupplierRequest(
        Organization $organization,
        string $suffix,
        ?float $budgetAmount = null
    ): SupplierRequest {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => "PR-AUD-{$suffix}",
            'status' => 'approved',
            'budget_amount' => $budgetAmount,
        ]);

        return SupplierRequest::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => "SR-AUD-{$suffix}",
            'status' => 'responded',
        ]);
    }

    private function createProposal(
        Organization $organization,
        SupplierRequest $supplierRequest,
        string $proposalNumber,
        float $totalAmount,
        bool $withLine = false
    ): SupplierProposal {
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => "{$proposalNumber} supplier",
            'tax_number' => '7701000000',
            'is_active' => true,
        ]);

        $proposal = SupplierProposal::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'supplier_id' => $supplier->id,
            'supplier_snapshot' => [
                'type' => 'registered',
                'display_name' => "{$proposalNumber} supplier",
                'tax_id' => '7701000000',
            ],
            'proposal_number' => $proposalNumber,
            'proposal_date' => now()->toDateString(),
            'status' => 'submitted',
            'subtotal_amount' => $totalAmount,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => $totalAmount,
            'currency' => 'RUB',
        ]);

        if ($withLine) {
            $proposal->lines()->create([
                'name' => "{$proposalNumber} material",
                'quantity' => 2,
                'unit' => 'pcs',
                'unit_price' => $totalAmount / 2,
                'total_amount' => $totalAmount,
            ]);
        }

        app(SupplierProposalVersionService::class)->createInitialVersion($proposal);

        return $proposal->refresh();
    }

    private function createDecision(
        Organization $organization,
        SupplierRequest $supplierRequest,
        SupplierProposal $proposal
    ): SupplierProposalDecision {
        return SupplierProposalDecision::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'winning_supplier_proposal_id' => $proposal->id,
            'cheapest_supplier_proposal_id' => $proposal->id,
            'status' => 'selected',
            'is_lowest_price_selected' => true,
            'comparison_snapshot' => [],
            'selected_at' => now(),
        ]);
    }

    private function createPurchaseOrder(
        Organization $organization,
        SupplierRequest $supplierRequest,
        SupplierProposal $proposal
    ): PurchaseOrder {
        return PurchaseOrder::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $supplierRequest->purchase_request_id,
            'accepted_supplier_proposal_id' => $proposal->id,
            'supplier_id' => $proposal->supplier_id,
            'supplier_snapshot' => $proposal->supplier_snapshot ?? [],
            'order_number' => "PO-AUD-{$proposal->id}",
            'order_date' => now()->toDateString(),
            'status' => 'confirmed',
            'total_amount' => $proposal->total_amount,
            'currency' => 'RUB',
            'confirmed_at' => now(),
        ]);
    }

    private function createAuditEvent(Organization $organization, Model $subject, string $eventType): ProcurementAuditEvent
    {
        return ProcurementAuditEvent::query()->create([
            'organization_id' => $organization->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event_type' => $eventType,
            'occurred_at' => now(),
            'payload' => ['event_type' => $eventType],
        ]);
    }

}
