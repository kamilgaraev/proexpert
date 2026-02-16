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
            // Изменяем типы полей на text, чтобы избежать обрезания длинных строк при импорте
            $table->text('section_number')->change();
            $table->text('name')->change();
            $table->text('full_section_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_sections', function (Blueprint $table) {
            $table->string('section_number', 255)->change();
            $table->string('name', 255)->change();
            $table->string('full_section_number', 100)->nullable()->change();
        });
    }
};
