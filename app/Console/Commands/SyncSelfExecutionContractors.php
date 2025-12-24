<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Contractor\SelfExecutionService;

class SyncSelfExecutionContractors extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contractors:sync-self-execution
                            {--force : Force synchronization even if contractor already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Создать/обновить записи подрядчиков самоподряда для всех организаций';

    protected SelfExecutionService $selfExecutionService;

    /**
     * Create a new command instance.
     */
    public function __construct(SelfExecutionService $selfExecutionService)
    {
        parent::__construct();
        $this->selfExecutionService = $selfExecutionService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🚀 Начало синхронизации подрядчиков самоподряда...');
        $this->newLine();

        try {
            $result = $this->selfExecutionService->syncSelfExecutionContractors();

            $this->info("✅ Синхронизация завершена успешно!");
            $this->newLine();

            $this->table(
                ['Параметр', 'Значение'],
                [
                    ['Всего организаций', $result['total_organizations']],
                    ['Создано записей', $result['created']],
                    ['Пропущено (уже есть)', $result['skipped']],
                    ['Ошибок', $result['errors_count']],
                ]
            );

            if ($result['errors_count'] > 0) {
                $this->newLine();
                $this->warn("⚠️  Обнаружены ошибки:");
                foreach ($result['errors'] as $error) {
                    $this->error("  - Организация #{$error['organization_id']} ({$error['organization_name']}): {$error['error']}");
                }
                return Command::FAILURE;
            }

            $this->newLine();
            $this->info('🎉 Все записи подрядчиков самоподряда синхронизированы!');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Ошибка при синхронизации: ' . $e->getMessage());
            $this->error('Стек вызовов: ' . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

