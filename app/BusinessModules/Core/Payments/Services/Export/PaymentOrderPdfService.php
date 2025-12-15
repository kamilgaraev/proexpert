<?php

namespace App\BusinessModules\Core\Payments\Services\Export;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Barryvdh\DomPDF\Facade\Pdf;

class PaymentOrderPdfService
{
    /**
     * Generate PDF for Payment Order (Form 0401060)
     */
    public function generate(PaymentDocument $document): string
    {
        // Используем Blade-шаблон для генерации PDF
        $pdf = Pdf::loadView('payments.payment_order_pdf', [
            'document' => $document,
        ]);
        
        // Настройки для PDF
        $pdf->setPaper('a4', 'portrait');
        
        return $pdf->output();
    }
}

