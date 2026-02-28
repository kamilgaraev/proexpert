<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Дедупликация существующих записей
        DB::transaction(function () {
            // Ищем все дубликаты по (organization_id, LOWER(short_name))
            $duplicates = DB::select("
                SELECT organization_id, LOWER(short_name) as normalized_name, COUNT(*) as cnt
                FROM measurement_units
                WHERE deleted_at IS NULL
                GROUP BY organization_id, LOWER(short_name)
                HAVING COUNT(*) > 1
            ");

            foreach ($duplicates as $duplicate) {
                // Получаем все ID дублей для конкретной нормализованной связки
                $units = DB::table('measurement_units')
                    ->where('organization_id', $duplicate->organization_id)
                    ->whereRaw('LOWER(short_name) = ?', [$duplicate->normalized_name])
                    ->whereNull('deleted_at')
                    ->orderBy('id', 'asc') // Берем самую старую запись как основную
                    ->get();

                if ($units->count() <= 1) {
                    continue; // На всякий случай
                }

                $masterId = $units->first()->id;
                $duplicateIds = $units->slice(1)->pluck('id')->toArray();

                // 2. Перенаправляем все внешние ключи на $masterId
                
                // Таблица материалов:
                DB::table('materials')
                    ->whereIn('measurement_unit_id', $duplicateIds)
                    ->update(['measurement_unit_id' => $masterId]);

                // Таблица позиций сметы (стандартно там unit_id или measurement_unit_id)
                if (Schema::hasColumn('estimate_items', 'measurement_unit_id')) {
                    DB::table('estimate_items')
                        ->whereIn('measurement_unit_id', $duplicateIds)
                        ->update(['measurement_unit_id' => $masterId]);
                }
                
                // Нормативы:
                if (Schema::hasColumn('normative_rates', 'measurement_unit_id')) {
                    DB::table('normative_rates')
                        ->whereIn('measurement_unit_id', $duplicateIds)
                        ->update(['measurement_unit_id' => $masterId]);
                }

                // Внутренние каталоги 
                if (Schema::hasColumn('estimate_position_catalog', 'measurement_unit_id')) {
                    DB::table('estimate_position_catalog')
                        ->whereIn('measurement_unit_id', $duplicateIds)
                        ->update(['measurement_unit_id' => $masterId]);
                }

                // 3. "Удаляем" дубликаты
                DB::table('measurement_units')
                    ->whereIn('id', $duplicateIds)
                    ->update(['deleted_at' => now()]);
            }
        });

        // 4. Добавляем уникальный индекс
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS measurement_units_org_short_name_unique ON measurement_units (organization_id, LOWER(short_name)) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS measurement_units_org_short_name_unique');
    }
};
