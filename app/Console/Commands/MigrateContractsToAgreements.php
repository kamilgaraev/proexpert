<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\SupplementaryAgreement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateContractsToAgreements extends Command
{
    protected $signature = 'contracts:migrate-to-agreements {--dry-run : Показать изменения без сохранения}';

    protected $description = 'Мигрирует "дочерние контракты" (Д/С) в таблицу supplementary_agreements';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $this->info('🚀 Начинаю миграцию "дочерних контрактов" в supplementary_agreements...');
        if ($dryRun) {
            $this->warn('⚠️  Режим DRY-RUN: изменения не будут сохранены');
        }

        $stats = [
            'processed' => 0,
            'migrated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        // Находим все контракты с parent_contract_id
        $childContracts = Contract::whereNotNull('parent_contract_id')->get();

        $this->info("📋 Найдено контрактов с parent_contract_id: {$childContracts->count()}");

        $progressBar = $this->output->createProgressBar($childContracts->count());
        $progressBar->start();

        foreach ($childContracts as $contract) {
            $stats['processed']++;

            try {
                // Проверяем, является ли это Д/С
                $isAgreement = $this->isSupplementaryAgreement($contract);

                if ($isAgreement) {
                    $this->migrateToAgreement($contract, $stats, $dryRun);
                } else {
                    $stats['skipped']++;
                    $this->warn("\n⚠️  Контракт #{$contract->id} ({$contract->number}) пропущен - не является Д/С");
                }
            } catch (\Exception $e) {
                $stats['errors'][] = "Контракт #{$contract->id}: {$e->getMessage()}";
                Log::error('Migration error', [
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
        $this->info('✅ Миграция завершена!');
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Обработано контрактов', $stats['processed']],
                ['Мигрировано в agreements', $stats['migrated']],
                ['Пропущено', $stats['skipped']],
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

        return 0;
    }

    protected function isSupplementaryAgreement(Contract $contract): bool
    {
        $parent = $contract->parentContract;
        
        if (!$parent) {
            return false;
        }

        // Проверяем критерии Д/С:
        // 1. Тот же organization_id
        // 2. Тот же contractor_id
        // 3. Номер содержит "Д/С" или "ДС"
        
        $sameOrganization = $contract->organization_id === $parent->organization_id;
        $sameContractor = $contract->contractor_id === $parent->contractor_id;
        $hasAgreementPattern = preg_match('/Д\/С|ДС|дополнительн|доп\.\s*согл/ui', $contract->number);

        return $sameOrganization && $sameContractor && $hasAgreementPattern;
    }

    protected function migrateToAgreement(Contract $contract, array &$stats, bool $dryRun): void
    {
        // ВАЖНО: Перезагружаем родителя из БД для получения актуальной суммы
        $parent = Contract::find($contract->parent_contract_id);

        $agreementData = [
            'contract_id' => $parent->id,
            'number' => $contract->number,
            'agreement_date' => $contract->date,
            'change_amount' => $contract->total_amount, // Полная сумма Д/С = изменение
            'subject_changes' => [
                'subject' => $contract->subject,
                'notes' => $contract->notes,
            ],
            'subcontract_changes' => $contract->subcontract_amount > 0 ? [
                'amount' => $contract->subcontract_amount,
            ] : null,
            'gp_changes' => $contract->gp_percentage != 0 || $contract->gp_coefficient != 0 ? [
                'percentage' => $contract->gp_percentage,
                'coefficient' => $contract->gp_coefficient,
                'calculation_type' => $contract->gp_calculation_type?->value,
            ] : null,
            'advance_changes' => $contract->planned_advance_amount > 0 ? [
                'planned_amount' => $contract->planned_advance_amount,
                'actual_amount' => $contract->actual_advance_amount,
            ] : null,
        ];

        if (!$dryRun) {
            DB::beginTransaction();
            try {
                // Создаем запись в supplementary_agreements
                $agreement = SupplementaryAgreement::create($agreementData);

                // Обновляем total_amount родительского контракта
                $parent->total_amount += $contract->total_amount;
                $parent->save();

                // Помечаем старый контракт как удаленный (soft delete)
                $contract->delete();

                DB::commit();

                $this->info("\n✅ Контракт #{$contract->id} ({$contract->number}) → Agreement #{$agreement->id}");
                $this->info("   Родитель #{$parent->id}: {$parent->total_amount} ₽ (было: " . ($parent->total_amount - $contract->total_amount) . " ₽)");

                Log::info('Contract migrated to agreement', [
                    'old_contract_id' => $contract->id,
                    'new_agreement_id' => $agreement->id,
                    'parent_contract_id' => $parent->id,
                    'change_amount' => $contract->total_amount,
                ]);

                $stats['migrated']++;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } else {
            $this->info("\n🔍 [DRY-RUN] Контракт #{$contract->id} ({$contract->number}):");
            $this->info("   Будет создан Agreement для контракта #{$parent->id}");
            $this->info("   change_amount: {$contract->total_amount} ₽");
            $this->info("   Новая сумма родителя: " . ($parent->total_amount + $contract->total_amount) . " ₽");
            $stats['migrated']++;
        }
    }
}

