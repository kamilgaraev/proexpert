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
        // Переименование в таблице payments
        if (Schema::hasColumn('payments', 'user_subscription_id')) {
            Schema::table('payments', function (Blueprint $table) {
                // Удаляем старый foreign key
                $table->dropForeign(['user_subscription_id']);
                
                // Переименовываем колонку
                $table->renameColumn('user_subscription_id', 'organization_subscription_id');
            });
            
            // Добавляем новый foreign key
            Schema::table('payments', function (Blueprint $table) {
                $table->foreign('organization_subscription_id')
                    ->references('id')
                    ->on('organization_subscriptions')
                    ->onDelete('set null');
            });
        }
        
        // Переименование в таблице balance_transactions
        if (Schema::hasColumn('balance_transactions', 'user_subscription_id')) {
            Schema::table('balance_transactions', function (Blueprint $table) {
                // Удаляем старый foreign key
                $table->dropForeign(['user_subscription_id']);
                
                // Переименовываем колонку
                $table->renameColumn('user_subscription_id', 'organization_subscription_id');
            });
            
            // Добавляем новый foreign key
            Schema::table('balance_transactions', function (Blueprint $table) {
                $table->foreign('organization_subscription_id')
                    ->references('id')
                    ->on('organization_subscriptions')
                    ->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Откат для payments
        if (Schema::hasColumn('payments', 'organization_subscription_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['organization_subscription_id']);
                $table->renameColumn('organization_subscription_id', 'user_subscription_id');
            });
            
            Schema::table('payments', function (Blueprint $table) {
                $table->foreign('user_subscription_id')
                    ->references('id')
                    ->on('user_subscriptions')
                    ->onDelete('set null');
            });
        }
        
        // Откат для balance_transactions
        if (Schema::hasColumn('balance_transactions', 'organization_subscription_id')) {
            Schema::table('balance_transactions', function (Blueprint $table) {
                $table->dropForeign(['organization_subscription_id']);
                $table->renameColumn('organization_subscription_id', 'user_subscription_id');
            });
            
            Schema::table('balance_transactions', function (Blueprint $table) {
                $table->foreign('user_subscription_id')
                    ->references('id')
                    ->on('user_subscriptions')
                    ->onDelete('set null');
            });
        }
    }
};
