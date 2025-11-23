<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Адаптер для обратной совместимости и интеграции Invoice ↔ PaymentDocument
 */
class LegacyPaymentAdapter
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly PaymentDocumentService $paymentDocumentService
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

    // ========================================
    // НОВАЯ ИНТЕГРАЦИЯ: Invoice ↔ PaymentDocument
    // ========================================

    /**
     * Создать PaymentDocument из Invoice для прохождения апрувалов
     */
    public function createPaymentDocumentFromInvoice(Invoice $invoice): PaymentDocument
    {
        // Проверить, что PaymentDocument еще не создан
        $existing = PaymentDocument::where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->first();

        if ($existing) {
            Log::info('payment_document.already_exists_for_invoice', [
                'invoice_id' => $invoice->id,
                'payment_document_id' => $existing->id,
            ]);
            return $existing;
        }

        // Определить тип документа на основе типа счета
        $documentType = $this->mapInvoiceTypeToDocumentType($invoice->invoice_type);

        // Определить плательщика и получателя
        $payerOrgId = null;
        $payerContractorId = null;
        $payeeOrgId = null;
        $payeeContractorId = null;

        if ($invoice->direction === InvoiceDirection::OUTGOING) {
            // Мы платим подрядчику
            $payerOrgId = $invoice->organization_id;
            $payeeContractorId = $invoice->contractor_id;
            $payeeOrgId = $invoice->counterparty_organization_id;
        } else {
            // Нам платит клиент
            $payeeOrgId = $invoice->organization_id;
            $payerContractorId = $invoice->contractor_id;
            $payerOrgId = $invoice->counterparty_organization_id;
        }

        // Расчет НДС если его нет в Invoice
        $vatRate = $invoice->vat_rate ?? 20;
        $amount = (float) $invoice->total_amount;
        
        if ($invoice->vat_amount === null || $invoice->amount_without_vat === null) {
            // Рассчитываем НДС (предполагаем что сумма включает НДС)
            $amountWithoutVat = $amount / (1 + $vatRate / 100);
            $vatAmount = $amount - $amountWithoutVat;
        } else {
            $vatAmount = (float) $invoice->vat_amount;
            $amountWithoutVat = (float) $invoice->amount_without_vat;
        }

        // Определить назначение платежа
        $paymentPurpose = $invoice->payment_terms ?? $invoice->description ?? 'Оплата по счёту ' . $invoice->invoice_number;

        $document = PaymentDocument::create([
            'organization_id' => $invoice->organization_id,
            'project_id' => $invoice->project_id,
            'document_type' => $documentType,
            'document_number' => $invoice->invoice_number,
            'document_date' => $invoice->invoice_date,
            'payer_organization_id' => $payerOrgId,
            'payer_contractor_id' => $payerContractorId,
            'payee_organization_id' => $payeeOrgId,
            'payee_contractor_id' => $payeeContractorId,
            'amount' => $amount,
            'currency' => $invoice->currency ?? 'RUB', // Fallback на RUB если не указана
            'vat_rate' => $vatRate,
            'vat_amount' => round($vatAmount, 2),
            'amount_without_vat' => round($amountWithoutVat, 2),
            'paid_amount' => $invoice->paid_amount ?? 0,
            'remaining_amount' => $invoice->remaining_amount ?? $amount,
            'status' => $this->mapInvoiceStatusToDocumentStatus($invoice->status),
            'source_type' => Invoice::class,
            'source_id' => $invoice->id,
            'due_date' => $invoice->due_date,
            'description' => $invoice->description,
            'payment_purpose' => $paymentPurpose,
            // Банковские реквизиты
            'bank_account' => $invoice->bank_account,
            'bank_bik' => $invoice->bank_bik,
            'bank_name' => $invoice->bank_name,
            'bank_correspondent_account' => $invoice->bank_correspondent_account,
            'metadata' => array_merge($invoice->metadata ?? [], [
                'created_from_invoice' => true,
                'invoice_type' => $invoice->invoice_type->value,
                'invoice_direction' => $invoice->direction->value,
            ]),
        ]);

        Log::info('payment_document.created_from_invoice', [
            'invoice_id' => $invoice->id,
            'payment_document_id' => $document->id,
            'document_type' => $document->document_type->value,
        ]);

        return $document;
    }

    /**
     * Получить PaymentDocument для Invoice (без создания)
     */
    public function getPaymentDocumentForInvoice(Invoice $invoice): ?PaymentDocument
    {
        return PaymentDocument::where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->first();
    }

    /**
     * Получить или создать PaymentDocument для Invoice
     */
    public function getOrCreatePaymentDocument(Invoice $invoice): PaymentDocument
    {
        $document = $this->getPaymentDocumentForInvoice($invoice);

        if (!$document) {
            $document = $this->createPaymentDocumentFromInvoice($invoice);
        }

        return $document;
    }

    /**
     * Синхронизировать статус Invoice → PaymentDocument
     */
    public function syncInvoiceToPaymentDocument(Invoice $invoice): ?PaymentDocument
    {
        $document = $this->getPaymentDocumentForInvoice($invoice);

        if (!$document) {
            return null;
        }

        // Синхронизируем все важные поля
        $document->update([
            'amount' => $invoice->total_amount,
            'paid_amount' => $invoice->paid_amount,
            'remaining_amount' => $invoice->remaining_amount,
            'status' => $this->mapInvoiceStatusToDocumentStatus($invoice->status),
            'payment_purpose' => $invoice->payment_terms,
            'description' => $invoice->description,
            'bank_account' => $invoice->bank_account,
            'bank_bik' => $invoice->bank_bik,
            'bank_name' => $invoice->bank_name,
            'bank_correspondent_account' => $invoice->bank_correspondent_account,
            'due_date' => $invoice->due_date,
        ]);

        Log::info('payment_document.synced_from_invoice', [
            'invoice_id' => $invoice->id,
            'payment_document_id' => $document->id,
        ]);

        return $document;
    }

    /**
     * Синхронизировать статус PaymentDocument → Invoice
     */
    public function syncPaymentDocumentToInvoice(PaymentDocument $document): ?Invoice
    {
        if ($document->source_type !== Invoice::class || !$document->source_id) {
            return null;
        }

        $invoice = Invoice::find($document->source_id);
        if (!$invoice) {
            return null;
        }

        // Синхронизируем суммы и статус
        $invoice->update([
            'paid_amount' => $document->paid_amount,
            'remaining_amount' => $document->remaining_amount,
            'status' => $this->mapDocumentStatusToInvoiceStatus($document->status),
        ]);

        Log::info('invoice.synced_from_payment_document', [
            'payment_document_id' => $document->id,
            'invoice_id' => $invoice->id,
        ]);

        return $invoice;
    }

    /**
     * Отправить Invoice на утверждение (создает PaymentDocument если нужно)
     */
    public function submitInvoiceForApproval(Invoice $invoice): PaymentDocument
    {
        DB::beginTransaction();

        try {
            // Получить или создать PaymentDocument
            $document = $this->getOrCreatePaymentDocument($invoice);

            // Если документ уже в процессе утверждения, вернуть его
            if ($document->status === PaymentDocumentStatus::PENDING_APPROVAL) {
                DB::commit();
                return $document;
            }

            // Синхронизировать данные из Invoice перед отправкой на утверждение
            // (на случай если документ был создан ранее, но Invoice был обновлен)
            if ($document->exists && $document->status === PaymentDocumentStatus::DRAFT) {
                $document = $this->syncInvoiceToPaymentDocument($invoice);
            }

            // Отправить на утверждение
            $document = $this->paymentDocumentService->submit($document);

            // Обновить статус Invoice (счёт переходит в статус "выставлен")
            // Workflow утверждения отслеживается в PaymentDocument, а не в Invoice
            $invoice->update([
                'status' => 'issued', // Invoice остаётся в статусе "выставлен"
                'metadata' => array_merge($invoice->metadata ?? [], [
                    'payment_document_id' => $document->id,
                    'submitted_for_approval_at' => now()->toDateTimeString(),
                ]),
            ]);

            DB::commit();

            Log::info('invoice.submitted_for_approval', [
                'invoice_id' => $invoice->id,
                'payment_document_id' => $document->id,
            ]);

            return $document;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Маппинг типа Invoice → PaymentDocument
     */
    private function mapInvoiceTypeToDocumentType(InvoiceType $invoiceType): PaymentDocumentType
    {
        return match($invoiceType) {
            InvoiceType::ADVANCE => PaymentDocumentType::PAYMENT_ORDER,
            InvoiceType::PROGRESS => PaymentDocumentType::PAYMENT_ORDER,
            InvoiceType::FINAL => PaymentDocumentType::PAYMENT_ORDER,
            InvoiceType::ACT => PaymentDocumentType::INVOICE,
            default => PaymentDocumentType::INVOICE,
        };
    }

    /**
     * Маппинг статуса Invoice → PaymentDocument
     */
    private function mapInvoiceStatusToDocumentStatus($status): PaymentDocumentStatus
    {
        // Если это enum, получаем значение
        $statusValue = $status instanceof \BackedEnum ? $status->value : $status;

        return match($statusValue) {
            'draft' => PaymentDocumentStatus::DRAFT,
            'issued' => PaymentDocumentStatus::SUBMITTED,
            'pending_approval' => PaymentDocumentStatus::PENDING_APPROVAL,
            'approved' => PaymentDocumentStatus::APPROVED,
            'partially_paid' => PaymentDocumentStatus::PARTIALLY_PAID,
            'paid' => PaymentDocumentStatus::PAID,
            'overdue' => PaymentDocumentStatus::APPROVED, // Просрочен, но утвержден
            'cancelled' => PaymentDocumentStatus::CANCELLED,
            default => PaymentDocumentStatus::DRAFT,
        };
    }

    /**
     * Маппинг статуса PaymentDocument → Invoice
     */
    private function mapDocumentStatusToInvoiceStatus(PaymentDocumentStatus $status)
    {
        return match($status) {
            PaymentDocumentStatus::DRAFT => 'draft',
            PaymentDocumentStatus::SUBMITTED => 'issued',
            PaymentDocumentStatus::PENDING_APPROVAL => 'pending_approval',
            PaymentDocumentStatus::APPROVED => 'approved',
            PaymentDocumentStatus::REJECTED => 'cancelled',
            PaymentDocumentStatus::SCHEDULED => 'issued',
            PaymentDocumentStatus::PARTIALLY_PAID => 'partially_paid',
            PaymentDocumentStatus::PAID => 'paid',
            PaymentDocumentStatus::CANCELLED => 'cancelled',
        };
    }

    /**
     * Массовая миграция существующих Invoice в PaymentDocument
     */
    public function migrateExistingInvoices(?int $organizationId = null, int $limit = 100): array
    {
        $query = Invoice::whereNotIn('status', ['cancelled', 'paid'])
            ->whereDoesntHave('paymentDocuments'); // Только те, для которых нет PaymentDocument

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $invoices = $query->limit($limit)->get();

        $migrated = [];
        $errors = [];

        foreach ($invoices as $invoice) {
            try {
                $document = $this->createPaymentDocumentFromInvoice($invoice);
                $migrated[] = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'payment_document_id' => $document->id,
                    'document_number' => $document->document_number,
                ];
            } catch (\Exception $e) {
                $errors[] = [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ];
                Log::error('invoice.migration_failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'migrated' => $migrated,
            'errors' => $errors,
            'total' => count($invoices),
            'success_count' => count($migrated),
            'error_count' => count($errors),
        ];
    }
}

