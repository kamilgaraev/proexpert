<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use Carbon\Carbon;

class PaymentPurposeGenerator
{
    /**
     * Generate a payment purpose string based on document type and data
     */
    public function generate(PaymentDocumentType $type, array $data): string
    {
        $template = $this->getTemplate($type);
        
        return $this->replaceVariables($template, $data);
    }

    /**
     * Get the template for a specific document type
     * In a real scenario, this could come from database/settings
     */
    private function getTemplate(PaymentDocumentType $type): string
    {
        return match($type) {
            PaymentDocumentType::INVOICE => "Оплата по счету №{document_number} от {date}. В том числе НДС {vat_rate}% - {vat_amount}",
            PaymentDocumentType::PAYMENT_REQUEST => "Оплата по требованию №{document_number} от {date} по договору №{contract_number}. НДС не облагается",
            PaymentDocumentType::PAYMENT_ORDER => "Перечисление средств по п/п №{document_number} от {date}. {description}",
            default => "Оплата по документу №{document_number} от {date}",
        };
    }

    /**
     * Replace variables in the template
     */
    private function replaceVariables(string $template, array $data): string
    {
        $replacements = [
            '{document_number}' => $data['document_number'] ?? '___',
            '{date}' => isset($data['document_date']) ? Carbon::parse($data['document_date'])->format('d.m.Y') : date('d.m.Y'),
            '{contract_number}' => $data['contract_number'] ?? '',
            '{vat_rate}' => $data['vat_rate'] ?? '20',
            '{vat_amount}' => isset($data['vat_amount']) ? number_format($data['vat_amount'], 2, '.', '') : '0.00',
            '{amount}' => isset($data['amount']) ? number_format($data['amount'], 2, '.', '') : '0.00',
            '{description}' => $data['description'] ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}

