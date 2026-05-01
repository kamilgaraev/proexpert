<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementApprovalService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalComparisonService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalService;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use Tests\TestCase;

class ProcurementAuditTest extends TestCase
{
    public function test_decision_approval_and_order_creation_write_domain_audit_events(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization, budgetAmount: 1000);
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-AUD-001', 1200);

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

        app(ProcurementApprovalService::class)->approve($approval, $actor->id, 'Approved for urgent delivery.');
        app(SupplierProposalService::class)->accept($proposal, $actor->id);

        $eventTypes = ProcurementAuditEvent::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->pluck('event_type')
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
        $this->assertSame(1200.0, $orderEvent->payload['total_amount']);
    }

    private function createSupplierRequest(
        Organization $organization,
        ?float $budgetAmount = null
    ): SupplierRequest {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => 'PR-AUD-001',
            'status' => 'approved',
            'budget_amount' => $budgetAmount,
        ]);

        return SupplierRequest::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => 'SR-AUD-001',
            'status' => 'responded',
        ]);
    }

    private function createProposal(
        Organization $organization,
        SupplierRequest $supplierRequest,
        string $proposalNumber,
        float $totalAmount
    ): SupplierProposal {
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => "{$proposalNumber} supplier",
            'tax_number' => '7701000000',
            'is_active' => true,
        ]);

        return SupplierProposal::query()->create([
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
    }
}
