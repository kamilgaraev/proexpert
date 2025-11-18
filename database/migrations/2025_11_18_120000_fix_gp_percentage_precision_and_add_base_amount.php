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
        Schema::table('contracts', function (Blueprint $table) {
            // Добавляем поле base_amount (базовая сумма контракта ДО учета ГП)
            $table->decimal('base_amount', 15, 2)->nullable()->after('total_amount');
            
            // Изменяем точность gp_percentage с decimal(5,2) на decimal(5,3)
            // для поддержки тысячных (например, 0.944)
            $table->decimal('gp_percentage', 5, 3)->nullable()->default(0)->change();
        });
        
        // Мигрируем существующие данные:
        // Если base_amount пустой, копируем total_amount в base_amount
        // (для существующих контрактов БЕЗ ГП это правильно)
        DB::statement('UPDATE contracts SET base_amount = total_amount WHERE base_amount IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Возвращаем старую точность
            $table->decimal('gp_percentage', 5, 2)->nullable()->default(0)->change();
            
            // Удаляем base_amount
            $table->dropColumn('base_amount');
        });
    }
};

