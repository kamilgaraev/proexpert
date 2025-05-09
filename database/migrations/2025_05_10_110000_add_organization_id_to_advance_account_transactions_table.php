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
        if (!Schema::hasColumn('advance_account_transactions', 'organization_id')) {
            Schema::table('advance_account_transactions', function (Blueprint $table) {
                // Добавляем столбец после user_id для логического порядка
                $table->unsignedBigInteger('organization_id')->after('user_id')->comment('ID организации');
                
                // Добавляем внешний ключ, если таблица organizations существует
                if (Schema::hasTable('organizations')) {
                    $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
                }
                
                // Добавляем индекс
                $table->index('organization_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('advance_account_transactions', 'organization_id')) {
            Schema::table('advance_account_transactions', function (Blueprint $table) {
                // Сначала удаляем внешний ключ и индекс
                $table->dropForeign(['organization_id']);
                $table->dropIndex(['organization_id']);
                // Затем удаляем столбец
                $table->dropColumn('organization_id');
            });
        }
    }
}; 