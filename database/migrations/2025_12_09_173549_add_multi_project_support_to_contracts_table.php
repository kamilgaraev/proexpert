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
        // Добавляем поле is_multi_project в таблицу contracts
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('is_multi_project')->default(false)->after('is_fixed_amount');
        });

        // Создаем pivot таблицу для связи контрактов с проектами (many-to-many)
        Schema::create('contract_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->onDelete('cascade');
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->timestamps();

            // Уникальный индекс чтобы избежать дублирования
            $table->unique(['contract_id', 'project_id']);
            $table->index('project_id');
        });

        // Мигрируем существующие контракты в pivot таблицу
        // Для всех контрактов с project_id создаем запись в contract_project
        DB::statement('
            INSERT INTO contract_project (contract_id, project_id, created_at, updated_at)
            SELECT id, project_id, created_at, updated_at
            FROM contracts
            WHERE project_id IS NOT NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем pivot таблицу
        Schema::dropIfExists('contract_project');

        // Удаляем поле is_multi_project
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('is_multi_project');
        });
    }
};
