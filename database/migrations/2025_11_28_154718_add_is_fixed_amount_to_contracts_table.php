<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Добавляем поле для указания, является ли сумма контракта фиксированной
            // false = нефиксированная сумма (например, охранные услуги, где сумма зависит от фактически оказанных услуг)
            // true = фиксированная сумма (по умолчанию для обратной совместимости)
            $table->boolean('is_fixed_amount')->default(true)->after('total_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('is_fixed_amount');
        });
    }
};
