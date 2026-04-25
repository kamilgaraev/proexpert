<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\BusinessModules\Core\Payments\Services\PaymentTransactionService;
use App\Models\Contract;
use Illuminate\Support\Collection;

class ContractPaymentDocumentService
{
    public function __construct(
        private readonly PaymentDocumentService $paymentDocumentService,
        private readonly PaymentTransactionService $paymentTransactionService,
    ) {
    }

    public function createPaidContractPayment(Contract $contract, array $data): PaymentDocument
    {
        $paymentType = (string) ($data['payment_type'] ?? 'other');
        $amount = (float) ($data['amount'] ?? 0);
        $paymentDate = $data['payment_date'] ?? now();

        $document = $this->paymentDocumentService->createFromContract(
            $contract,
            $this->mapContractPaymentTypeToInvoiceType($paymentType),
            [
                'amount' => $amount,
                'currency' => $data['currency'] ?? config('payments.defaults.currency', 'RUB'),
                'document_date' => $paymentDate,
                'due_date' => $paymentDate,
                'description' => $data['description'] ?? null,
                'metadata' => array_merge($data['metadata'] ?? [], [
                    'contract_payment_type' => $paymentType,
                    'reference_document_number' => $data['reference_document_number'] ?? null,
                ]),
            ],
        );

        $this->paymentTransactionService->registerPayment($document, [
            'amount' => $amount,
            'payment_method' => $data['payment_method'] ?? PaymentMethod::BANK_TRANSFER->value,
            'reference_number' => $data['reference_document_number'] ?? null,
            'transaction_date' => $paymentDate,
            'value_date' => $paymentDate,
            'notes' => $data['description'] ?? null,
            'metadata' => [
                'contract_payment_type' => $paymentType,
            ],
        ]);

        return $document->refresh();
    }

    public function getPaymentsForContract(int $contractId, array $filters = [], string $sortBy = 'document_date', string $sortDirection = 'desc'): Collection
    {
        $query = PaymentDocument::query()
            ->with('transactions')
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contractId)
            ->where('status', '!=', PaymentDocumentStatus::CANCELLED->value);

        if (! empty($filters['payment_type'])) {
            $query->where('metadata->contract_payment_type', $filters['payment_type']);
        }

        return $query
            ->orderBy($this->mapSortColumn($sortBy), $sortDirection)
            ->get();
    }

    public function getAdvancePaymentsSum(int $contractId): float
    {
        return (float) PaymentDocument::query()
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contractId)
            ->where('status', '!=', PaymentDocumentStatus::CANCELLED->value)
            ->where(function ($query): void {
                $query->where('invoice_type', InvoiceType::ADVANCE->value)
                    ->orWhere('metadata->contract_payment_type', 'advance');
            })
            ->sum('paid_amount');
    }

    public function getTotalPaidAmountForContract(int $contractId): float
    {
        return (float) PaymentDocument::query()
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contractId)
            ->where('status', '!=', PaymentDocumentStatus::CANCELLED->value)
            ->sum('paid_amount');
    }

    public function mapContractPaymentTypeToInvoiceType(string $paymentType): InvoiceType
    {
        return match ($paymentType) {
            'advance' => InvoiceType::ADVANCE,
            'deferred_payment' => InvoiceType::FINAL,
            'fact_payment', 'regular' => InvoiceType::PROGRESS,
            default => InvoiceType::OTHER,
        };
    }

    private function mapSortColumn(string $sortBy): string
    {
        return match ($sortBy) {
            'payment_date' => 'document_date',
            default => $sortBy,
        };
    }
}
