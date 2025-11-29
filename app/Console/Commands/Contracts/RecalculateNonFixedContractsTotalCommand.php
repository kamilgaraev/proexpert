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
        // Отключаем Observer'ы при загрузке, чтобы избежать автоматического обновления
        $query = Contract::withoutEvents()
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
                
                // Рассчитываем сумму вручную для проверки
                $actsAmount = $contract->performanceActs()
                    ->where('is_approved', true)
                    ->sum('amount') ?? 0;
                
                $agreementsAmount = $contract->agreements()
                    ->sum('change_amount') ?? 0;
                
                $calculatedTotal = round((float) $actsAmount + (float) $agreementsAmount, 2);
                
                // Пересчитываем сумму через метод модели
                $newTotalAmount = $contract->recalculateTotalAmountForNonFixed();

                if ($newTotalAmount === null) {
                    // Контракт с фиксированной суммой (не должен попасть в выборку, но на всякий случай)
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                // Используем рассчитанную сумму для сравнения (не из метода, который мог уже обновить)
                $difference = abs((float) $oldTotalAmount - $calculatedTotal);

                // Всегда показываем детали для отладки
                $this->newLine();
                $this->line("  Контракт #{$contract->id} ({$contract->number}):");
                $this->line("    Текущая сумма в БД: " . number_format($oldTotalAmount, 2, '.', ' ') . " руб.");
                $this->line("    Рассчитанная сумма: " . number_format($calculatedTotal, 2, '.', ' ') . " руб.");
                $this->line("    Сумма одобренных актов: " . number_format($actsAmount, 2, '.', ' ') . " руб.");
                $this->line("    Сумма ДС: " . number_format($agreementsAmount, 2, '.', ' ') . " руб.");
                $this->line("    Количество одобренных актов: {$contract->performanceActs->where('is_approved', true)->count()}");
                $this->line("    Количество ДС: {$contract->agreements->count()}");
                $this->line("    Разница: " . number_format($difference, 2, '.', ' ') . " руб.");

                if ($difference > 0.01) {
                    if (!$dryRun) {
                        // Обновляем контракт напрямую
                        DB::table('contracts')
                            ->where('id', $contract->id)
                            ->update(['total_amount' => $calculatedTotal]);
                        
                        // Обновляем значение в модели
                        $contract->total_amount = $calculatedTotal;
                    }

                    $updated++;
                    $this->info("    ✅ Будет обновлено на: " . number_format($calculatedTotal, 2, '.', ' ') . " руб.");
                } else {
                    $skipped++;
                    $this->comment("    ⏭️  Без изменений (разница < 0.01 руб.)");
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

