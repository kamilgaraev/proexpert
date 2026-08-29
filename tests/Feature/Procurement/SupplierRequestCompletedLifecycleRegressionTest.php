<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementLifecycleService;
use App\Models\Organization;
use Tests\TestCase;

// Regression: ISSUE-085 — завершённый запрос поставщику повторно предлагал принять КП
// Found by /qa on 2026-08-29
// Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
class SupplierRequestCompletedLifecycleRegressionTest extends TestCase
{
    public function test_accepted_proposal_with_order_completes_supplier_request_action(): void
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => 'PR-ISSUE-085',
            'status' => 'approved',
        ]);
        $supplierRequest = SupplierRequest::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => 'SR-ISSUE-085',
            'status' => 'responded',
        ]);
        $proposal = SupplierProposal::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'proposal_number' => 'KP-ISSUE-085',
            'proposal_date' => now()->toDateString(),
            'status' => 'accepted',
            'subtotal_amount' => 100,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => 100,
            'currency' => 'RUB',
        ]);
        SupplierProposalDecision::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'winning_supplier_proposal_id' => $proposal->id,
            'cheapest_supplier_proposal_id' => $proposal->id,
            'status' => 'selected',
            'is_lowest_price_selected' => true,
            'comparison_snapshot' => [],
        ]);
        $order = PurchaseOrder::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'accepted_supplier_proposal_id' => $proposal->id,
            'order_number' => 'PO-ISSUE-085',
            'order_date' => now()->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 100,
            'currency' => 'RUB',
        ]);
        $proposal->update(['purchase_order_id' => $order->id]);

        $summary = app(ProcurementLifecycleService::class)->forSupplierRequest($supplierRequest->fresh());

        self::assertSame('proposal_accepted', $summary->stage);
        self::assertNull($summary->nextAction);
        self::assertFalse($summary->canAcceptProposal);
    }
}
