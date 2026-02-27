<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Listeners;

use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\BusinessModules\Features\BasicWarehouse\Services\WarehouseReceiptFromPaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CreateWarehouseReceiptOnPayment implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'default';

    public function handle(PaymentDocumentPaid $event): void
    {
        try {
            app(WarehouseReceiptFromPaymentService::class)
                ->createFromPaymentDocument($event->document);
        } catch (\Exception $e) {
            Log::error('warehouse_receipt.listener_failed', [
                'document_id' => $event->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function failed(PaymentDocumentPaid $event, \Throwable $exception): void
    {
        Log::error('warehouse_receipt.listener_job_failed', [
            'document_id' => $event->document->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
