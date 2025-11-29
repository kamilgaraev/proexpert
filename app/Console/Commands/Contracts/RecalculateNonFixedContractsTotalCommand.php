<?php

namespace App\Console\Commands\Contracts;

use App\Models\Contract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Команда для пересчета total_amount контрактов с нефиксированной суммой
 * 
 * Использование:
 * php artisan contracts:recalculate-non-fixed-total --all
 * php artisan contracts:recalculate-non-fixed-total --contract=92
 * php artisan contracts:recalculate-non-fixed-total --organization=14
 */
class RecalculateNonFixedContractsTotalCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contracts:recalculate-non-fixed-total
                            {--contract= : ID конкретного контракта для пересчета}
                            {--organization= : ID организации для пересчета всех контрактов}
                            {--all : Пересчитать все контракты с нефиксированной суммой}
                            {--dry-run : Показать что будет изменено без сохранения}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Пересчитать total_amount для контрактов с нефиксированной суммой на основе актов и ДС';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $contractId = $this->option('contract');
        $organizationId = $this->option('organization');
        $all = $this->option('all');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - изменения не будут сохранены');
        }

        $this->info('🚀 Начинаем пересчет total_amount для контрактов с нефиксированной суммой...');
        $this->newLine();

        // Формируем запрос
        $query = Contract::query()
            ->where('is_fixed_amount', false)
            ->with(['performanceActs', 'agreements']);

        if ($contractId) {
            $query->where('id', $contractId);
        } elseif ($organizationId) {
            $query->where('organization_id', $organizationId);
        } elseif (!$all) {
            $this->error('Укажите опцию --contract=ID, --organization=ID или --all');
            return Command::FAILURE;
        }

        $contracts = $query->get();
        $totalContracts = $contracts->count();

        if ($totalContracts === 0) {
            $this->warn('Контракты с нефиксированной суммой не найдены');
            return Command::SUCCESS;
        }

        $this->info("Найдено контрактов для пересчета: {$totalContracts}");
        $this->newLine();

        $bar = $this->output->createProgressBar($totalContracts);
        $bar->start();

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($contracts as $contract) {
            try {
                $oldTotalAmount = $contract->total_amount ?? 0;
                
                // Пересчитываем сумму
                $newTotalAmount = $contract->recalculateTotalAmountForNonFixed();

                if ($newTotalAmount === null) {
                    // Контракт с фиксированной суммой (не должен попасть в выборку, но на всякий случай)
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $difference = abs((float) $oldTotalAmount - $newTotalAmount);

                if ($difference > 0.01) {
                    if (!$dryRun) {
                        // Обновляем контракт
                        $contract->updateQuietly(['total_amount' => $newTotalAmount]);
                    }

                    $updated++;
                    
                    if ($this->getOutput()->isVerbose()) {
                        $this->newLine();
                        $this->line("  Контракт #{$contract->id} ({$contract->number}):");
                        $this->line("    Старая сумма: " . number_format($oldTotalAmount, 2, '.', ' ') . " руб.");
                        $this->line("    Новая сумма: " . number_format($newTotalAmount, 2, '.', ' ') . " руб.");
                        $this->line("    Разница: " . number_format($difference, 2, '.', ' ') . " руб.");
                        $this->line("    Актов: {$contract->performanceActs->where('is_approved', true)->count()}");
                        $this->line("    ДС: {$contract->agreements->count()}");
                    }
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $errors++;
                Log::error('contracts:recalculate-non-fixed-total.error', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                if ($this->getOutput()->isVerbose()) {
                    $this->newLine();
                    $this->error("  Ошибка при пересчете контракта #{$contract->id}: {$e->getMessage()}");
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Итоговая статистика
        $this->info('📊 Результаты пересчета:');
        $this->table(
            ['Статус', 'Количество'],
            [
                ['Обновлено', $updated],
                ['Пропущено (без изменений)', $skipped],
                ['Ошибок', $errors],
                ['Всего обработано', $totalContracts],
            ]
        );

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - изменения не были сохранены');
        } else {
            $this->info('✅ Пересчет завершен успешно');
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}

