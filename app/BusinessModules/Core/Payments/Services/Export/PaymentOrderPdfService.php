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
        $data = $this->prepareData($document);

        // In a real implementation, we would use a Blade view
        // $pdf = Pdf::loadView('payments::exports.payment-order', $data);
        
        // For now, we'll just generate a simple PDF to demonstrate
        $pdf = Pdf::loadHTML($this->getHtmlTemplate($data));
        
        return $pdf->output();
    }

    private function prepareData(PaymentDocument $document): array
    {
        return [
            'document_number' => $document->document_number,
            'date' => $document->document_date->format('d.m.Y'),
            'amount' => number_format($document->amount, 2, '-', ' '),
            'payer_name' => $document->getPayerName(),
            'payer_inn' => $document->payerOrganization->inn ?? $document->payerContractor->inn ?? '',
            'payer_kpp' => $document->payerOrganization->kpp ?? $document->payerContractor->kpp ?? '',
            'payer_account' => $document->bank_account ?? '', // Assuming payer account
            'payer_bank' => $document->bank_name ?? '', // Assuming payer bank
            'payer_bik' => $document->bank_bik ?? '', // Assuming payer bik
            'payer_corr_account' => $document->bank_correspondent_account ?? '', // Assuming payer corr
            
            'payee_name' => $document->getPayeeName(),
            'payee_inn' => $document->payeeOrganization->inn ?? $document->payeeContractor->inn ?? '',
            'payee_kpp' => $document->payeeOrganization->kpp ?? $document->payeeContractor->kpp ?? '',
            'payee_account' => '', // Needs to be added to model
            'payee_bank' => '', // Needs to be added to model
            'payee_bik' => '', // Needs to be added to model
            'payee_corr_account' => '', // Needs to be added to model
            
            'payment_purpose' => $document->payment_purpose,
            'priority' => 5, // Standard priority
        ];
    }

    private function getHtmlTemplate(array $data): string
    {
        // Simplified HTML for Form 0401060
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; }
        table { width: 100%; border-collapse: collapse; }
        td { border: 1px solid black; padding: 4px; vertical-align: top; }
        .header { text-align: center; font-weight: bold; }
        .no-border { border: none; }
        .bottom-border { border-bottom: 1px solid black; border-top: none; border-left: none; border-right: none; }
    </style>
</head>
<body>
    <div style="text-align: right; font-size: 8pt;">Приложение 2<br>к Положению Банка России<br>от 19 июня 2012 г. № 383-П</div>
    <br>
    <table class="no-border">
        <tr class="no-border">
            <td class="no-border" width="30%">
                ПОСТУП. В БАНК ПЛАТ.<br>
                ___________
            </td>
            <td class="no-border" width="30%">
                СПИСАНО СО СЧ. ПЛАТ.<br>
                ___________
            </td>
            <td class="no-border"></td>
        </tr>
    </table>

    <div style="margin-top: 10px;">
        <table style="border: none;">
            <tr>
                <td width="20%" style="border: 1px solid black;">ПЛАТЕЖНОЕ ПОРУЧЕНИЕ № {$data['document_number']}</td>
                <td width="15%" style="border: 1px solid black;">{$data['date']}</td>
                <td width="10%" style="border: 1px solid black;">Вид платежа<br> электронно</td>
            </tr>
        </table>
    </div>

    <table style="margin-top: 10px;">
        <tr>
            <td width="20%">Сумма</td>
            <td colspan="5">{$data['amount']}</td>
        </tr>
        <tr>
            <td colspan="2" width="40%">
                Плательщик<br>
                {$data['payer_name']}<br>
                ИНН {$data['payer_inn']} КПП {$data['payer_kpp']}
            </td>
            <td width="10%">Сч. №</td>
            <td colspan="3">{$data['payer_account']}</td>
        </tr>
        <tr>
            <td colspan="2">
                Банк плательщика<br>
                {$data['payer_bank']}
            </td>
            <td>БИК</td>
            <td colspan="3">{$data['payer_bik']}</td>
        </tr>
        <tr>
            <td colspan="2">
                Банк получателя<br>
                {$data['payee_bank']}
            </td>
            <td>БИК</td>
            <td colspan="3">{$data['payee_bik']}</td>
        </tr>
        <tr>
            <td colspan="2">
                Получатель<br>
                {$data['payee_name']}<br>
                ИНН {$data['payee_inn']} КПП {$data['payee_kpp']}
            </td>
            <td>Сч. №</td>
            <td colspan="3">{$data['payee_account']}</td>
        </tr>
        <tr>
            <td colspan="6">
                Назначение платежа<br>
                {$data['payment_purpose']}
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}

