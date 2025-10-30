<?php

namespace App\Console\Commands\Contracts;

use App\Models\Contract;
use App\Models\ContractStateEvent;
use App\Services\Contract\ContractStateEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixContractHistoryEventsCommand extends Command
{
    protected $signature = 'contracts:fix-history-events 
                          {--dry-run : Показать изменения без сохранения}
                          {--contract-id= : Обработать только указанный контракт}
                          {--fix-wrong-amounts : Исправить события с неправильными amount_delta}';

    protected $description = 'Исправляет неправильные события истории контрактов';

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
        $fixWrongAmounts = $this->option('fix-wrong-amounts');

        $this->info('🔍 Проверка событий истории контрактов...');
        if ($dryRun) {
            $this->warn('⚠️  Режим DRY-RUN: изменения не будут сохранены');
        }

        $stats = [
            'contracts_processed' => 0,
            'events_checked' => 0,
            'events_fixed' => 0,
            'errors' => [],
        ];

        // Получаем контракты для обработки
        $contractsQuery = Contract::query();
        
        if ($contractId) {
            $contractsQuery->where('id', $contractId);
        }

        $contracts = $contractsQuery->get();

        $this->info("📋 Найдено контрактов для обработки: {$contracts->count()}");

        foreach ($contracts as $contract) {
            $stats['contracts_processed']++;

            try {
                if (!$contract->usesEventSourcing()) {
                    continue;
                }

                $activeEvents = ContractStateEvent::where('contract_id', $contract->id)
                    ->whereDoesntHave('supersededByEvents')
                    ->orderBy('created_at', 'asc')
                    ->get();

                // Рассчитываем сумму из событий (исключая платежи)
                $calculatedAmount = $activeEvents
                    ->filter(function ($event) {
                        return !in_array($event->event_type->value, ['payment_created']);
                    })
                    ->sum('amount_delta');

                $contractAmount = (float) ($contract->total_amount ?? 0);
                $difference = abs($calculatedAmount - $contractAmount);

                if ($difference > 0.01) {
                    $this->line("\n⚠️  Контракт #{$contract->id}: расхождение обнаружено");
                    $this->line("   Сумма контракта: {$contractAmount}");
                    $this->line("   Сумма из событий: {$calculatedAmount}");
                    $this->line("   Разница: {$difference}");

                    if ($fixWrongAmounts && !$dryRun) {
                        // Находим последнее событие AMENDED со спецификацией, которое может быть неправильным
                        $lastAmendedEvent = $activeEvents
                            ->where('event_type', 'amended')
                            ->whereNotNull('specification_id')
                            ->last();

                        if ($lastAmendedEvent) {
                            // Пересчитываем правильную дельту
                            $eventsBefore = $activeEvents
                                ->filter(function ($e) use ($lastAmendedEvent) {
                                    return $e->id < $lastAmendedEvent->id && 
                                           !in_array($e->event_type->value, ['payment_created']);
                                })
                                ->sum('amount_delta');

                            $correctDelta = $contractAmount - $eventsBefore;

                            if (abs($lastAmendedEvent->amount_delta - $correctDelta) > 0.01) {
                                $this->line("   Исправляю событие id:{$lastAmendedEvent->id}");
                                $this->line("   Старая дельта: {$lastAmendedEvent->amount_delta}");
                                $this->line("   Новая дельта: {$correctDelta}");

                                DB::table('contract_state_events')
                                    ->where('id', $lastAmendedEvent->id)
                                    ->update([
                                        'amount_delta' => $correctDelta,
                                        'metadata' => json_encode(array_merge(
                                            $lastAmendedEvent->metadata ?? [],
                                            [
                                                'old_amount' => $lastAmendedEvent->amount_delta,
                                                'new_amount' => $correctDelta,
                                                'fixed_by_command' => now()->toIso8601String(),
                                            ]
                                        )),
                                    ]);

                                $stats['events_fixed']++;
                            }
                        }
                    } elseif ($fixWrongAmounts) {
                        $this->line("   [DRY-RUN] Будет исправлено событие id:{$lastAmendedEvent->id ?? 'N/A'}");
                        $stats['events_fixed']++;
                    }

                    $stats['events_checked']++;
                }

            } catch (\Exception $e) {
                $stats['errors'][] = "Контракт #{$contract->id}: {$e->getMessage()}";
                Log::error('Fix history events error', [
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->newLine(2);
        $this->info('✅ Проверка завершена!');
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Обработано контрактов', $stats['contracts_processed']],
                ['Найдено расхождений', $stats['events_checked']],
                ['Исправлено событий', $stats['events_fixed']],
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
}

