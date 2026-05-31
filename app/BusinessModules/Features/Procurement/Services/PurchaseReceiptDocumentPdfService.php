<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PurchaseReceiptDocumentPdfService
{
    public function download(PurchaseOrder $order, array $document): Response
    {
        $pdf = Pdf::loadView('procurement.receipt-document', [
            'order' => $order,
            'document' => $document,
        ]);
        $pdf->setPaper('a4', 'landscape');

        return $pdf->download($this->filename($order, $document));
    }

    private function filename(PurchaseOrder $order, array $document): string
    {
        $number = (string) ($document['document_number'] ?? $order->order_number ?? $order->id);
        $safeNumber = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $number) ?: '';
        $safeNumber = trim($safeNumber, '-_.') ?: (string) $order->id;

        if ($safeNumber === (string) $order->id && $order->order_number) {
            $safeOrderNumber = preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $order->order_number) ?: '';
            $safeNumber = trim($safeOrderNumber, '-_.') ?: $safeNumber;
        }

        return sprintf('torg12-%s.pdf', $safeNumber);
    }
}
