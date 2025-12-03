<?php

namespace App\BusinessModules\Features\Procurement\Listeners;

use App\BusinessModules\Features\Procurement\Events\PurchaseOrderCreated;
use App\Modules\Core\AccessController;

/**
 * Слушатель для автоматического создания счета на оплату
 * при создании заказа поставщику
 */
class CreateInvoiceFromPurchaseOrder
{
    public function __construct(
        private readonly AccessController $accessController
    ) {}

    /**
     * Handle the event.
     */
    public function handle(PurchaseOrderCreated $event): void
    {
        $order = $event->purchaseOrder;

        // Проверяем активацию модуля payments
        if (!$this->accessController->hasModuleAccess($order->organization_id, 'payments')) {
            \Log::info('procurement.skip_invoice_creation', [
                'purchase_order_id' => $order->id,
                'reason' => 'Модуль платежей не активирован',
            ]);
            return;
        }

        // Проверяем настройки модуля
        $module = app(\App\BusinessModules\Features\Procurement\ProcurementModule::class);
        $settings = $module->getSettings($order->organization_id);

        if (!($settings['auto_create_invoice'] ?? true)) {
            \Log::info('procurement.skip_invoice_creation', [
                'purchase_order_id' => $order->id,
                'reason' => 'Автоматическое создание счетов отключено в настройках',
            ]);
            return;
        }

        try {
            // Используем PaymentDocumentService для создания счета
            $paymentService = app(\App\BusinessModules\Core\Payments\Services\PaymentDocumentService::class);

            $invoice = $paymentService->createInvoice([
                'organization_id' => $order->organization_id,
                'supplier_id' => $order->supplier_id,
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'due_date' => $order->delivery_date,
                'description' => "Счет по заказу поставщику: {$order->order_number}",
                'metadata' => [
                    'purchase_order_id' => $order->id,
                    'purchase_order_number' => $order->order_number,
                ],
            ]);

            \Log::info('procurement.invoice.auto_created', [
                'purchase_order_id' => $order->id,
                'invoice_id' => $invoice->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.invoice.auto_create_failed', [
                'purchase_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

