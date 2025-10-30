<?php

namespace App\Console\Commands\Contracts;

use App\Models\Contract;
use App\Services\Contract\ContractStateCalculatorService;
use Illuminate\Console\Command;

class RecalculateContractStatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contracts:recalculate-states 
                            {--contract= : ID конкретного договора для пересчета}
                            {--all : Пересчитать все договоры с событиями}
                            {--force : Принудительный пересчет даже если состояние актуально}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Пересчитать материализованные представления состояний договоров из событий Event Sourcing';

    protected ContractStateCalculatorService $calculatorService;

    public function __construct(ContractStateCalculatorService $calculatorService)
    {
        parent::__construct();
        $this->calculatorService = $calculatorService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $contractId = $this->option('contract');
        $all = $this->option('all');
        $force = $this->option('force');

        if ($contractId) {
            // Пересчет конкретного договора
            return $this->recalculateContract((int)$contractId, $force);
        } elseif ($all) {
            // Массовый пересчет
            return $this->recalculateAll($force);
        } else {
            $this->error('Укажите опцию --contract=ID или --all');
            return Command::FAILURE;
        }
    }

    /**
     * Пересчет состояния конкретного договора
     */
    protected function recalculateContract(int $contractId, bool $force): int
    {
        $this->info("Пересчет состояния договора ID: {$contractId}...");

        $contract = Contract::find($contractId);
        if (!$contract) {
            $this->error("Договор с ID {$contractId} не найден");
            return Command::FAILURE;
        }

        if (!$contract->usesEventSourcing()) {
            $this->warn("Договор ID {$contractId} не использует Event Sourcing (legacy)");
            return Command::SUCCESS;
        }

        try {
            $this->calculatorService->recalculateContractState($contract);
            $this->info("✓ Состояние договора ID {$contractId} успешно пересчитано");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("✗ Ошибка при пересчете: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Массовый пересчет всех договоров
     */
    protected function recalculateAll(bool $force): int
    {
        $this->info("Начат массовый пересчет состояний договоров...");

        $contracts = Contract::whereHas('stateEvents')->get();
        $total = $contracts->count();

        if ($total === 0) {
            $this->warn("Не найдено договоров с событиями Event Sourcing");
            return Command::SUCCESS;
        }

        $this->info("Найдено договоров с событиями: {$total}");
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($contracts as $contract) {
            try {
                $this->calculatorService->recalculateContractState($contract);
                $success++;
            } catch (\Exception $e) {
                $failed++;
                $this->newLine();
                $this->error("Ошибка при пересчете договора ID {$contract->id}: " . $e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        
        $this->info("Пересчет завершен:");
        $this->info("  ✓ Успешно: {$success}");
        if ($failed > 0) {
            $this->warn("  ✗ Ошибок: {$failed}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
