<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Listeners;

use App\BusinessModules\Features\Procurement\Events\PurchaseOrderCreated;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPaymentDocumentService;
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
            $result = app(PurchaseOrderPaymentDocumentService::class)->createOrOpen($order);
            $invoice = $result['document'];

            Log::info('procurement.invoice.auto_created', [
                'purchase_order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'created' => $result['created'],
            ]);
        } catch (\Exception $e) {
            Log::error('procurement.invoice.auto_create_failed', [
                'purchase_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
