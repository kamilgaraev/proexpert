<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('estimate_sections', function (Blueprint $table) {
            // Добавляем поле для хранения полного иерархического номера
            // Например: "1.2.3" для раздела третьего уровня
            $table->string('full_section_number', 100)->nullable()->after('section_number');
            
            // Индекс для быстрого поиска и сортировки
            $table->index('full_section_number');
        });

        // Заполняем full_section_number для существующих разделов
        // Используем section_number как начальное значение
        DB::statement('UPDATE estimate_sections SET full_section_number = section_number WHERE full_section_number IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_sections', function (Blueprint $table) {
            $table->dropIndex(['full_section_number']);
            $table->dropColumn('full_section_number');
        });
    }
};

