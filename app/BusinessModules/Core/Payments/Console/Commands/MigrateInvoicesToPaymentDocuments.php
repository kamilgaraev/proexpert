<?php

namespace App\BusinessModules\Core\Payments\Console\Commands;

use App\BusinessModules\Core\Payments\Services\LegacyPaymentAdapter;
use Illuminate\Console\Command;

class MigrateInvoicesToPaymentDocuments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:migrate-invoices
                            {--organization= : ID организации для миграции (опционально)}
                            {--limit=100 : Количество счетов для обработки за раз}
                            {--dry-run : Показать что будет сделано, без реального выполнения}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Мигрировать существующие счета (Invoice) в PaymentDocuments для работы с апрувалами';

    /**
     * Execute the console command.
     */
    public function handle(LegacyPaymentAdapter $adapter): int
    {
        $organizationId = $this->option('organization');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info("🔄 Начинаем миграцию Invoice → PaymentDocument");
        
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

        if ($dryRun) {
            $this->info("В DRY RUN режиме только показываем что будет сделано...");
            $this->newLine();
            
            // TODO: Добавить логику dry-run если нужно
            $this->warn("DRY RUN режим пока не реализован полностью");
            return Command::SUCCESS;
        }

        // Запускаем миграцию
        $bar = $this->output->createProgressBar();
        $bar->start();

        try {
            $result = $adapter->migrateExistingInvoices($organizationId, $limit);
            
            $bar->finish();
            $this->newLine(2);

            // Показываем результаты
            $this->info("✅ Миграция завершена!");
            $this->newLine();

            $this->table(
                ['Метрика', 'Значение'],
                [
                    ['Всего обработано', $result['total']],
                    ['Успешно мигрировано', $result['success_count']],
                    ['Ошибок', $result['error_count']],
                ]
            );

            if (!empty($result['migrated'])) {
                $this->newLine();
                $this->info("Мигрированные счета:");
                $this->table(
                    ['Invoice ID', 'Invoice #', 'PaymentDocument ID', 'Document #'],
                    collect($result['migrated'])->take(10)->toArray()
                );

                if (count($result['migrated']) > 10) {
                    $remaining = count($result['migrated']) - 10;
                    $this->info("   ... и еще {$remaining} счетов");
                }
            }

            if (!empty($result['errors'])) {
                $this->newLine();
                $this->error("Ошибки при миграции:");
                $this->table(
                    ['Invoice ID', 'Ошибка'],
                    $result['errors']
                );
            }

            $this->newLine();
            
            if ($result['total'] == $limit) {
                $this->warn("⚠️  Достигнут лимит обработки ({$limit})");
                $this->info("   Возможно есть еще счета для миграции.");
                $this->info("   Запустите команду повторно для продолжения.");
            } else {
                $this->info("✨ Все доступные счета мигрированы!");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $bar->finish();
            $this->newLine(2);
            
            $this->error("❌ Ошибка при миграции:");
            $this->error("   {$e->getMessage()}");
            $this->newLine();
            $this->error("   Трейс: {$e->getTraceAsString()}");
            
            return Command::FAILURE;
        }
    }
}

