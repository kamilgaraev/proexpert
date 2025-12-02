<?php

namespace App\BusinessModules\Core\Payments\Console\Commands;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RestoreInvoiceableRelations extends Command
{
    protected $signature = 'payments:restore-invoiceable-relations
                            {--document-id= : ID конкретного документа для восстановления}
                            {--project-id= : Восстановить связи для всех документов проекта}
                            {--organization-id= : Восстановить связи для всех документов организации}
                            {--force : Принудительно обновить существующие связи}
                            {--dry-run : Показать что будет сделано, без реального выполнения}';

    protected $description = 'Восстановить потерянные связи invoiceable для PaymentDocument (ручной запуск)';

    public function handle(): int
    {
        $documentId = $this->option('document-id');
        $projectId = $this->option('project-id');
        $organizationId = $this->option('organization-id');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        $query = PaymentDocument::query();

        // Фильтры
        if ($documentId) {
            $query->where('id', $documentId);
        } elseif ($projectId) {
            $query->where('project_id', $projectId);
        } elseif ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        // Если не force, ищем только документы без связей
        if (!$force) {
            $query->whereNull('invoiceable_type')
                ->whereNull('invoiceable_id');
        }

        // Только документы с проектом
        $query->whereNotNull('project_id');

        $documents = $query->with(['project'])->get();

        if ($documents->isEmpty()) {
            $this->info('Нет документов для восстановления связей');
            return Command::SUCCESS;
        }

        $this->info("Найдено документов: {$documents->count()}");
        if ($dryRun) {
            $this->warn('⚠️  DRY RUN режим - реальные изменения не будут сохранены');
        }

        $restored = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($documents->count());
        $bar->start();

        foreach ($documents as $document) {
            try {
                // Определяем тип платежа из метаданных
                $invoiceType = $document->metadata['invoice_type'] ?? null;
                
                $contract = null;
                $act = null;

                if ($invoiceType === 'advance') {
                    // Для авансов ищем контракт по сумме аванса
                    $contract = $this->findContractByAdvanceAmount($document);
                    if ($contract) {
                        if (!$dryRun) {
                            $document->update([
                                'invoiceable_type' => Contract::class,
                                'invoiceable_id' => $contract->id,
                            ]);
                        }
                        $restored++;
                    }
                } elseif (in_array($invoiceType, ['act', 'progress', 'final'])) {
                    // Для платежей по факту ищем акт
                    $contract = $this->findContractByActsAmount($document);
                    if ($contract) {
                        $act = $this->findActByAmount($contract, $document->amount);
                        
                        if ($act) {
                            if (!$dryRun) {
                                $document->update([
                                    'invoiceable_type' => ContractPerformanceAct::class,
                                    'invoiceable_id' => $act->id,
                                ]);
                            }
                            $restored++;
                        } elseif ($contract) {
                            // Если акт не найден, но контракт найден - связываем с контрактом
                            if (!$dryRun) {
                                $document->update([
                                    'invoiceable_type' => Contract::class,
                                    'invoiceable_id' => $contract->id,
                                ]);
                            }
                            $restored++;
                        }
                    }
                } else {
                    // Если тип неизвестен, пытаемся найти по сумме актов
                    $contract = $this->findContractByActsAmount($document);
                    if ($contract) {
                        $act = $this->findActByAmount($contract, $document->amount);
                        
                        if ($act) {
                            if (!$dryRun) {
                                $document->update([
                                    'invoiceable_type' => ContractPerformanceAct::class,
                                    'invoiceable_id' => $act->id,
                                ]);
                            }
                            $restored++;
                        } elseif ($contract) {
                            if (!$dryRun) {
                                $document->update([
                                    'invoiceable_type' => Contract::class,
                                    'invoiceable_id' => $contract->id,
                                ]);
                            }
                            $restored++;
                        }
                    }
                }

                if (!$contract && !$act) {
                    $errors++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("  ✗ Документ #{$document->id}: {$e->getMessage()}");
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Статистика
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Всего обработано', $documents->count()],
                ['Восстановлено связей', $restored],
                ['Не найдено связей', $errors],
            ]
        );

        if ($restored > 0 && !$dryRun) {
            $this->info("✅ Успешно восстановлено {$restored} связей");
        } elseif ($restored > 0 && $dryRun) {
            $this->warn("⚠️  DRY RUN: {$restored} связей будут восстановлены при реальном запуске");
        }

        if ($errors > 0) {
            $this->warn("⚠️  Не удалось найти связи для {$errors} документов");
        }

        return Command::SUCCESS;
    }

    private function findContractByAdvanceAmount(PaymentDocument $document): ?Contract
    {
        if (!$document->project_id) {
            return null;
        }

        $contracts = Contract::where('project_id', $document->project_id)->get();
        $documentAmount = (float)$document->amount;

        foreach ($contracts as $contract) {
            // Проверяем planned_advance_amount
            if ($contract->planned_advance_amount && 
                abs((float)$contract->planned_advance_amount - $documentAmount) < 0.01) {
                return $contract;
            }
            
            // Проверяем actual_advance_amount
            if ($contract->actual_advance_amount && 
                abs((float)$contract->actual_advance_amount - $documentAmount) < 0.01) {
                return $contract;
            }
        }

        return null;
    }

    private function findContractByActsAmount(PaymentDocument $document): ?Contract
    {
        if (!$document->project_id) {
            return null;
        }

        $contracts = Contract::where('project_id', $document->project_id)
            ->with('performanceActs')
            ->get();

        foreach ($contracts as $contract) {
            $actsTotal = $contract->performanceActs->sum('amount');
            // Проверяем совпадение суммы (с погрешностью 0.01)
            if (abs($actsTotal - (float)$document->amount) < 0.01) {
                return $contract;
            }
        }

        return null;
    }

    private function findActByAmount(Contract $contract, string $amount): ?ContractPerformanceAct
    {
        $amountFloat = (float)$amount;
        
        return $contract->performanceActs()
            ->whereRaw('ABS(amount - ?) < 0.01', [$amountFloat])
            ->first();
    }
}

