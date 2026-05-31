<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalIntake;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementApprovalService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalComparisonService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use DomainException;
use Tests\TestCase;

class ProcurementApprovalTest extends TestCase
{
    public function test_approval_required_when_selected_proposal_exceeds_purchase_request_budget_amount(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization, budgetAmount: 1000);
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-APR-001', 1200);

        $decision = app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $proposal->id,
            null,
            null
        );

        $this->assertSame('approval_required', $decision->status->value);
        $this->assertDatabaseHas('procurement_approvals', [
            'organization_id' => $organization->id,
            'approvable_type' => $decision->getMorphClass(),
            'approvable_id' => $decision->id,
            'reason_code' => 'budget_exceeded',
            'status' => 'pending',
        ]);
    }

    public function test_approval_required_when_selected_proposal_is_not_cheapest(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $cheapest = $this->createProposal($organization, $supplierRequest, 'KP-APR-002', 900);
        $expensive = $this->createProposal($organization, $supplierRequest, 'KP-APR-003', 1100);

        $decision = app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $expensive->id,
            'Supplier can deliver the full batch tomorrow.',
            null
        );

        $this->assertSame($cheapest->id, $decision->cheapest_supplier_proposal_id);
        $this->assertSame('approval_required', $decision->status->value);
        $this->assertDatabaseHas('procurement_approvals', [
            'organization_id' => $organization->id,
            'approvable_type' => $decision->getMorphClass(),
            'approvable_id' => $decision->id,
            'reason_code' => 'non_lowest_price',
            'status' => 'pending',
        ]);
    }

    public function test_approval_required_when_external_supplier_has_no_tax_id_in_snapshot(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $proposal = $this->createProposal(
            $organization,
            $supplierRequest,
            'KP-APR-004',
            900,
            supplierSnapshot: [
                'type' => 'external',
                'display_name' => 'External No Tax',
                'tax_id' => null,
            ]
        );

        $decision = app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $proposal->id,
            null,
            null
        );

        $this->assertSame('approval_required', $decision->status->value);
        $this->assertDatabaseHas('procurement_approvals', [
            'organization_id' => $organization->id,
            'approvable_type' => $decision->getMorphClass(),
            'approvable_id' => $decision->id,
            'reason_code' => 'external_supplier_missing_identity',
            'status' => 'pending',
        ]);
    }

    public function test_approval_not_required_for_cheapest_within_budget_and_registered_identity(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization, budgetAmount: 1000);
        $proposal = $this->createProposal(
            $organization,
            $supplierRequest,
            'KP-APR-005',
            900,
            supplierSnapshot: [
                'type' => 'registered',
                'display_name' => 'Registered Supplier',
                'tax_id' => '7701000000',
            ]
        );

        $decision = app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $proposal->id,
            null,
            null
        );

        $this->assertSame('selected', $decision->status->value);
        $this->assertDatabaseMissing('procurement_approvals', [
            'organization_id' => $organization->id,
            'approvable_type' => $decision->getMorphClass(),
            'approvable_id' => $decision->id,
            'status' => 'pending',
        ]);
    }

    public function test_approved_decision_automatically_creates_purchase_order(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $approver = User::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization, budgetAmount: 1000);
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-APR-006', 1200);

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

        app(ProcurementApprovalService::class)->approve($approval, $approver->id, 'Budget exception approved.');

        $this->assertSame('approved', $decision->refresh()->status->value);
        $this->assertSame('accepted', $proposal->refresh()->status->value);
        $this->assertSame(1, PurchaseOrder::query()
            ->where('accepted_supplier_proposal_id', $proposal->id)
            ->count());
    }

    public function test_rejected_decision_cannot_be_accepted(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $approver = User::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization, budgetAmount: 1000);
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-APR-007', 1200);

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

        app(ProcurementApprovalService::class)->reject($approval, $approver->id, 'Budget exception rejected.');

        $this->expectException(DomainException::class);

        app(SupplierProposalService::class)->accept($proposal);
    }

    public function test_expired_winning_proposal_blocks_pending_approval_resolution(): void
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->create();
        $approver = User::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization, budgetAmount: 1000);
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-APR-008', 1200);

        $decision = app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $proposal->id,
            null,
            $actor->id
        );

        $proposal->forceFill(['valid_until' => now()->subDay()->toDateString()])->save();

        $approval = ProcurementApproval::query()
            ->where('approvable_type', $decision->getMorphClass())
            ->where('approvable_id', $decision->id)
            ->firstOrFail();

        $blockers = app(ProcurementApprovalService::class)->resolutionBlockers($approval->load('approvable'), $approver->id);

        $this->assertContains('proposal_expired', collect($blockers)->pluck('code')->all());
        $this->expectException(DomainException::class);

        app(ProcurementApprovalService::class)->approve($approval, $approver->id, 'Approve expired offer.');
    }

    public function test_organization_owner_can_resolve_own_procurement_approval(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOrganizationOwner($organization);
        $supplierRequest = $this->createSupplierRequest($organization, budgetAmount: 1000);
        $proposal = $this->createProposal($organization, $supplierRequest, 'KP-APR-009', 1200);

        SupplierProposalIntake::query()->create([
            'organization_id' => $organization->id,
            'supplier_proposal_id' => $proposal->id,
            'source' => 'email',
            'received_at' => now(),
            'entered_by' => $owner->id,
            'attachment_ids' => [],
        ]);

        $decision = app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $proposal->id,
            null,
            $owner->id
        );

        $approval = ProcurementApproval::query()
            ->where('approvable_type', $decision->getMorphClass())
            ->where('approvable_id', $decision->id)
            ->firstOrFail();

        $blockers = app(ProcurementApprovalService::class)->resolutionBlockers($approval->load('approvable'), $owner->id);

        $this->assertSame([], $blockers);

        app(ProcurementApprovalService::class)->approve($approval, $owner->id, 'Owner works alone.');

        $this->assertDatabaseHas('procurement_approvals', [
            'id' => $approval->id,
            'status' => 'approved',
            'approved_by' => $owner->id,
        ]);
    }

    private function createSupplierRequest(
        Organization $organization,
        ?float $budgetAmount = null,
        string $purchaseRequestNumber = 'PR-APR-001',
        string $supplierRequestNumber = 'SR-APR-001'
    ): SupplierRequest {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => $purchaseRequestNumber,
            'status' => 'approved',
            'budget_amount' => $budgetAmount,
        ]);

        return SupplierRequest::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => $supplierRequestNumber,
            'status' => 'responded',
        ]);
    }

    private function createProposal(
        Organization $organization,
        SupplierRequest $supplierRequest,
        string $proposalNumber,
        float $totalAmount,
        ?array $supplierSnapshot = null,
        ?float $subtotalAmount = null,
        float $deliveryAmount = 0,
        float $vatAmount = 0
    ): SupplierProposal {
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => "{$proposalNumber} supplier",
            'tax_number' => $supplierSnapshot['tax_id'] ?? '7701000000',
            'is_active' => true,
        ]);

        $proposal = SupplierProposal::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'supplier_id' => $supplier->id,
            'supplier_snapshot' => $supplierSnapshot ?? [
                'type' => 'registered',
                'display_name' => "{$proposalNumber} supplier",
                'tax_id' => '7701000000',
            ],
            'proposal_number' => $proposalNumber,
            'proposal_date' => now()->toDateString(),
            'status' => 'submitted',
            'subtotal_amount' => $subtotalAmount ?? $totalAmount,
            'delivery_amount' => $deliveryAmount,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
            'currency' => 'RUB',
        ]);

        app(SupplierProposalVersionService::class)->createInitialVersion($proposal);

        return $proposal->refresh();
    }

    private function createOrganizationOwner(Organization $organization): User
    {
        $owner = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $organization->users()->attach($owner->id, [
            'is_owner' => true,
            'is_active' => true,
            'settings' => null,
        ]);

        UserRoleAssignment::assignRole(
            $owner,
            'organization_owner',
            AuthorizationContext::getOrganizationContext($organization->id)
        );

        return $owner;
    }
}
