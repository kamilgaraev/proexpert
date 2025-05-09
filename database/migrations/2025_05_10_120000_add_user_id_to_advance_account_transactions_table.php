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
        if (!Schema::hasColumn('advance_account_transactions', 'user_id')) {
            Schema::table('advance_account_transactions', function (Blueprint $table) {
                // Добавляем столбец user_id после id
                $table->unsignedBigInteger('user_id')->after('id')->comment('ID пользователя (прораба)');
                
                // Добавляем внешний ключ, если таблица users существует
                if (Schema::hasTable('users')) {
                    $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                }
                
                // Добавляем индекс
                $table->index('user_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('advance_account_transactions', 'user_id')) {
            Schema::table('advance_account_transactions', function (Blueprint $table) {
                // Сначала удаляем внешний ключ и индекс
                if (Schema::hasTable('users')) { // Проверяем наличие таблицы перед удалением ключа
                    $table->dropForeign(['user_id']);
                }
                $table->dropIndex(['user_id']);
                // Затем удаляем столбец
                $table->dropColumn('user_id');
            });
        }
    }
}; 