<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Features\Procurement\Http\Middleware\EnsureProcurementActive;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementIssueService;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Http\Middleware\JwtMiddleware;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ProcurementIssueControllerTest extends TestCase
{
    public function test_index_returns_paginated_procurement_issues_with_summary(): void
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

        $pendingRequest = $this->createPurchaseRequest($organization, 'PENDING', 'pending');
        $approvedRequest = $this->createPurchaseRequest($organization, 'APPROVED', 'approved');
        $approvedWithSupplierRequest = $this->createPurchaseRequest($organization, 'HAS-SR', 'approved');
        $this->createSupplierRequest($organization, $approvedWithSupplierRequest);
        $sentOrder = $this->createPurchaseOrder($organization, 'SENT', 'sent');
        $inDeliveryOrder = $this->createPurchaseOrder($organization, 'DELIVERY', 'in_delivery');

        $this->createPurchaseRequest($otherOrganization, 'OUTSIDE', 'pending');

        $response = $this->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/procurement/issues?per_page=10');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('summary.total', 4)
            ->assertJsonPath('summary.approved_without_order', 1)
            ->assertJsonPath('summary.waiting_confirmation', 1)
            ->assertJsonPath('summary.waiting_receipt', 1)
            ->assertJsonFragment([
                'id' => "pr-pending-{$pendingRequest->id}",
                'scope' => 'purchase_requests',
                'type' => 'purchase_request_pending',
            ])
            ->assertJsonFragment([
                'id' => "pr-without-order-{$approvedRequest->id}",
                'action_href' => "/procurement/supplier-requests?purchase_request_id={$approvedRequest->id}",
            ])
            ->assertJsonFragment([
                'id' => "po-sent-{$sentOrder->id}",
                'type' => 'purchase_order_sent',
            ])
            ->assertJsonFragment([
                'id' => "po-in_delivery-{$inDeliveryOrder->id}",
                'type' => 'purchase_order_in_delivery',
            ])
            ->assertJsonMissing([
                'id' => "pr-without-order-{$approvedWithSupplierRequest->id}",
            ]);
    }

    public function test_index_filters_by_scope(): void
    {
        $this->withoutMiddleware([
            JwtMiddleware::class,
            AuthorizeMiddleware::class,
            EnsureProcurementActive::class,
        ]);

        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $user->organizations()->attach($organization->id, ['is_owner' => true, 'is_active' => true]);

        $this->createPurchaseRequest($organization, 'PENDING', 'pending');
        $this->createPurchaseOrder($organization, 'DRAFT', 'draft');

        $response = $this->actingAs($user, 'api_admin')
            ->getJson('/api/v1/admin/procurement/issues?scope=purchase_orders');

        $response
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.scope', 'purchase_orders')
            ->assertJsonPath('data.0.type', 'purchase_order_draft');
    }

    public function test_purchase_request_issue_action_label_matches_expired_proposal_next_action(): void
    {
        $organization = Organization::factory()->create();

        $purchaseRequest = $this->createPurchaseRequest($organization, 'EXPIRED', 'approved');
        $supplierRequest = $this->createSupplierRequest($organization, $purchaseRequest);
        $supplierRequest->forceFill(['status' => 'responded'])->save();
        $proposal = $this->createExpiredProposal($organization, $supplierRequest);

        SupplierProposalDecision::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'winning_supplier_proposal_id' => $proposal->id,
            'cheapest_supplier_proposal_id' => $proposal->id,
            'status' => 'approval_required',
            'is_lowest_price_selected' => true,
            'selected_at' => now(),
        ]);

        $result = app(ProcurementIssueService::class)->paginate(
            $organization->id,
            'purchase_requests',
            1,
            10
        );

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame("pr-without-order-{$purchaseRequest->id}", $result['items'][0]['id']);
        $this->assertSame(
            trans_message('procurement.lifecycle.actions.request_new_proposal'),
            $result['items'][0]['next_action']
        );
        $this->assertSame(
            trans_message('procurement.lifecycle.actions.request_new_proposal'),
            $result['items'][0]['action_label']
        );
    }

    public function test_issues_route_is_guarded_by_procurement_view_permission(): void
    {
        $route = Route::getRoutes()->getByName('admin.procurement.issues.index');

        $this->assertNotNull($route);
        $this->assertContains('authorize:procurement.view', $route->gatherMiddleware());
    }

    private function createPurchaseRequest(Organization $organization, string $suffix, string $status): PurchaseRequest
    {
        return PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => "PR-ISSUE-{$suffix}",
            'status' => $status,
            'budget_amount' => 1000,
            'budget_currency' => 'RUB',
        ]);
    }

    private function createPurchaseOrder(Organization $organization, string $suffix, string $status): PurchaseOrder
    {
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => "Supplier {$suffix}",
            'tax_number' => "7701000{$organization->id}{$suffix}",
            'is_active' => true,
        ]);

        return PurchaseOrder::query()->create([
            'organization_id' => $organization->id,
            'supplier_id' => $supplier->id,
            'supplier_snapshot' => [
                'type' => 'registered',
                'display_name' => $supplier->name,
                'tax_id' => $supplier->tax_number,
            ],
            'order_number' => "PO-ISSUE-{$suffix}",
            'order_date' => now()->toDateString(),
            'status' => $status,
            'total_amount' => 1000,
            'currency' => 'RUB',
            'sent_at' => $status === 'sent' ? now() : null,
            'confirmed_at' => in_array($status, ['confirmed', 'in_delivery'], true) ? now() : null,
        ]);
    }

    private function createSupplierRequest(Organization $organization, PurchaseRequest $purchaseRequest): SupplierRequest
    {
        return SupplierRequest::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'request_number' => "SR-ISSUE-{$purchaseRequest->id}",
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    private function createExpiredProposal(Organization $organization, SupplierRequest $supplierRequest): SupplierProposal
    {
        $supplier = Supplier::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Expired Proposal Supplier',
            'tax_number' => "7702000{$organization->id}",
            'is_active' => true,
        ]);

        return SupplierProposal::query()->create([
            'organization_id' => $organization->id,
            'supplier_request_id' => $supplierRequest->id,
            'supplier_id' => $supplier->id,
            'supplier_snapshot' => [
                'type' => 'registered',
                'display_name' => $supplier->name,
                'tax_id' => $supplier->tax_number,
            ],
            'proposal_number' => "KP-ISSUE-{$supplierRequest->id}",
            'proposal_date' => now()->subDays(3)->toDateString(),
            'status' => 'submitted',
            'valid_until' => now()->subDay()->toDateString(),
            'subtotal_amount' => 1000,
            'delivery_amount' => 0,
            'vat_amount' => 0,
            'total_amount' => 1000,
            'currency' => 'RUB',
        ]);
    }
}
