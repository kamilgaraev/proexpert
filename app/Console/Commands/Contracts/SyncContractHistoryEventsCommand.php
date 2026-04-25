<?php

namespace App\Console\Commands\Contracts;

use App\Models\Contract;
use App\Models\SupplementaryAgreement;
use App\Models\ContractStateEvent;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Services\Contract\ContractStateEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncContractHistoryEventsCommand extends Command
{
    protected $signature = 'contracts:sync-history-events 
                          {--dry-run : Показать изменения без сохранения}
                          {--contract-id= : Обработать только указанный контракт}
                          {--skip-agreements : Пропустить создание событий для доп. соглашений}
                          {--skip-payments : Пропустить создание событий для платежей}';

    protected $description = 'Создает события истории для существующих доп. соглашений и платежей';

    protected ContractStateEventService $stateEventService;

    public function __construct(ContractStateEventService $stateEventService)
    {
        parent::__construct();
        $this->stateEventService = $stateEventService;
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $contractId = $this->option('contract-id');
        $skipAgreements = $this->option('skip-agreements');
        $skipPayments = $this->option('skip-payments');

        $this->info('🚀 Начинаю синхронизацию событий истории для контрактов...');
        if ($dryRun) {
            $this->warn('⚠️  Режим DRY-RUN: изменения не будут сохранены');
        }

        $stats = [
            'contracts_processed' => 0,
            'agreements_processed' => 0,
            'agreements_created' => 0,
            'agreements_skipped' => 0,
            'payments_processed' => 0,
            'payments_created' => 0,
            'payments_skipped' => 0,
            'errors' => [],
        ];

        // Получаем контракты для обработки
        $contractsQuery = Contract::query();
        
        if ($contractId) {
            $contractsQuery->where('id', $contractId);
        }

        $contracts = $contractsQuery->get();

        $this->info("📋 Найдено контрактов для обработки: {$contracts->count()}");

        if ($contracts->isEmpty()) {
            $this->warn('⚠️  Контракты не найдены');
            return self::FAILURE;
        }

        $progressBar = $this->output->createProgressBar($contracts->count());
        $progressBar->start();

        foreach ($contracts as $contract) {
            $stats['contracts_processed']++;

            try {
                // Проверяем, использует ли контракт Event Sourcing
                if (!$contract->usesEventSourcing()) {
                    $this->line("\n⚠️  Контракт #{$contract->id} не использует Event Sourcing - пропускаем");
                    $progressBar->advance();
                    continue;
                }

                // Обрабатываем доп. соглашения
                if (!$skipAgreements) {
                    $this->processAgreements($contract, $dryRun, $stats);
                }

                // Обрабатываем платежи
                if (!$skipPayments) {
                    $this->processPayments($contract, $dryRun, $stats);
                }

            } catch (\Exception $e) {
                $stats['errors'][] = "Контракт #{$contract->id}: {$e->getMessage()}";
                Log::error('Sync history events error', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Отчет
        $this->info('✅ Синхронизация завершена!');
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Обработано контрактов', $stats['contracts_processed']],
                ['Обработано доп. соглашений', $stats['agreements_processed']],
                ['Создано событий для доп. соглашений', $stats['agreements_created']],
                ['Пропущено доп. соглашений', $stats['agreements_skipped']],
                ['Обработано платежей', $stats['payments_processed']],
                ['Создано событий для платежей', $stats['payments_created']],
                ['Пропущено платежей', $stats['payments_skipped']],
                ['Ошибок', count($stats['errors'])],
            ]
        );

        if (!empty($stats['errors'])) {
            $this->error('❌ Ошибки:');
            foreach ($stats['errors'] as $error) {
                $this->error("  - {$error}");
            }
        }

        if ($dryRun) {
            $this->warn('⚠️  Это был режим DRY-RUN. Для применения изменений запустите команду без флага --dry-run');
        }

        return self::SUCCESS;
    }

    protected function processAgreements(Contract $contract, bool $dryRun, array &$stats): void
    {
        // Получаем все доп. соглашения контракта
        $agreements = SupplementaryAgreement::where('contract_id', $contract->id)->get();

        foreach ($agreements as $agreement) {
            $stats['agreements_processed']++;

            // Проверяем, существует ли уже событие для этого доп. соглашения
            $existingEvent = ContractStateEvent::where('contract_id', $contract->id)
                ->where('triggered_by_type', SupplementaryAgreement::class)
                ->where('triggered_by_id', $agreement->id)
                ->first();

            if ($existingEvent) {
                $stats['agreements_skipped']++;
                continue;
            }

            if ($dryRun) {
                $this->line("\n🔍 [DRY-RUN] Будет создано событие для доп. соглашения #{$agreement->id} (№{$agreement->number}) контракта #{$contract->id}");
                $stats['agreements_created']++;
            } else {
                try {
                    DB::beginTransaction();
                    $this->stateEventService->createSupplementaryAgreementEvent($contract, $agreement);
                    DB::commit();

                    $this->line("\n✅ Создано событие для доп. соглашения #{$agreement->id} (№{$agreement->number}) контракта #{$contract->id}");
                    $stats['agreements_created']++;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        }
    }

    protected function processPayments(Contract $contract, bool $dryRun, array &$stats): void
    {
        // Получаем все платежи контракта
        $payments = PaymentDocument::query()
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contract->id)
            ->get();

        foreach ($payments as $payment) {
            $stats['payments_processed']++;

            // Проверяем, существует ли уже событие для этого платежа
            $existingEvent = ContractStateEvent::where('contract_id', $contract->id)
                ->where('triggered_by_type', PaymentDocument::class)
                ->where('triggered_by_id', $payment->id)
                ->first();

            if ($existingEvent) {
                $stats['payments_skipped']++;
                continue;
            }

            if ($dryRun) {
                $this->line("\n🔍 [DRY-RUN] Будет создано событие для платежа #{$payment->id} (сумма: {$payment->amount} руб.) контракта #{$contract->id}");
                $stats['payments_created']++;
            } else {
                try {
                    DB::beginTransaction();
                    $this->stateEventService->createPaymentEvent($contract, $payment);
                    DB::commit();

                    $this->line("\n✅ Создано событие для платежа #{$payment->id} (сумма: {$payment->amount} руб.) контракта #{$contract->id}");
                    $stats['payments_created']++;
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        }
    }
}

