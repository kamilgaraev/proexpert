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
        if (!Schema::hasColumn('advance_account_transactions', 'project_id')) {
            Schema::table('advance_account_transactions', function (Blueprint $table) {
                // Добавляем столбец project_id после organization_id
                $table->unsignedBigInteger('project_id')->nullable()->after('organization_id')->comment('ID проекта, к которому относится транзакция');
                
                // Добавляем внешний ключ, если таблица projects существует
                if (Schema::hasTable('projects')) {
                    $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
                }
                
                // Добавляем индекс
                $table->index('project_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('advance_account_transactions', 'project_id')) {
            Schema::table('advance_account_transactions', function (Blueprint $table) {
                // Сначала удаляем внешний ключ и индекс
                if (Schema::hasTable('projects')) { // Проверяем наличие таблицы перед удалением ключа
                    $table->dropForeign(['project_id']);
                }
                $table->dropIndex(['project_id']);
                // Затем удаляем столбец
                $table->dropColumn('project_id');
            });
        }
    }
}; 