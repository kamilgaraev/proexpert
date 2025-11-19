<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\Models\Contract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Адаптер для обратной совместимости со старой системой платежей
 * 
 * @deprecated Будет удален в версии 2.0
 */
class LegacyPaymentAdapter
{
    public function __construct(
        private readonly InvoiceService $invoiceService
    ) {}

    /**
     * Создать авансовый платеж (старый формат)
     * 
     * @deprecated Используйте InvoiceService::createInvoice()
     */
    public function createAdvancePayment(int $contractId, array $data): Invoice
    {
        Log::warning('payments.legacy_adapter.create_advance_payment', [
            'contract_id' => $contractId,
            'message' => 'Используется устаревший метод. Мигрируйте на InvoiceService::createInvoice()'
        ]);

        $contract = Contract::findOrFail($contractId);

        $invoiceData = [
            'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id,
            'contractor_id' => $contract->contractor_id,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => $contract->id,
            'invoice_date' => $data['payment_date'] ?? now(),
            'due_date' => $data['payment_date'] ?? now(),
            'direction' => InvoiceDirection::OUTGOING,
            'invoice_type' => InvoiceType::ADVANCE,
            'total_amount' => $data['amount'],
            'paid_amount' => isset($data['payment_date']) ? $data['amount'] : 0,
            'currency' => 'RUB',
            'description' => $data['description'] ?? 'Авансовый платеж',
            'status' => isset($data['payment_date']) ? 'paid' : 'issued',
        ];

        return $this->invoiceService->createInvoice($invoiceData);
    }

    /**
     * Получить авансовые платежи по договору (старый формат)
     * 
     * @deprecated Используйте Invoice::where('contract_id', $contractId)->where('invoice_type', 'advance')->get()
     */
    public function getAdvancePaymentsForContract(int $contractId): Collection
    {
        Log::warning('payments.legacy_adapter.get_advance_payments', [
            'contract_id' => $contractId,
            'message' => 'Используется устаревший метод.'
        ]);

        return Invoice::where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contractId)
            ->where('invoice_type', InvoiceType::ADVANCE)
            ->get();
    }

    /**
     * Обновить авансовый платеж (старый формат)
     * 
     * @deprecated Используйте InvoiceService::updateInvoice()
     */
    public function updateAdvancePayment(int $paymentId, array $data): Invoice
    {
        Log::warning('payments.legacy_adapter.update_advance_payment', [
            'payment_id' => $paymentId,
            'message' => 'Используется устаревший метод.'
        ]);

        // Ищем счет по старому ID в metadata
        $invoice = Invoice::whereJsonContains('metadata->old_payment_id', $paymentId)->first();

        if (!$invoice) {
            // Если не найден по metadata, ищем по ID
            $invoice = Invoice::findOrFail($paymentId);
        }

        $updateData = [];

        if (isset($data['amount'])) {
            $updateData['total_amount'] = $data['amount'];
        }

        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }

        if (isset($data['payment_date'])) {
            $updateData['invoice_date'] = $data['payment_date'];
            $updateData['due_date'] = $data['payment_date'];
            $updateData['paid_at'] = $data['payment_date'];
            $updateData['paid_amount'] = $invoice->total_amount;
            $updateData['status'] = 'paid';
        }

        return $this->invoiceService->updateInvoice($invoice, $updateData);
    }

    /**
     * Удалить авансовый платеж (старый формат)
     * 
     * @deprecated Используйте InvoiceService::cancelInvoice()
     */
    public function deleteAdvancePayment(int $paymentId): bool
    {
        Log::warning('payments.legacy_adapter.delete_advance_payment', [
            'payment_id' => $paymentId,
            'message' => 'Используется устаревший метод.'
        ]);

        $invoice = Invoice::whereJsonContains('metadata->old_payment_id', $paymentId)->first();

        if (!$invoice) {
            $invoice = Invoice::findOrFail($paymentId);
        }

        // Используем отмену вместо удаления
        $this->invoiceService->cancelInvoice($invoice, 'Удалено через legacy adapter');

        return true;
    }

    /**
     * Получить сумму всех авансов по договору
     * 
     * @deprecated Используйте прямой запрос к Invoice
     */
    public function getAdvancePaymentsSum(int $contractId): float
    {
        return Invoice::where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contractId)
            ->where('invoice_type', InvoiceType::ADVANCE)
            ->sum('paid_amount');
    }
}

