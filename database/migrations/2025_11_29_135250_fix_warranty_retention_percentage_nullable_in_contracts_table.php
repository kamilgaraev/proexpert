<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Исправляет проблему: поле warranty_retention_percentage должно быть nullable,
     * чтобы можно было не передавать значение и использовать значение по умолчанию из БД (2.5)
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Явно указываем nullable() и default(2.5) для корректной работы
            $table->decimal('warranty_retention_percentage', 5, 3)->nullable()->default(2.5)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Возвращаем обратно к NOT NULL с default(2.5)
            // ВНИМАНИЕ: это может вызвать ошибки, если есть записи с NULL значениями
            $table->decimal('warranty_retention_percentage', 5, 3)->default(2.5)->change();
        });
    }
};
