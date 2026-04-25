<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract\Payment;

use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PaymentDocument
 */
class ContractPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PaymentDocument $document */
        $document = $this->resource;
        $metadata = $document->metadata ?? [];
        $invoiceType = $document->getAttribute('invoice_type');
        $invoiceTypeValue = $invoiceType instanceof \BackedEnum
            ? $invoiceType->value
            : (is_string($invoiceType) ? $invoiceType : null);
        $paymentType = $metadata['contract_payment_type'] ?? $this->mapInvoiceTypeToPaymentType($invoiceTypeValue);
        $referenceNumber = $metadata['reference_document_number'] ?? $this->whenLoaded(
            'transactions',
            fn () => $document->transactions->first()?->reference_number,
            null
        );

        return [
            'id' => $document->id,
            'contract_id' => $document->getAttribute('invoiceable_id'),
            'payment_date' => $document->document_date,
            'amount' => (float) ($document->paid_amount ?: $document->amount ?: 0),
            'payment_type' => $paymentType,
            'payment_type_label' => $this->paymentTypeLabel($paymentType),
            'reference_document_number' => $referenceNumber,
            'description' => $document->description,
            'created_at' => $document->created_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
        ];
    }

    private function mapInvoiceTypeToPaymentType(?string $invoiceType): string
    {
        return match ($invoiceType) {
            InvoiceType::ADVANCE->value => 'advance',
            InvoiceType::PROGRESS->value => 'fact_payment',
            InvoiceType::FINAL->value => 'deferred_payment',
            default => 'other',
        };
    }

    private function paymentTypeLabel(string $paymentType): string
    {
        return match ($paymentType) {
            'advance' => 'Аванс',
            'fact_payment', 'regular' => 'Оплата по факту',
            'deferred_payment' => 'Отложенный платеж',
            default => 'Другой платеж',
        };
    }
}
