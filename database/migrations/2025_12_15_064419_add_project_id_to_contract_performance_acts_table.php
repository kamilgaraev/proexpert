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
        Schema::table('contract_performance_acts', function (Blueprint $table) {
            // Добавляем поле project_id (nullable для существующих записей)
            $table->foreignId('project_id')->nullable()->after('contract_id')->constrained('projects')->onDelete('cascade');
            $table->index('project_id');
        });

        // Заполняем project_id для существующих актов
        // Для обычных контрактов берем project_id из таблицы contracts
        DB::statement('
            UPDATE contract_performance_acts cpa
            INNER JOIN contracts c ON cpa.contract_id = c.id
            SET cpa.project_id = c.project_id
            WHERE c.is_multi_project = 0 AND c.project_id IS NOT NULL
        ');

        // Для мультипроектных контрактов берем первый project_id из таблицы contract_project
        // (идеально было бы уточнить у пользователя, но возьмем первый)
        DB::statement('
            UPDATE contract_performance_acts cpa
            INNER JOIN contracts c ON cpa.contract_id = c.id
            INNER JOIN (
                SELECT contract_id, MIN(project_id) as project_id
                FROM contract_project
                GROUP BY contract_id
            ) cp ON c.id = cp.contract_id
            SET cpa.project_id = cp.project_id
            WHERE c.is_multi_project = 1 AND cpa.project_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contract_performance_acts', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
