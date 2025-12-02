<?php

namespace App\BusinessModules\Core\Payments\Jobs;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
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
    public function handle(PaymentDocumentService $documentService): void
    {
        // Найти все неоплаченные документы с просроченным due_date
        $overdueDocuments = PaymentDocument::whereIn('status', [
                PaymentDocumentStatus::SUBMITTED,
                PaymentDocumentStatus::APPROVED,
                PaymentDocumentStatus::PARTIALLY_PAID,
                PaymentDocumentStatus::SCHEDULED,
            ])
            ->where('due_date', '<', Carbon::now())
            ->whereNull('overdue_since')
            ->get();

        foreach ($overdueDocuments as $document) {
            $document->update([
                'overdue_since' => now(),
            ]);
        }

        \Log::info('payments.overdue.processed', [
            'count' => $overdueDocuments->count(),
        ]);
    }
}

