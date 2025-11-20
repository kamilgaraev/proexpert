<?php

namespace App\BusinessModules\Core\Payments\Observers;

use App\BusinessModules\Core\Payments\Models\Invoice;
use App\BusinessModules\Core\Payments\Services\LegacyPaymentAdapter;
use Illuminate\Support\Facades\Log;

class InvoiceObserver
{
    public function __construct(
        private readonly LegacyPaymentAdapter $adapter
    ) {}

    /**
     * Handle the Invoice "created" event.
     * 
     * Автоматически создаем PaymentDocument для новых счетов (кроме draft)
     */
    public function created(Invoice $invoice): void
    {
        try {
            // Создаем PaymentDocument только если счет не в draft
            // Draft счета будут создавать PaymentDocument при переходе в issued
            if ($invoice->status->value !== 'draft') {
                $this->adapter->createPaymentDocumentFromInvoice($invoice);
                
                Log::info('invoice.payment_document_auto_created', [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'status' => $invoice->status->value,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('invoice.payment_document_auto_create_failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Invoice "updated" event.
     * 
     * Синхронизируем изменения с PaymentDocument
     */
    public function updated(Invoice $invoice): void
    {
        try {
            // Проверяем, изменился ли статус на issued или paid
            if ($invoice->wasChanged('status')) {
                $oldStatus = $invoice->getOriginal('status');
                $newStatus = $invoice->status->value;

                // Если переходим из draft в issued, создаем PaymentDocument
                if ($oldStatus === 'draft' && $newStatus === 'issued') {
                    $this->adapter->createPaymentDocumentFromInvoice($invoice);
                    
                    Log::info('invoice.payment_document_created_on_issue', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                    ]);
                }
            }

            // Синхронизируем суммы и статус если PaymentDocument уже существует
            if ($invoice->wasChanged(['paid_amount', 'remaining_amount', 'status'])) {
                $this->adapter->syncInvoiceToPaymentDocument($invoice);
            }
        } catch (\Exception $e) {
            Log::error('invoice.sync_to_payment_document_failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Invoice "deleted" event.
     * 
     * Отменяем связанный PaymentDocument при удалении Invoice
     */
    public function deleted(Invoice $invoice): void
    {
        try {
            $paymentDocument = $invoice->primaryPaymentDocument;
            
            if ($paymentDocument && $paymentDocument->canBeCancelled()) {
                $paymentDocument->update([
                    'status' => 'cancelled',
                    'notes' => ($paymentDocument->notes ?? '') . "\n\nОтменен: связанный счет был удален",
                ]);
                
                Log::info('invoice.payment_document_cancelled_on_delete', [
                    'invoice_id' => $invoice->id,
                    'payment_document_id' => $paymentDocument->id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('invoice.payment_document_cancel_failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

