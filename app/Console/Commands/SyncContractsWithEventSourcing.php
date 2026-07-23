<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractStateCalculatorService;
use App\Services\Contract\ContractStateEventService;
use Illuminate\Console\Command;

class SyncContractsWithEventSourcing extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'contracts:sync-event-sourcing
                            {--contract= : Синхронизировать конкретный контракт по ID}
                            {--organization= : Синхронизировать контракты конкретной организации}
                            {--dry-run : Показать что будет изменено без сохранения}';

    /**
     * The console command description.
     */
    protected $description = 'Синхронизировать total_amount контрактов с Event Sourcing событиями';

    public function __construct(
        private readonly ContractStateEventService $stateService,
        private readonly ContractStateCalculatorService $calculatorService,
        private readonly ContractAuditedMutationService $contractMutations,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $contractId = $this->option('contract');
        $organizationId = $this->option('organization');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - изменения не будут сохранены');
        }

        $this->info('🚀 Начинаем синхронизацию контрактов с Event Sourcing...');

        // Фильтруем контракты
        $query = Contract::query();

        if ($contractId) {
            $query->where('id', $contractId);
        }

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        // Только контракты с Event Sourcing (у которых есть события)
        $query->whereHas('stateEvents');

        $contracts = $query->get();

        if ($contracts->isEmpty()) {
            $this->warn('⚠️  Контракты с Event Sourcing не найдены');

            return self::SUCCESS;
        }

        $this->info("📊 Найдено контрактов для синхронизации: {$contracts->count()}");

        $synced = 0;
        $skipped = 0;
        $errors = 0;

        $this->output->progressStart($contracts->count());

        foreach ($contracts as $contract) {
            try {
                // Пересчитываем состояние
                $this->calculatorService->recalculateContractState($contract);
                $contract->refresh();

                $currentState = $this->stateService->getCurrentState($contract);
                $calculatedAmount = (float) $currentState['total_amount'];
                $dbAmount = (float) ($contract->total_amount ?? 0);

                // Проверяем расхождение
                if (abs($calculatedAmount - $dbAmount) > 0.01) {
                    $this->newLine();
                    $this->line("📝 Контракт ID {$contract->id} ({$contract->number}):");
                    $this->line('   Текущая сумма: '.number_format($dbAmount, 2, '.', ' ').' руб.');
                    $this->line('   Расчет из событий: '.number_format($calculatedAmount, 2, '.', ' ').' руб.');
                    $this->line('   Разница: '.number_format($calculatedAmount - $dbAmount, 2, '.', ' ').' руб.');

                    if (! $dryRun) {
                        $this->contractMutations->update(
                            $contract,
                            ['total_amount' => $calculatedAmount],
                            'event_sourcing_total_synced',
                            null,
                            ['source_event_id' => 'event_sourcing_sync:'.(string) $contract->id.':'.hash('sha256', (string) $calculatedAmount)],
                        );

                        $this->info('   ✅ Синхронизировано');
                        $synced++;
                    } else {
                        $this->warn('   🔍 Будет обновлено (dry-run)');
                    }
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("❌ Ошибка при синхронизации контракта ID {$contract->id}: {$e->getMessage()}");
                $errors++;
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->newLine(2);
        $this->info('📊 Результаты синхронизации:');
        $this->line("   ✅ Синхронизировано: {$synced}");
        $this->line("   ⏭️  Пропущено (совпадают): {$skipped}");
        $this->line("   ❌ Ошибок: {$errors}");

        if ($dryRun && $synced > 0) {
            $this->newLine();
            $this->warn('💡 Запустите без --dry-run для применения изменений');
        }

        return self::SUCCESS;
    }
}
