<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Создание справочников ресурсов для смет
     * 
     * - machinery (механизмы/техника)
     * - labor_resources (трудозатраты/профессии)
     */
    public function up(): void
    {
        // ============================================
        // СПРАВОЧНИК МЕХАНИЗМОВ И ТЕХНИКИ
        // ============================================
        Schema::create('machinery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            
            // Основные данные
            $table->string('code', 100)->nullable()->comment('Код механизма (91.17.04-091)');
            $table->string('name')->comment('Название механизма');
            $table->text('description')->nullable();
            
            // Классификация
            $table->string('category')->nullable()->comment('Категория (экскаваторы, краны, автотранспорт)');
            $table->string('type')->nullable()->comment('Тип механизма');
            
            // Единица измерения
            $table->foreignId('measurement_unit_id')->nullable()->constrained()->onDelete('set null');
            
            // Технические характеристики
            $table->string('model')->nullable()->comment('Модель/марка');
            $table->string('manufacturer')->nullable()->comment('Производитель');
            $table->decimal('power', 10, 2')->nullable()->comment('Мощность (кВт, л.с.)');
            $table->decimal('capacity', 10, 2')->nullable()->comment('Грузоподъемность/производительность');
            $table->string('specifications')->nullable()->comment('Характеристики');
            
            // Стоимость
            $table->decimal('hourly_rate', 15, 2)->nullable()->comment('Стоимость маш.-час');
            $table->decimal('shift_rate', 15, 2)->nullable()->comment('Стоимость за смену');
            $table->decimal('daily_rate', 15, 2)->nullable()->comment('Стоимость за день');
            
            // Эксплуатация
            $table->decimal('fuel_consumption', 10, 2)->nullable()->comment('Расход топлива');
            $table->string('fuel_type')->nullable()->comment('Тип топлива');
            $table->decimal('maintenance_cost', 15, 2)->nullable()->comment('Стоимость обслуживания');
            
            // Дополнительные данные
            $table->jsonb('metadata')->nullable()->comment('Доп. данные');
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index(['organization_id', 'code']);
            $table->index(['organization_id', 'category']);
            $table->index(['organization_id', 'is_active']);
            $table->unique(['organization_id', 'code']);
        });
        
        // GIN индекс для полнотекстового поиска по механизмам
        DB::statement('CREATE INDEX machinery_name_gin_idx ON machinery USING GIN(name gin_trgm_ops)');
        DB::statement('CREATE INDEX machinery_code_gin_idx ON machinery USING GIN(code gin_trgm_ops)');

        // ============================================
        // СПРАВОЧНИК ТРУДОВЫХ РЕСУРСОВ
        // ============================================
        Schema::create('labor_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            
            // Основные данные
            $table->string('code', 100)->nullable()->comment('Код профессии/работы');
            $table->string('name')->comment('Название профессии/работы');
            $table->text('description')->nullable();
            
            // Классификация
            $table->string('category')->nullable()->comment('Категория (строители, монтажники, отделочники)');
            $table->string('profession')->nullable()->comment('Профессия');
            $table->integer('skill_level')->nullable()->comment('Разряд/квалификация');
            
            // Единица измерения (чел.-час, чел.-дн, чел.-смена)
            $table->foreignId('measurement_unit_id')->nullable()->constrained()->onDelete('set null');
            
            // Стоимость
            $table->decimal('hourly_rate', 15, 2)->nullable()->comment('Стоимость чел.-час');
            $table->decimal('shift_rate', 15, 2')->nullable()->comment('Стоимость за смену');
            $table->decimal('daily_rate', 15, 2')->nullable()->comment('Стоимость за день');
            $table->decimal('monthly_rate', 15, 2)->nullable()->comment('Месячный оклад');
            
            // Коэффициенты
            $table->decimal('coefficient', 10, 4)->default(1)->comment('Коэффициент к базовой ставке');
            $table->decimal('overhead_rate', 10, 4)->nullable()->comment('Накладные расходы');
            
            // Нормативы
            $table->decimal('work_hours_per_shift', 10, 2)->default(8)->comment('Часов в смену');
            $table->decimal('productivity_factor', 10, 4)->default(1)->comment('Коэффициент производительности');
            
            // Дополнительные данные
            $table->jsonb('metadata')->nullable()->comment('Доп. данные');
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Индексы
            $table->index(['organization_id', 'code']);
            $table->index(['organization_id', 'profession']);
            $table->index(['organization_id', 'category']);
            $table->index(['organization_id', 'skill_level']);
            $table->index(['organization_id', 'is_active']);
            $table->unique(['organization_id', 'code']);
        });
        
        // GIN индекс для полнотекстового поиска по трудовым ресурсам
        DB::statement('CREATE INDEX labor_resources_name_gin_idx ON labor_resources USING GIN(name gin_trgm_ops)');
        DB::statement('CREATE INDEX labor_resources_code_gin_idx ON labor_resources USING GIN(code gin_trgm_ops)');
        DB::statement('CREATE INDEX labor_resources_profession_gin_idx ON labor_resources USING GIN(profession gin_trgm_ops)');

        // ============================================
        // РАСШИРЕНИЕ ТАБЛИЦЫ ESTIMATE_ITEMS
        // ============================================
        if (!Schema::hasColumn('estimate_items', 'machinery_id')) {
            Schema::table('estimate_items', function (Blueprint $table) {
                $table->foreignId('machinery_id')->nullable()->after('material_id')->constrained('machinery')->onDelete('set null');
                $table->foreignId('labor_resource_id')->nullable()->after('machinery_id')->constrained('labor_resources')->onDelete('set null');
                
                // Индексы для быстрого поиска
                $table->index('machinery_id');
                $table->index('labor_resource_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropForeign(['machinery_id']);
            $table->dropForeign(['labor_resource_id']);
            $table->dropColumn(['machinery_id', 'labor_resource_id']);
        });
        
        // Удаляем GIN индексы
        DB::statement('DROP INDEX IF EXISTS machinery_name_gin_idx');
        DB::statement('DROP INDEX IF EXISTS machinery_code_gin_idx');
        DB::statement('DROP INDEX IF EXISTS labor_resources_name_gin_idx');
        DB::statement('DROP INDEX IF EXISTS labor_resources_code_gin_idx');
        DB::statement('DROP INDEX IF EXISTS labor_resources_profession_gin_idx');
        
        Schema::dropIfExists('labor_resources');
        Schema::dropIfExists('machinery');
    }
};

