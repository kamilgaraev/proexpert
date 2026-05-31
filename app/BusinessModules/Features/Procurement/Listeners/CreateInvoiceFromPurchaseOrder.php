<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Listeners;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\BusinessModules\Features\Procurement\Events\PurchaseOrderCreated;
use App\Models\Contract;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\Log;

class CreateInvoiceFromPurchaseOrder
{
    public function __construct(
        private readonly AccessController $accessController
    ) {}

    public function handle(PurchaseOrderCreated $event): void
    {
        $order = $event->purchaseOrder;

        if (! $this->accessController->hasModuleAccess($order->organization_id, 'payments')) {
            Log::info('procurement.skip_invoice_creation', [
                'purchase_order_id' => $order->id,
                'reason' => 'Модуль платежей не активирован',
            ]);

            return;
        }

        $module = app(\App\BusinessModules\Features\Procurement\ProcurementModule::class);
        $settings = $module->getSettings($order->organization_id);

        if (! ($settings['auto_create_invoice'] ?? true)) {
            Log::info('procurement.skip_invoice_creation', [
                'purchase_order_id' => $order->id,
                'reason' => 'Автоматическое создание платежных документов отключено',
            ]);

            return;
        }

        try {
            $order->loadMissing('contract');
            $contract = $order->contract;

            if (! $contract instanceof Contract || $contract->contractor_id === null) {
                Log::info('procurement.skip_invoice_creation', [
                    'purchase_order_id' => $order->id,
                    'reason' => 'Для заказа еще нет договора с контрагентом',
                ]);

                return;
            }

            $existingDocument = PaymentDocument::query()
                ->where('organization_id', $order->organization_id)
                ->where('metadata->purchase_order_id', $order->id)
                ->first();

            if ($existingDocument instanceof PaymentDocument) {
                return;
            }

            $invoice = app(PaymentDocumentService::class)->createPaymentOrder([
                'organization_id' => $order->organization_id,
                'project_id' => $contract->project_id,
                'document_date' => now()->toDateString(),
                'direction' => InvoiceDirection::OUTGOING->value,
                'invoice_type' => InvoiceType::MATERIAL_PURCHASE->value,
                'source_type' => Contract::class,
                'source_id' => $contract->id,
                'invoiceable_type' => Contract::class,
                'invoiceable_id' => $contract->id,
                'payer_organization_id' => $order->organization_id,
                'payee_contractor_id' => $contract->contractor_id,
                'contractor_id' => $contract->contractor_id,
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'due_date' => $order->delivery_date?->toDateString() ?? now()->addDays(7)->toDateString(),
                'description' => "Счет по заказу поставщику: {$order->order_number}",
                'payment_purpose' => "Оплата материалов по заказу {$order->order_number}",
                'metadata' => [
                    'purchase_order_id' => $order->id,
                    'purchase_order_number' => $order->order_number,
                ],
            ]);

            Log::info('procurement.invoice.auto_created', [
                'purchase_order_id' => $order->id,
                'invoice_id' => $invoice->id,
            ]);
        } catch (\Exception $e) {
            Log::error('procurement.invoice.auto_create_failed', [
                'purchase_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
