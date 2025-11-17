<?php

namespace App\BusinessModules\Core\Payments\Jobs;

use App\BusinessModules\Core\Payments\Enums\InvoiceStatus;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\BusinessModules\Core\Payments\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ProcessOverdueInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(InvoiceService $invoiceService): void
    {
        // Найти все неоплаченные счета с просроченным due_date
        $overdueInvoices = Invoice::whereIn('status', [
                InvoiceStatus::ISSUED,
                InvoiceStatus::PARTIALLY_PAID,
            ])
            ->where('due_date', '<', Carbon::now())
            ->get();

        foreach ($overdueInvoices as $invoice) {
            $invoiceService->markAsOverdue($invoice);
        }

        \Log::info('payments.overdue.processed', [
            'count' => $overdueInvoices->count(),
        ]);
    }
}

