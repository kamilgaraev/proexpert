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
            // Делаем total_amount nullable для поддержки контрактов с нефиксированной суммой
            // Для существующих контрактов значение сохранится (NOT NULL -> NULL разрешено)
            $table->decimal('total_amount', 15, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Возвращаем NOT NULL, устанавливая 0 для NULL значений
            DB::statement('UPDATE contracts SET total_amount = 0 WHERE total_amount IS NULL');
            $table->decimal('total_amount', 15, 2)->nullable(false)->change();
        });
    }
};
