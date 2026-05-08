<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalComparisonService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalService;
use App\Models\Organization;
use App\Models\Supplier;
use DomainException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierProposalDecisionTest extends TestCase
{
    public function test_comparison_lists_proposals_only_for_one_supplier_request(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $otherSupplierRequest = $this->createSupplierRequest($organization, 'PR-DEC-OTHER', 'SR-DEC-OTHER');

        $firstProposal = $this->createProposal($organization, $supplierRequest, 'KP-DEC-001', 1200);
        $secondProposal = $this->createProposal($organization, $supplierRequest, 'KP-DEC-002', 950);
        $foreignProposal = $this->createProposal($organization, $otherSupplierRequest, 'KP-DEC-003', 100);
        $foreignOrganization = Organization::factory()->create();
        $foreignOrganizationProposal = $this->createProposal(
            $foreignOrganization,
            $supplierRequest,
            'KP-DEC-FOREIGN-ORG',
            50
        );

        $comparison = app(SupplierProposalComparisonService::class)->comparisonForRequest($supplierRequest);

        $this->assertEqualsCanonicalizing(
            [$firstProposal->id, $secondProposal->id],
            array_column($comparison['rows'], 'id')
        );
        $this->assertNotContains($foreignProposal->id, array_column($comparison['rows'], 'id'));
        $this->assertNotContains($foreignOrganizationProposal->id, array_column($comparison['rows'], 'id'));
    }

    public function test_cheapest_proposal_uses_components_and_falls_back_to_total_amount(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);

        $componentProposal = $this->createProposal(
            $organization,
            $supplierRequest,
            'KP-DEC-004',
            totalAmount: 999,
            subtotalAmount: 80,
            deliveryAmount: 10,
            vatAmount: 10
        );

        $fallbackProposal = $this->createProposal(
            $organization,
            $supplierRequest,
            'KP-DEC-005',
            totalAmount: 90,
            subtotalAmount: 0,
            deliveryAmount: 0,
            vatAmount: 0
        );

        $comparison = app(SupplierProposalComparisonService::class)->comparisonForRequest($supplierRequest);

        $rows = collect($comparison['rows'])->keyBy('id');

        $this->assertSame($fallbackProposal->id, $comparison['cheapest_supplier_proposal_id']);
        $this->assertSame(100.0, $rows[$componentProposal->id]['comparison_total']);
        $this->assertSame(90.0, $rows[$fallbackProposal->id]['comparison_total']);
    }

    public function test_selecting_winner_persists_decision(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-006', 100);

        $decision = app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $winner->id,
            null,
            null
        );

        $this->assertSame($supplierRequest->id, $decision->supplier_request_id);
        $this->assertSame($winner->id, $decision->winning_supplier_proposal_id);
        $this->assertSame($winner->id, $decision->cheapest_supplier_proposal_id);
        $this->assertTrue($decision->is_lowest_price_selected);
        $this->assertSame('selected', $decision->status->value);
        $this->assertDatabaseHas('supplier_proposal_decisions', [
            'supplier_request_id' => $supplierRequest->id,
            'winning_supplier_proposal_id' => $winner->id,
            'status' => 'selected',
        ]);
    }

    public function test_comparison_payload_includes_saved_decision(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $purchaseRequest = PurchaseRequest::query()->findOrFail($supplierRequest->purchase_request_id);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-006-A', 100);

        $decision = app(SupplierProposalComparisonService::class)->selectWinnerForPurchaseRequest(
            $purchaseRequest,
            $winner->id,
            null,
            null
        );

        $requestComparison = app(SupplierProposalComparisonService::class)->comparisonForRequest($supplierRequest);
        $purchaseRequestComparison = app(SupplierProposalComparisonService::class)->comparisonForPurchaseRequest($purchaseRequest);

        $this->assertSame($decision->id, $requestComparison['decision']['id']);
        $this->assertSame($winner->id, $requestComparison['decision']['winning_supplier_proposal_id']);
        $this->assertSame('selected', $requestComparison['decision']['status']);
        $this->assertSame($decision->id, $purchaseRequestComparison['decision']['id']);
        $this->assertSame($winner->id, $purchaseRequestComparison['decision']['winning_supplier_proposal_id']);
        $this->assertSame('selected', $purchaseRequestComparison['decision']['status']);
    }

    public function test_selecting_non_cheapest_requires_decision_reason(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $this->createProposal($organization, $supplierRequest, 'KP-DEC-007', 100);
        $expensiveProposal = $this->createProposal($organization, $supplierRequest, 'KP-DEC-008', 150);

        $this->expectException(ValidationException::class);

        app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest,
            $expensiveProposal->id,
            '   ',
            null
        );
    }

    public function test_accept_proposal_requires_valid_decision_and_selected_proposal_matches(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-009', 100);
        $otherProposal = $this->createProposal($organization, $supplierRequest, 'KP-DEC-010', 150);

        try {
            app(SupplierProposalService::class)->accept($winner);
            $this->fail('Proposal without selected decision should not be accepted.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        app(SupplierProposalComparisonService::class)->selectWinner($supplierRequest, $winner->id, null, null);

        try {
            app(SupplierProposalService::class)->accept($otherProposal);
            $this->fail('Proposal that was not selected should not be accepted.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $accepted = app(SupplierProposalService::class)->accept($winner);

        $this->assertSame('accepted', $accepted->status->value);
        $this->assertNotNull($accepted->purchase_order_id);
    }

    public function test_cannot_change_winner_after_selected_proposal_accepted_and_order_created(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-011', 100);
        $otherProposal = $this->createProposal($organization, $supplierRequest, 'KP-DEC-012', 150);

        app(SupplierProposalComparisonService::class)->selectWinner($supplierRequest, $winner->id, null, null);
        app(SupplierProposalService::class)->accept($winner);

        $this->expectException(ValidationException::class);

        app(SupplierProposalComparisonService::class)->selectWinner(
            $supplierRequest->refresh(),
            $otherProposal->id,
            'Selected supplier cannot deliver on time.',
            null
        );
    }

    public function test_second_accept_with_stale_proposal_does_not_create_duplicate_order(): void
    {
        $organization = Organization::factory()->create();
        $supplierRequest = $this->createSupplierRequest($organization);
        $winner = $this->createProposal($organization, $supplierRequest, 'KP-DEC-013', 100);
        $staleWinner = SupplierProposal::query()->findOrFail($winner->id);

        app(SupplierProposalComparisonService::class)->selectWinner($supplierRequest, $winner->id, null, null);
        app(SupplierProposalService::class)->accept($winner);

        try {
            app(SupplierProposalService::class)->accept($staleWinner);
            $this->fail('Stale proposal accept should not create a duplicate purchase order.');
        } catch (DomainException|ValidationException) {
            $this->assertSame(1, PurchaseOrder::query()
                ->where('accepted_supplier_proposal_id', $winner->id)
                ->count());
        }
    }

    private function createSupplierRequest(
        Organization $organization,
        string $purchaseRequestNumber = 'PR-DEC-001',
        string $supplierRequestNumber = 'SR-DEC-001'
    ): SupplierRequest {
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => $purchaseRequestNumber,
            'status' => 'approved',
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
        ?float $subtotalAmount = null,
        float $deliveryAmount = 0,
        float $vatAmount = 0,
        string $status = 'submitted'
    ): SupplierProposal {
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => "{$proposalNumber} supplier",
            'is_active' => true,
        ]);

        return SupplierProposal::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'supplier_id' => $supplier->id,
            'proposal_number' => $proposalNumber,
            'proposal_date' => now()->toDateString(),
            'status' => $status,
            'subtotal_amount' => $subtotalAmount ?? $totalAmount,
            'delivery_amount' => $deliveryAmount,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
            'currency' => 'RUB',
        ]);
    }
}
