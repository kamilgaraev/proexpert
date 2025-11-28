<?php

namespace App\Console\Commands;

use App\BusinessModules\Core\Payments\Models\Invoice;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\LegacyPaymentAdapter;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncInvoicesWithPaymentDocuments extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payments:sync-invoices-documents
                            {--invoice= : Синхронизировать конкретный счет по ID}
                            {--organization= : Синхронизировать счета конкретной организации}
                            {--limit=100 : Максимальное количество счетов для обработки}
                            {--dry-run : Показать что будет изменено без сохранения}';

    /**
     * The console command description.
     */
    protected $description = 'Синхронизировать данные между invoices и payment_documents (обновляет paid_amount, status, paid_at в invoices на основе payment_documents)';

    public function __construct(
        private readonly LegacyPaymentAdapter $legacyAdapter
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $invoiceId = $this->option('invoice');
        $organizationId = $this->option('organization');
        $limit = (int) $this->option('limit');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - изменения не будут сохранены');
        }

        $this->info('🚀 Начинаем синхронизацию invoices с payment_documents...');

        // Находим payment_documents, которые связаны с invoices и имеют статус paid или submitted
        $query = PaymentDocument::query()
            ->where('source_type', Invoice::class)
            ->whereNotNull('source_id')
            ->whereIn('status', [
                PaymentDocumentStatus::PAID,
                PaymentDocumentStatus::SUBMITTED,
            ])
            ->where('amount', '>', 0);

        if ($invoiceId) {
            $query->where('source_id', $invoiceId);
        }

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        // Сортируем по дате обновления (сначала самые свежие)
        $query->orderBy('updated_at', 'desc');

        $paymentDocuments = $query->limit($limit)->get();

        if ($paymentDocuments->isEmpty()) {
            $this->warn('⚠️  Payment documents для синхронизации не найдены');
            return self::SUCCESS;
        }

        $this->info("📊 Найдено payment documents для синхронизации: {$paymentDocuments->count()}");

        $synced = 0;
        $skipped = 0;
        $errors = 0;
        $notFound = 0;

        // Подготавливаем рефлексию для доступа к приватному методу (один раз перед циклом)
        $reflection = new \ReflectionClass($this->legacyAdapter);
        $statusMappingMethod = $reflection->getMethod('mapDocumentStatusToInvoiceStatus');
        $statusMappingMethod->setAccessible(true);

        $this->output->progressStart($paymentDocuments->count());

        foreach ($paymentDocuments as $document) {
            try {
                $invoice = Invoice::find($document->source_id);

                if (!$invoice) {
                    $this->newLine();
                    $this->warn("⚠️  Invoice ID {$document->source_id} не найден для payment_document ID {$document->id}");
                    $notFound++;
                    $this->output->progressAdvance();
                    continue;
                }

                // Проверяем, нужно ли обновлять invoice
                $needsUpdate = false;
                $changes = [];

                // Проверяем paid_amount
                if (abs((float) $invoice->paid_amount - (float) $document->paid_amount) > 0.01) {
                    $needsUpdate = true;
                    $changes['paid_amount'] = [
                        'old' => $invoice->paid_amount,
                        'new' => $document->paid_amount,
                    ];
                }

                // Проверяем remaining_amount
                if (abs((float) $invoice->remaining_amount - (float) $document->remaining_amount) > 0.01) {
                    $needsUpdate = true;
                    $changes['remaining_amount'] = [
                        'old' => $invoice->remaining_amount,
                        'new' => $document->remaining_amount,
                    ];
                }

                // Проверяем статус (используем рефлексию для доступа к приватному методу)
                $newStatus = $statusMappingMethod->invoke($this->legacyAdapter, $document->status);
                $currentStatus = $invoice->status instanceof \BackedEnum ? $invoice->status->value : $invoice->status;
                
                if ($currentStatus !== $newStatus) {
                    $needsUpdate = true;
                    $changes['status'] = [
                        'old' => $currentStatus,
                        'new' => $newStatus,
                    ];
                }

                // Проверяем paid_at (если документ оплачен и у invoice нет paid_at)
                if ($document->status === PaymentDocumentStatus::PAID && !$invoice->paid_at) {
                    $needsUpdate = true;
                    $changes['paid_at'] = [
                        'old' => null,
                        'new' => $document->updated_at ?? now(),
                    ];
                }

                if ($needsUpdate) {
                    $this->newLine();
                    $this->line("📝 Invoice ID {$invoice->id} ({$invoice->invoice_number}):");
                    
                    foreach ($changes as $field => $change) {
                        $oldValue = is_numeric($change['old']) 
                            ? number_format($change['old'], 2, '.', ' ') 
                            : ($change['old'] ?? 'NULL');
                        $newValue = is_numeric($change['new']) 
                            ? number_format($change['new'], 2, '.', ' ') 
                            : ($change['new'] ?? 'NULL');
                        
                        $this->line("   {$field}: {$oldValue} → {$newValue}");
                    }

                    if (!$dryRun) {
                        DB::transaction(function () use ($invoice, $document, $newStatus) {
                            $updateData = [
                                'paid_amount' => $document->paid_amount,
                                'remaining_amount' => $document->remaining_amount,
                                'status' => $newStatus,
                            ];

                            // Устанавливаем paid_at если документ оплачен
                            if ($document->status === PaymentDocumentStatus::PAID && !$invoice->paid_at) {
                                $updateData['paid_at'] = $document->updated_at ?? now();
                            }

                            $invoice->update($updateData);
                        });

                        $this->info("   ✅ Синхронизировано");
                        $synced++;
                    } else {
                        $this->warn("   🔍 Будет обновлено (dry-run)");
                    }
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("❌ Ошибка при синхронизации payment_document ID {$document->id}: {$e->getMessage()}");
                Log::error('payments.sync_invoices_documents.error', [
                    'payment_document_id' => $document->id,
                    'invoice_id' => $document->source_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $errors++;
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->newLine(2);
        $this->info('📊 Результаты синхронизации:');
        $this->line("   ✅ Синхронизировано: {$synced}");
        $this->line("   ⏭️  Пропущено (совпадают): {$skipped}");
        $this->line("   ⚠️  Invoices не найдены: {$notFound}");
        $this->line("   ❌ Ошибок: {$errors}");

        if ($dryRun && $synced > 0) {
            $this->newLine();
            $this->warn('💡 Запустите без --dry-run для применения изменений');
        }

        return self::SUCCESS;
    }

}

