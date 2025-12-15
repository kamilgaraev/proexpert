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
            UPDATE contract_performance_acts
            SET project_id = contracts.project_id
            FROM contracts
            WHERE contract_performance_acts.contract_id = contracts.id
                AND contracts.is_multi_project = false
                AND contracts.project_id IS NOT NULL
        ');

        // Для мультипроектных контрактов берем первый project_id из таблицы contract_project
        DB::statement('
            UPDATE contract_performance_acts
            SET project_id = cp.project_id
            FROM contracts c
            INNER JOIN (
                SELECT contract_id, MIN(project_id) as project_id
                FROM contract_project
                GROUP BY contract_id
            ) cp ON c.id = cp.contract_id
            WHERE contract_performance_acts.contract_id = c.id
                AND c.is_multi_project = true
                AND contract_performance_acts.project_id IS NULL
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
