<?php

namespace App\BusinessModules\Core\Payments\Console\Commands;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceStatus;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentType;
use App\BusinessModules\Core\Payments\Models\Invoice;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateInvoicesToPaymentDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:migrate-invoices-to-documents
                            {--organization= : ID организации для миграции (опционально)}
                            {--limit=100 : Количество счетов для обработки за раз}
                            {--dry-run : Показать что будет сделано, без реального выполнения}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Мигрировать все данные из Invoice в PaymentDocument (полная миграция)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $organizationId = $this->option('organization');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info("🔄 Начинаем полную миграцию Invoice → PaymentDocument");
        
        if ($organizationId) {
            $this->info("   Организация: {$organizationId}");
        } else {
            $this->info("   Организация: Все");
        }
        
        $this->info("   Лимит: {$limit} за раз");
        
        if ($dryRun) {
            $this->warn("   ⚠️  DRY RUN режим - реальные изменения не будут сохранены");
        }

        $this->newLine();

        $query = Invoice::query();

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $totalInvoices = $query->count();
        $invoices = $query->limit($limit)->get();

        $this->info("Найдено счетов для миграции: {$totalInvoices}");
        $this->info("Будет обработано: {$invoices->count()}");

        if ($invoices->isEmpty()) {
            $this->info("✅ Нет счетов для миграции");
            return Command::SUCCESS;
        }

        $migrated = [];
        $errors = [];
        $bar = $this->output->createProgressBar($invoices->count());
        $bar->start();

        foreach ($invoices as $invoice) {
            try {
                if ($dryRun) {
                    $this->newLine();
                    $this->line("Будет мигрирован Invoice #{$invoice->invoice_number} (ID: {$invoice->id})");
                    $migrated[] = [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                    ];
                } else {
                    DB::transaction(function () use ($invoice, &$migrated) {
                        // Проверяем, существует ли уже PaymentDocument для этого Invoice
                        $existingDoc = PaymentDocument::where('source_type', Invoice::class)
                            ->where('source_id', $invoice->id)
                            ->first();

                        if ($existingDoc) {
                            // Обновляем существующий документ всеми данными из Invoice
                            $this->updatePaymentDocumentFromInvoice($existingDoc, $invoice);
                            $docId = $existingDoc->id;
                        } else {
                            // Создаем новый PaymentDocument
                            $doc = $this->createPaymentDocumentFromInvoice($invoice);
                            $docId = $doc->id;
                        }

                        // Мигрируем PaymentTransaction
                        $this->migrateTransactions($invoice->id, $docId);

                        // Мигрируем PaymentSchedule
                        $this->migrateSchedules($invoice->id, $docId);

                        $migrated[] = [
                            'invoice_id' => $invoice->id,
                            'invoice_number' => $invoice->invoice_number,
                            'payment_document_id' => $docId,
                        ];
                    });
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number ?? 'N/A',
                    'error' => $e->getMessage(),
                ];
                Log::error('invoice.migration_failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Показываем результаты
        $this->info("✅ Миграция завершена!");
        $this->newLine();

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Всего обработано', count($invoices)],
                ['Успешно мигрировано', count($migrated)],
                ['Ошибок', count($errors)],
            ]
        );

        if (!empty($migrated) && !$dryRun) {
            $this->newLine();
            $this->info("Мигрированные счета:");
            $this->table(
                ['Invoice ID', 'Invoice #', 'PaymentDocument ID'],
                collect($migrated)->take(10)->map(fn($m) => [
                    $m['invoice_id'],
                    $m['invoice_number'],
                    $m['payment_document_id'] ?? 'N/A',
                ])->toArray()
            );

            if (count($migrated) > 10) {
                $remaining = count($migrated) - 10;
                $this->info("   ... и еще {$remaining} счетов");
            }
        }

        if (!empty($errors)) {
            $this->newLine();
            $this->error("Ошибки при миграции:");
            $this->table(
                ['Invoice ID', 'Invoice #', 'Ошибка'],
                collect($errors)->take(10)->map(fn($e) => [
                    $e['invoice_id'],
                    $e['invoice_number'],
                    substr($e['error'], 0, 50),
                ])->toArray()
            );
        }

        $this->newLine();
        
        if (count($invoices) == $limit && $totalInvoices > $limit) {
            $this->warn("⚠️  Достигнут лимит обработки ({$limit})");
            $this->info("   Осталось мигрировать: " . ($totalInvoices - $limit));
            $this->info("   Запустите команду повторно для продолжения.");
        } else {
            $this->info("✨ Все доступные счета мигрированы!");
        }

        return Command::SUCCESS;
    }

    /**
     * Создать PaymentDocument из Invoice
     */
    private function createPaymentDocumentFromInvoice(Invoice $invoice): PaymentDocument
    {
        // Определяем тип документа
        $documentType = $this->mapInvoiceTypeToDocumentType($invoice->invoice_type ?? InvoiceType::OTHER);
        
        // Определяем плательщика и получателя на основе direction
        $payerOrgId = null;
        $payerContractorId = null;
        $payeeOrgId = null;
        $payeeContractorId = null;

        if ($invoice->direction === InvoiceDirection::INCOMING) {
            // Нам должны - мы получатели
            $payeeOrgId = $invoice->organization_id;
            $payerOrgId = $invoice->counterparty_organization_id;
            $payerContractorId = $invoice->contractor_id;
        } else {
            // Мы должны - мы плательщики
            $payerOrgId = $invoice->organization_id;
            $payeeOrgId = $invoice->counterparty_organization_id;
            $payeeContractorId = $invoice->contractor_id;
        }

        $vatRate = $invoice->vat_rate ?? 20;
        $amount = (float) $invoice->total_amount;
        
        if ($invoice->vat_amount === null || $invoice->amount_without_vat === null) {
            $amountWithoutVat = $amount / (1 + $vatRate / 100);
            $vatAmount = $amount - $amountWithoutVat;
        } else {
            $vatAmount = (float) $invoice->vat_amount;
            $amountWithoutVat = (float) $invoice->amount_without_vat;
        }

        $paymentPurpose = $invoice->payment_purpose ?? $invoice->payment_terms ?? $invoice->description ?? 'Оплата по счёту ' . $invoice->invoice_number;

        return PaymentDocument::create([
            'organization_id' => $invoice->organization_id,
            'project_id' => $invoice->project_id,
            'document_type' => $documentType,
            'document_number' => $invoice->invoice_number,
            'document_date' => $invoice->invoice_date,
            'direction' => $invoice->direction,
            'invoice_type' => $invoice->invoice_type,
            'invoiceable_type' => $invoice->invoiceable_type,
            'invoiceable_id' => $invoice->invoiceable_id,
            'payer_organization_id' => $payerOrgId,
            'payer_contractor_id' => $payerContractorId,
            'payee_organization_id' => $payeeOrgId,
            'payee_contractor_id' => $payeeContractorId,
            'counterparty_organization_id' => $invoice->counterparty_organization_id,
            'contractor_id' => $invoice->contractor_id,
            'amount' => $amount,
            'currency' => $invoice->currency ?? 'RUB',
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
            'payment_terms' => $invoice->payment_terms,
            'bank_account' => $invoice->bank_account,
            'bank_bik' => $invoice->bank_bik,
            'bank_name' => $invoice->bank_name,
            'bank_correspondent_account' => $invoice->bank_correspondent_account,
            'issued_at' => $invoice->issued_at,
            'paid_at' => $invoice->paid_at,
            'overdue_since' => $invoice->overdue_since,
            'metadata' => array_merge($invoice->metadata ?? [], [
                'created_from_invoice' => true,
                'original_invoice_id' => $invoice->id,
            ]),
        ]);
    }

    /**
     * Обновить PaymentDocument данными из Invoice
     */
    private function updatePaymentDocumentFromInvoice(PaymentDocument $doc, Invoice $invoice): void
    {
        $doc->update([
            'direction' => $invoice->direction,
            'invoice_type' => $invoice->invoice_type,
            'invoiceable_type' => $invoice->invoiceable_type,
            'invoiceable_id' => $invoice->invoiceable_id,
            'counterparty_organization_id' => $invoice->counterparty_organization_id,
            'contractor_id' => $invoice->contractor_id,
            'amount' => $invoice->total_amount,
            'paid_amount' => $invoice->paid_amount,
            'remaining_amount' => $invoice->remaining_amount,
            'status' => $this->mapInvoiceStatusToDocumentStatus($invoice->status),
            'payment_terms' => $invoice->payment_terms,
            'issued_at' => $invoice->issued_at,
            'paid_at' => $invoice->paid_at,
            'overdue_since' => $invoice->overdue_since,
        ]);
    }

    /**
     * Мигрировать PaymentTransaction
     */
    private function migrateTransactions(int $invoiceId, int $paymentDocumentId): void
    {
        PaymentTransaction::where('invoice_id', $invoiceId)
            ->update(['payment_document_id' => $paymentDocumentId]);
    }

    /**
     * Мигрировать PaymentSchedule
     */
    private function migrateSchedules(int $invoiceId, int $paymentDocumentId): void
    {
        PaymentSchedule::where('invoice_id', $invoiceId)
            ->update(['payment_document_id' => $paymentDocumentId]);
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
        $statusValue = $status instanceof \BackedEnum ? $status->value : $status;

        return match($statusValue) {
            'draft' => PaymentDocumentStatus::DRAFT,
            'issued' => PaymentDocumentStatus::SUBMITTED,
            'pending_approval' => PaymentDocumentStatus::PENDING_APPROVAL,
            'approved' => PaymentDocumentStatus::APPROVED,
            'partially_paid' => PaymentDocumentStatus::PARTIALLY_PAID,
            'paid' => PaymentDocumentStatus::PAID,
            'overdue' => PaymentDocumentStatus::APPROVED,
            'cancelled' => PaymentDocumentStatus::CANCELLED,
            'written_off' => PaymentDocumentStatus::CANCELLED,
            default => PaymentDocumentStatus::DRAFT,
        };
    }
}

