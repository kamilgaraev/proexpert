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
        // Добавить индекс на normative_rate_code в estimate_items для быстрого поиска
        if (!$this->indexExists('estimate_items', 'estimate_items_normative_rate_code_index')) {
            Schema::table('estimate_items', function (Blueprint $table) {
                $table->index('normative_rate_code', 'estimate_items_normative_rate_code_index');
            });
        }

        // Добавить composite индекс на normative_rates для ускорения поиска по коду и коллекции
        if (!$this->indexExists('normative_rates', 'normative_rates_code_collection_idx')) {
            Schema::table('normative_rates', function (Blueprint $table) {
                $table->index(['code', 'collection_id'], 'normative_rates_code_collection_idx');
            });
        }

        // Добавить GIN индекс для нормализованных кодов (без префиксов) в PostgreSQL
        // Это позволит быстро искать коды вариативно (с префиксами и без)
        if (config('database.default') === 'pgsql') {
            // Добавляем функцию для нормализации кодов
            DB::statement("
                CREATE OR REPLACE FUNCTION normalize_code(code_text TEXT) 
                RETURNS TEXT AS $$
                BEGIN
                    -- Убираем префиксы (ГЭСН, ФЕР, ТЕР, ФСБЦ и т.д.)
                    code_text := REGEXP_REPLACE(code_text, '^(ГЭСН|ФЕР|ТЕР|ФСБЦ|ФСБЦС|GESN|FER|TER|FSBC)[-\\s]?', '', 'i');
                    -- Заменяем точки на дефисы для единообразия
                    code_text := REPLACE(code_text, '.', '-');
                    -- Убираем лишние пробелы
                    code_text := REGEXP_REPLACE(code_text, '\\s+', '', 'g');
                    -- Приводим к верхнему регистру
                    RETURN UPPER(code_text);
                END;
                $$ LANGUAGE plpgsql IMMUTABLE;
            ");

            // Создаем функциональный индекс для нормализованных кодов
            DB::statement("
                CREATE INDEX IF NOT EXISTS normative_rates_normalized_code_idx 
                ON normative_rates (normalize_code(code))
            ");
        }

        // Индекс на estimate_items.code для быстрого доступа к импортированным кодам
        if (!$this->indexExists('estimate_items', 'estimate_items_code_idx')) {
            // Добавляем виртуальную колонку code если её нет
            if (!Schema::hasColumn('estimate_items', 'code')) {
                Schema::table('estimate_items', function (Blueprint $table) {
                    $table->string('code', 100)->nullable()->after('normative_rate_code');
                    $table->index('code', 'estimate_items_code_idx');
                });
            } else {
                Schema::table('estimate_items', function (Blueprint $table) {
                    $table->index('code', 'estimate_items_code_idx');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем индексы
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropIndex('estimate_items_normative_rate_code_index');
            if ($this->indexExists('estimate_items', 'estimate_items_code_idx')) {
                $table->dropIndex('estimate_items_code_idx');
            }
        });

        Schema::table('normative_rates', function (Blueprint $table) {
            $table->dropIndex('normative_rates_code_collection_idx');
        });

        // Удаляем PostgreSQL специфичные объекты
        if (config('database.default') === 'pgsql') {
            DB::statement("DROP INDEX IF EXISTS normative_rates_normalized_code_idx");
            DB::statement("DROP FUNCTION IF EXISTS normalize_code(TEXT)");
        }
    }

    /**
     * Проверка существования индекса
     */
    private function indexExists(string $table, string $index): bool
    {
        if (config('database.default') === 'pgsql') {
            $result = DB::selectOne(
                "SELECT EXISTS (
                    SELECT 1 
                    FROM pg_indexes 
                    WHERE tablename = ? 
                    AND indexname = ?
                ) as exists",
                [$table, $index]
            );
            
            return $result->exists ?? false;
        }
        
        // Для MySQL
        $result = DB::select(
            "SHOW INDEX FROM {$table} WHERE Key_name = ?",
            [$index]
        );
        
        return count($result) > 0;
    }
};

