<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Procurement\Http\Middleware\EnsureProcurementActive;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementIssueService;
use App\BusinessModules\Features\Procurement\Services\ProcurementLifecycleService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPaymentGateService;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Http\Middleware\JwtMiddleware;
use App\Models\Organization;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\Support\AdminApiTestContext;
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

        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;
        $otherOrganization = Organization::factory()->create();

        $pendingRequest = $this->createPurchaseRequest($organization, 'PENDING', 'pending');
        $approvedRequest = $this->createPurchaseRequest($organization, 'APPROVED', 'approved');
        $approvedWithSupplierRequest = $this->createPurchaseRequest($organization, 'HAS-SR', 'approved');
        $this->createSupplierRequest($organization, $approvedWithSupplierRequest);
        $sentOrder = $this->createPurchaseOrder($organization, 'SENT', 'sent');
        $inDeliveryOrder = $this->createPurchaseOrder($organization, 'DELIVERY', 'in_delivery');

        $this->createPurchaseRequest($otherOrganization, 'OUTSIDE', 'pending');

        $response = $this->withHeaders($context->authHeaders())
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

        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $organization = $context->organization;

        $this->createPurchaseRequest($organization, 'PENDING', 'pending');
        $this->createPurchaseOrder($organization, 'DRAFT', 'draft');

        $response = $this->withHeaders($context->authHeaders())
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

    public function test_issue_facts_are_loaded_once_per_entity_and_payment_changes_remain_fresh(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $approved = $this->createPurchaseRequest($organization, 'BUDGET', 'approved');
        $secondApproved = $this->createPurchaseRequest($organization, 'BUDGET-SECOND', 'approved');
        $order = $this->createPurchaseOrder($organization, 'BUDGET', 'confirmed');
        $outside = $this->createPurchaseOrder($foreign, 'OUTSIDE', 'confirmed');
        $lifecycle = Mockery::mock(ProcurementLifecycleService::class, [app(PurchaseOrderPaymentGateService::class)])->makePartial();
        foreach ([$approved, $secondApproved] as $requestForSummary) {
            $lifecycle->shouldReceive('forPurchaseRequest')->with(Mockery::on(static fn (PurchaseRequest $request): bool => $request->id === $requestForSummary->id && $request->relationLoaded('lines')))->once()->passthru();
        }
        $lifecycle->shouldReceive('forPurchaseOrder')->with(Mockery::on(static fn (PurchaseOrder $purchaseOrder): bool => $purchaseOrder->id === $order->id))->twice()->passthru();
        $service = new ProcurementIssueService($lifecycle);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $first = $service->paginate((int) $organization->id, 'all', 1, 50);
            $queries = DB::getQueryLog();
            self::assertCount(1, array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'purchase_request_lines')));
            self::assertCount(2, array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'payment_documents')));
            self::assertCount(1, array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'purchase_receipt_lines')));
            self::assertSame(4, $first['summary']['total']);
            self::assertEqualsCanonicalizing([
                'purchase_request_without_order', 'purchase_request_without_order', 'purchase_order_confirmed_without_contract', 'purchase_order_confirmed_waiting_delivery',
            ], array_column($first['items'], 'type'));
            self::assertNotContains('PO-ISSUE-OUTSIDE', array_column($first['items'], 'entity_number'));
            self::assertSame(0, $first['summary']['waiting_receipt']);
            self::assertSame(2, $first['summary']['approved_without_order']);
            $approved->update(['status' => 'pending']);
            $secondApproved->update(['status' => 'pending']);
            PaymentDocument::query()->create([
                'organization_id' => $organization->id, 'payer_organization_id' => $organization->id,
                'document_type' => PaymentDocumentType::PAYMENT_ORDER, 'document_number' => 'PAY-ISSUE-FRESH',
                'document_date' => now()->toDateString(), 'direction' => InvoiceDirection::OUTGOING,
                'invoice_type' => InvoiceType::MATERIAL_PURCHASE, 'amount' => 1000, 'currency' => 'RUB',
                'paid_amount' => 1000, 'remaining_amount' => 0, 'status' => PaymentDocumentStatus::PAID,
                'paid_at' => now(), 'metadata' => ['purchase_order_id' => $order->id],
            ]);
            DB::flushQueryLog();
            $second = $service->paginate((int) $organization->id, 'all', 1, 50);
            $secondQueries = DB::getQueryLog();
            self::assertCount(0, array_filter($secondQueries, static fn (array $query): bool => str_contains($query['query'], 'purchase_request_lines')));
            self::assertCount(2, array_filter($secondQueries, static fn (array $query): bool => str_contains($query['query'], 'payment_documents')));
            self::assertCount(1, array_filter($secondQueries, static fn (array $query): bool => str_contains($query['query'], 'purchase_receipt_lines')));
            self::assertEqualsCanonicalizing([
                'purchase_request_pending', 'purchase_request_pending', 'purchase_order_confirmed_without_contract', 'purchase_order_confirmed_waiting_delivery',
            ], array_column($second['items'], 'type'));
            $orderIssues = collect($second['items'])->where('scope', 'purchase_orders');
            self::assertCount(2, $orderIssues);
            foreach ($orderIssues as $issue) {
                self::assertSame(trans_message('procurement.lifecycle.actions.receive_materials'), $issue['next_action']);
            }
            self::assertSame('confirmed', $outside->fresh()->status->value);
        } finally {
            DB::disableQueryLog();
        }
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
