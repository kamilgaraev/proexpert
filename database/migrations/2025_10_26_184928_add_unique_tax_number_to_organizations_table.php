<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет уникальный индекс на tax_number в таблице organizations.
     * АВТОМАТИЧЕСКИ обрабатывает существующие дубликаты перед добавлением индекса.
     */
    public function up(): void
    {
        echo "\n🔍 [Migration] Starting organizations tax_number unique constraint migration\n";
        Log::info('[Migration] Starting organizations tax_number unique constraint migration');
        
        // Шаг 1: Обработка дубликатов tax_number
        $processedCount = $this->handleDuplicateTaxNumbers();
        echo "✅ [Migration] Processed {$processedCount} duplicate organizations\n";
        Log::info('[Migration] Processed duplicates', ['count' => $processedCount]);

        // Шаг 2: Проверка что дубликатов больше нет
        $remainingDuplicates = DB::table('organizations')
            ->select('tax_number')
            ->whereNull('deleted_at')
            ->whereNotNull('tax_number')
            ->where('tax_number', '!=', '')
            ->groupBy('tax_number')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($remainingDuplicates > 0) {
            echo "❌ [Migration] ERROR: Still have {$remainingDuplicates} duplicates after processing!\n";
            Log::error('[Migration] Still have duplicates after processing', ['count' => $remainingDuplicates]);
            throw new \Exception("Cannot add unique constraint: {$remainingDuplicates} duplicate tax_numbers still exist");
        }

        echo "✅ [Migration] No duplicates remaining, adding unique index...\n";
        
        // Шаг 3: Добавление уникального индекса
        Schema::table('organizations', function (Blueprint $table) {
            $table->unique('tax_number', 'organizations_tax_number_unique');
        });

        echo "🎉 [Migration] Unique index on organizations.tax_number added successfully!\n";
        Log::info('[Migration] Unique index on organizations.tax_number added successfully');
    }

    /**
     * Обработка дубликатов tax_number
     * 
     * Стратегия: Для каждого дублирующегося tax_number оставляем самую старую организацию,
     * а у остальных добавляем суффикс к tax_number чтобы сделать их уникальными.
     * 
     * @return int Количество обработанных дубликатов
     */
    private function handleDuplicateTaxNumbers(): int
    {
        Log::info('[Migration] Starting to check for duplicate tax_numbers...');
        
        // Сначала посмотрим сколько вообще записей с tax_number
        $totalWithTaxNumber = DB::table('organizations')
            ->whereNull('deleted_at')
            ->whereNotNull('tax_number')
            ->where('tax_number', '!=', '')
            ->count();
            
        Log::info('[Migration] Total organizations with tax_number: ' . $totalWithTaxNumber);
        
        // Находим дубликаты
        $duplicates = DB::table('organizations')
            ->select('tax_number', DB::raw('COUNT(*) as dup_count'))
            ->whereNull('deleted_at')
            ->whereNotNull('tax_number')
            ->where('tax_number', '!=', '')
            ->groupBy('tax_number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        Log::info('[Migration] Duplicate query returned ' . $duplicates->count() . ' results');
        echo "📊 [Migration] Found {$duplicates->count()} duplicate tax_numbers\n";

        if ($duplicates->isEmpty()) {
            echo "✅ [Migration] No duplicate tax_numbers found\n";
            Log::info('[Migration] No duplicate tax_numbers found in organizations');
            return 0;
        }

        echo "⚠️  [Migration] Processing {$duplicates->count()} duplicate tax_numbers...\n";
        Log::warning('[Migration] Found ' . $duplicates->count() . ' duplicate tax_numbers in organizations', [
            'duplicates' => $duplicates->map(function($d) {
                return [
                    'tax_number' => $d->tax_number,
                    'count' => $d->dup_count
                ];
            })->toArray()
        ]);

        $processedCount = 0;

        foreach ($duplicates as $duplicate) {
            Log::info('[Migration] Processing duplicate tax_number', [
                'tax_number' => $duplicate->tax_number,
                'count' => $duplicate->dup_count
            ]);
            
            // Получаем все организации с этим tax_number, сортируем по дате создания
            $organizations = DB::table('organizations')
                ->where('tax_number', $duplicate->tax_number)
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            if ($organizations->isEmpty()) {
                Log::warning('[Migration] No organizations found for tax_number', [
                    'tax_number' => $duplicate->tax_number
                ]);
                continue;
            }

            // Первую (самую старую) оставляем как есть
            $keepOrg = $organizations->first();
            Log::info('[Migration] Keeping organization', [
                'id' => $keepOrg->id,
                'name' => $keepOrg->name,
                'tax_number' => $keepOrg->tax_number,
                'created_at' => $keepOrg->created_at
            ]);

            // Остальные помечаем суффиксом
            $organizationsToUpdate = $organizations->skip(1);
            Log::info('[Migration] Will update ' . $organizationsToUpdate->count() . ' duplicate organizations');
            
            foreach ($organizationsToUpdate as $org) {
                $oldTaxNumber = $duplicate->tax_number; // Используем оригинальный ИНН из запроса
                $newTaxNumber = $oldTaxNumber . '-DUP-' . $org->id;
                
                Log::info('[Migration] About to update organization', [
                    'id' => $org->id,
                    'old_tax_number' => $org->tax_number,
                    'new_tax_number' => $newTaxNumber
                ]);
                
                $updated = DB::table('organizations')
                    ->where('id', $org->id)
                    ->update([
                        'tax_number' => $newTaxNumber,
                        'updated_at' => now()
                    ]);

                Log::warning('[Migration] Modified duplicate organization tax_number', [
                    'id' => $org->id,
                    'name' => $org->name,
                    'old_tax_number' => $org->tax_number,
                    'new_tax_number' => $newTaxNumber,
                    'updated_rows' => $updated,
                    'reason' => 'Duplicate - original kept in org #' . $keepOrg->id
                ]);

                if ($updated > 0) {
                    $processedCount++;
                } else {
                    Log::error('[Migration] Failed to update organization', [
                        'id' => $org->id,
                        'updated_rows' => $updated
                    ]);
                }
            }
        }

        Log::info('[Migration] Finished processing duplicates', [
            'processed_count' => $processedCount
        ]);

        return $processedCount;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique('organizations_tax_number_unique');
        });

        Log::info('[Migration] Unique index on organizations.tax_number removed');
    }
};
