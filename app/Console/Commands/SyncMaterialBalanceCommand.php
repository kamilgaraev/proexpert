<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MaterialBalance;
use App\Models\Models\Log\MaterialUsageLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncMaterialBalanceCommand extends Command
{
    protected $signature = 'materials:sync-balances {--force : Пересоздать все записи баланса}';
    protected $description = 'Синхронизировать остатки материалов из логов операций';

    public function handle(): int
    {
        $this->info('Начинаем синхронизацию остатков материалов...');

        if ($this->option('force')) {
            $this->info('Удаляем все существующие записи баланса...');
            MaterialBalance::truncate();
        }

        // Получаем уникальные комбинации organization_id, project_id, material_id из логов
        $combinations = MaterialUsageLog::select('organization_id', 'project_id', 'material_id')
            ->distinct()
            ->get();

        $created = 0;
        $updated = 0;

        foreach ($combinations as $combination) {
            try {
                DB::transaction(function () use ($combination, &$created, &$updated) {
                    $balance = MaterialBalance::firstOrNew([
                        'organization_id' => $combination->organization_id,
                        'project_id' => $combination->project_id,
                        'material_id' => $combination->material_id,
                    ]);

                    $currentQuantity = $this->calculateBalance(
                        $combination->organization_id,
                        $combination->project_id,
                        $combination->material_id
                    );

                    $isNew = !$balance->exists;

                    $balance->fill([
                        'available_quantity' => $currentQuantity,
                        'reserved_quantity' => 0,
                        'average_price' => 0,
                        'last_update_date' => now()->toDateString(),
                    ]);

                    $balance->save();

                    if ($isNew) {
                        $created++;
                    } else {
                        $updated++;
                    }
                });
            } catch (\Exception $e) {
                $this->error("Ошибка при обработке материала {$combination->material_id} проекта {$combination->project_id}: " . $e->getMessage());
                Log::error('Material balance sync error', [
                    'combination' => $combination->toArray(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info("Синхронизация завершена!");
        $this->info("Создано записей: {$created}");
        $this->info("Обновлено записей: {$updated}");

        return self::SUCCESS;
    }

    protected function calculateBalance(int $organizationId, int $projectId, int $materialId): float
    {
        $receipts = MaterialUsageLog::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('material_id', $materialId)
            ->where('operation_type', 'receipt')
            ->sum('quantity');

        $writeOffs = MaterialUsageLog::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('material_id', $materialId)
            ->where('operation_type', 'write_off')
            ->sum('quantity');

        return (float)($receipts - $writeOffs);
    }
} 