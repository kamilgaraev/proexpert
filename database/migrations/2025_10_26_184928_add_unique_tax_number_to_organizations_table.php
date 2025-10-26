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
        // Шаг 1: Обработка дубликатов tax_number
        $this->handleDuplicateTaxNumbers();

        // Шаг 2: Добавление уникального индекса
        Schema::table('organizations', function (Blueprint $table) {
            // Добавляем уникальный индекс на tax_number
            // Используем whereNotNull чтобы разрешить NULL значения
            $table->unique('tax_number', 'organizations_tax_number_unique');
        });

        Log::info('[Migration] Unique index on organizations.tax_number added successfully');
    }

    /**
     * Обработка дубликатов tax_number
     * 
     * Стратегия: Для каждого дублирующегося tax_number оставляем самую старую организацию,
     * а у остальных добавляем суффикс к tax_number чтобы сделать их уникальными.
     */
    private function handleDuplicateTaxNumbers(): void
    {
        // Находим дубликаты
        $duplicates = DB::table('organizations')
            ->select('tax_number', DB::raw('COUNT(*) as count'))
            ->whereNull('deleted_at')
            ->whereNotNull('tax_number')
            ->where('tax_number', '!=', '')
            ->groupBy('tax_number')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isEmpty()) {
            Log::info('[Migration] No duplicate tax_numbers found in organizations');
            return;
        }

        Log::warning('[Migration] Found ' . $duplicates->count() . ' duplicate tax_numbers in organizations', [
            'duplicates' => $duplicates->pluck('tax_number')->toArray()
        ]);

        $processedCount = 0;

        foreach ($duplicates as $duplicate) {
            // Получаем все организации с этим tax_number, сортируем по дате создания
            $organizations = DB::table('organizations')
                ->where('tax_number', $duplicate->tax_number)
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            // Первую (самую старую) оставляем как есть
            $keepOrg = $organizations->first();
            Log::info('[Migration] Keeping organization', [
                'id' => $keepOrg->id,
                'name' => $keepOrg->name,
                'tax_number' => $keepOrg->tax_number,
                'created_at' => $keepOrg->created_at
            ]);

            // Остальные помечаем суффиксом
            $duplicateOrgs = $organizations->skip(1);
            foreach ($duplicateOrgs as $index => $org) {
                $newTaxNumber = $org->tax_number . '-DUP-' . $org->id;
                
                DB::table('organizations')
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
                    'reason' => 'Duplicate - original kept in org #' . $keepOrg->id
                ]);

                $processedCount++;
            }
        }

        Log::info('[Migration] Processed ' . $processedCount . ' duplicate organizations');
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
