<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\SupplementaryAgreement;
use App\Services\Contract\ContractStateEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateLegacyContractsToEventSourcing extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'contracts:migrate-to-event-sourcing
                            {--contract= : Мигрировать конкретный контракт по ID}
                            {--organization= : Мигрировать контракты конкретной организации}
                            {--dry-run : Показать что будет создано без сохранения}';

    /**
     * The console command description.
     */
    protected $description = 'Мигрировать legacy контракты (без Event Sourcing) в новую систему событий';

    public function __construct(
        private readonly ContractStateEventService $stateService
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

        $this->info('🚀 Начинаем миграцию legacy контрактов в Event Sourcing...');

        // Фильтруем контракты
        $query = Contract::query();

        if ($contractId) {
            $query->where('id', $contractId);
        }

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        // Только контракты БЕЗ Event Sourcing (у которых НЕТ событий)
        $query->whereDoesntHave('stateEvents');

        $contracts = $query->with('agreements')->get();

        if ($contracts->isEmpty()) {
            $this->warn('⚠️  Legacy контракты не найдены (все уже мигрированы)');
            return self::SUCCESS;
        }

        $this->info("📊 Найдено legacy контрактов для миграции: {$contracts->count()}");

        $migrated = 0;
        $errors = 0;

        $this->output->progressStart($contracts->count());

        foreach ($contracts as $contract) {
            try {
                $this->newLine();
                $this->line("📝 Контракт ID {$contract->id} ({$contract->number}):");
                $this->line("   Базовая сумма: " . number_format($contract->total_amount, 2, '.', ' ') . " руб.");
                $this->line("   Дополнительных соглашений: {$contract->agreements->count()}");

                if (!$dryRun) {
                    DB::transaction(function () use ($contract) {
                        // 1. Создаем начальное событие CREATED
                        $this->stateService->createContractCreatedEvent($contract);
                        
                        // 2. Создаем события для всех ДС
                        foreach ($contract->agreements as $agreement) {
                            $this->stateService->createSupplementaryAgreementEvent($contract, $agreement);
                        }
                        
                        // 3. Пересчитываем total_amount из событий
                        $currentState = $this->stateService->getCurrentState($contract->fresh());
                        $calculatedAmount = (float) $currentState['total_amount'];
                        
                        // 4. Обновляем контракт
                        $contract->total_amount = $calculatedAmount;
                        $contract->save();
                    });

                    // Перечитываем для вывода
                    $contract->refresh();
                    $currentState = $this->stateService->getCurrentState($contract);
                    $newAmount = (float) $currentState['total_amount'];

                    $this->line("   Событий создано: " . $contract->stateEvents->count());
                    $this->line("   Новая сумма: " . number_format($newAmount, 2, '.', ' ') . " руб.");
                    $this->info("   ✅ Мигрировано");
                    $migrated++;
                } else {
                    // Расчет в dry-run режиме
                    $calculatedAmount = $contract->total_amount;
                    foreach ($contract->agreements as $agreement) {
                        $calculatedAmount += $agreement->change_amount ?? 0;
                    }
                    
                    $this->line("   Будет создано событий: " . (1 + $contract->agreements->count()));
                    $this->line("   Будет установлена сумма: " . number_format($calculatedAmount, 2, '.', ' ') . " руб.");
                    $this->warn("   🔍 Будет мигрировано (dry-run)");
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("❌ Ошибка при миграции контракта ID {$contract->id}: {$e->getMessage()}");
                $this->error("   Trace: " . $e->getTraceAsString());
                $errors++;
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->newLine(2);
        $this->info('📊 Результаты миграции:');
        $this->line("   ✅ Мигрировано: {$migrated}");
        $this->line("   ❌ Ошибок: {$errors}");

        if ($dryRun && $migrated > 0) {
            $this->newLine();
            $this->warn('💡 Запустите без --dry-run для применения миграции');
        }

        return self::SUCCESS;
    }
}

