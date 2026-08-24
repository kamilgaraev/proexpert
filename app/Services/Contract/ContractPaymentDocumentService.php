<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Models\Contract;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContractPaymentDocumentService
{
    public function __construct(
        private readonly PaymentDocumentService $paymentDocumentService,
    ) {}

    public function createPaidContractPayment(Contract $contract, array $data): PaymentDocument
    {
        $paymentType = (string) ($data['payment_type'] ?? 'other');
        $amount = (string) BigDecimal::of((string) ($data['amount'] ?? 0))
            ->toScale(2, RoundingMode::HalfUp);
        $paymentDate = $data['payment_date'] ?? now();
        $idempotencyKey = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? (string) $data['idempotency_key']
            : null;

        return DB::transaction(function () use (
            $contract,
            $data,
            $paymentType,
            $amount,
            $paymentDate,
            $idempotencyKey,
        ): PaymentDocument {
            $document = $this->paymentDocumentService->createFromContract(
                $contract,
                $this->mapContractPaymentTypeToInvoiceType($paymentType),
                [
                    'amount' => $amount,
                    'currency' => $data['currency'] ?? config('payments.defaults.currency', 'RUB'),
                    'document_date' => $paymentDate,
                    'due_date' => $paymentDate,
                    'description' => $data['description'] ?? null,
                    'origin_key' => $idempotencyKey === null
                        ? null
                        : "contract-payment:{$contract->id}:{$idempotencyKey}",
                    'metadata' => array_merge($data['metadata'] ?? [], [
                        'contract_payment_type' => $paymentType,
                        'reference_document_number' => $data['reference_document_number'] ?? null,
                    ]),
                ],
            );

            return $this->paymentDocumentService->registerPayment($document, $amount, [
                'payment_method' => $data['payment_method'] ?? PaymentMethod::BANK_TRANSFER->value,
                'reference_number' => $data['reference_document_number'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'transaction_date' => $paymentDate,
                'value_date' => $paymentDate,
                'notes' => $data['description'] ?? null,
                'metadata' => [
                    'contract_payment_type' => $paymentType,
                ],
            ]);
        });
    }

    public function updateUnpaidDocument(PaymentDocument $document, array $data): PaymentDocument
    {
        return $this->paymentDocumentService->update($document, $data);
    }

    public function cancelDocument(PaymentDocument $document, string $reason): PaymentDocument
    {
        return $this->paymentDocumentService->cancel($document, $reason, auth()->user());
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
