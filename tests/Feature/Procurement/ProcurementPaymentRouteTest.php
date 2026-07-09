<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequestLine;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProcurementPaymentRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_order_without_payment_document_requires_invoice_creation(): void
    {
        $order = $this->createPurchaseOrder();

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($order);

        $this->assertSame('payment_document_missing', $summary->currentStage->key);
        $this->assertSame('create_or_open_payment_document', $summary->nextAction?->key);
        $this->assertSame('payment_document_missing', $summary->blockers->first()?->key);
    }

    public function test_payment_document_route_moves_from_draft_to_approval_to_registration(): void
    {
        $this->assertPaymentRoute(
            PaymentDocumentStatus::DRAFT,
            'payment_document_draft',
            'submit_payment_document',
            'payment_document_not_submitted',
        );

        $this->assertPaymentRoute(
            PaymentDocumentStatus::PENDING_APPROVAL,
            'payment_approval_required',
            'approve_payment_document',
            'payment_approval_required',
        );

        $this->assertPaymentRoute(
            PaymentDocumentStatus::APPROVED,
            'payment_approved',
            'register_payment',
            'payment_registration_required',
        );
    }

    public function test_fully_paid_order_allows_material_receipt(): void
    {
        $order = $this->createPurchaseOrder();
        $this->createPaymentDocument($order, PaymentDocumentStatus::PAID, 500.0);

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($order);

        $this->assertSame('payment_registered', $summary->currentStage->key);
        $this->assertSame('receive_materials', $summary->nextAction?->key);
        $this->assertFalse($summary->compact()->isBlocked);
    }

    private function assertPaymentRoute(
        PaymentDocumentStatus $status,
        string $expectedStage,
        string $expectedAction,
        string $expectedBlocker,
    ): void {
        $order = $this->createPurchaseOrder();
        $paymentDocument = $this->createPaymentDocument($order, $status, 0.0);

        $summary = app(ProcurementChainService::class)->forPurchaseOrder($order);

        $this->assertSame($expectedStage, $summary->currentStage->key);
        $this->assertSame($expectedAction, $summary->nextAction?->key);
        $this->assertSame('POST', $summary->nextAction?->method);
        $this->assertStringContainsString((string) $paymentDocument->id, (string) $summary->nextAction?->href);
        $this->assertSame($expectedBlocker, $summary->blockers->first()?->key);
    }

    private function createPurchaseOrder(): PurchaseOrder
    {
        $organization = Organization::factory()->create();
        $purchaseRequest = PurchaseRequest::query()->create([
            'organization_id' => $organization->id,
            'request_number' => 'PR-PAY-ROUTE-'.$organization->id.'-'.uniqid(),
            'status' => PurchaseRequestStatusEnum::APPROVED,
            'budget_currency' => 'RUB',
        ]);

        PurchaseRequestLine::query()->create([
            'purchase_request_id' => $purchaseRequest->id,
            'name' => 'Кирпич',
            'quantity' => 5,
            'unit' => 'шт',
        ]);

        $order = PurchaseOrder::query()->create([
            'organization_id' => $organization->id,
            'purchase_request_id' => $purchaseRequest->id,
            'order_number' => 'PO-PAY-ROUTE-'.$purchaseRequest->id.'-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 500,
            'currency' => 'RUB',
        ]);

        PurchaseOrderItem::query()->create([
            'purchase_order_id' => $order->id,
            'material_name' => 'Кирпич',
            'quantity' => 5,
            'unit' => 'шт',
            'unit_price' => 100,
            'total_price' => 500,
        ]);

        return $order;
    }

    private function createPaymentDocument(
        PurchaseOrder $order,
        PaymentDocumentStatus $status,
        float $paidAmount
    ): PaymentDocument {
        return PaymentDocument::query()->create([
            'organization_id' => $order->organization_id,
            'document_type' => PaymentDocumentType::PAYMENT_ORDER,
            'document_number' => 'PAY-PAY-ROUTE-'.$order->id.'-'.uniqid(),
            'document_date' => now()->toDateString(),
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::MATERIAL_PURCHASE,
            'payer_organization_id' => $order->organization_id,
            'payee_organization_id' => $order->organization_id,
            'amount' => 500,
            'currency' => 'RUB',
            'paid_amount' => $paidAmount,
            'remaining_amount' => max(500 - $paidAmount, 0),
            'status' => $status,
            'paid_at' => $status === PaymentDocumentStatus::PAID ? now() : null,
            'metadata' => [
                'purchase_order_id' => $order->id,
                'purchase_order_number' => $order->order_number,
            ],
        ]);
    }
}
