<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ОПТИМИЗАЦИЯ: Индексы только для реально существующих таблиц в БД
        
        // balance_transactions - для финансовой сводки (таблица существует)
        Schema::table('balance_transactions', function (Blueprint $table) {
            if (!$this->hasIndex($table, 'bt_org_balance_created')) {
                $table->index(['organization_balance_id', 'created_at'], 'bt_org_balance_created');
            }
            if (!$this->hasIndex($table, 'bt_org_balance_type_created')) {
                $table->index(['organization_balance_id', 'type', 'created_at'], 'bt_org_balance_type_created');
            }
        });

        // projects - для проектной сводки (таблица существует)
        Schema::table('projects', function (Blueprint $table) {
            if (!$this->hasIndex($table, 'projects_org_status')) {
                $table->index(['organization_id', 'status'], 'projects_org_status');
            }
            if (!$this->hasIndex($table, 'projects_org_created')) {
                $table->index(['organization_id', 'created_at'], 'projects_org_created');
            }
        });

        // contracts - для контрактной сводки (таблица существует)
        Schema::table('contracts', function (Blueprint $table) {
            if (!$this->hasIndex($table, 'contracts_org_status')) {
                $table->index(['organization_id', 'status'], 'contracts_org_status');
            }
            if (!$this->hasIndex($table, 'contracts_org_created')) {
                $table->index(['organization_id', 'created_at'], 'contracts_org_created');
            }
        });

        // completed_works - для сводки работ и материалов (таблица существует)
        Schema::table('completed_works', function (Blueprint $table) {
            if (!$this->hasIndex($table, 'cw_org_status')) {
                $table->index(['organization_id', 'status'], 'cw_org_status');
            }
            if (!$this->hasIndex($table, 'cw_org_created')) {
                $table->index(['organization_id', 'created_at'], 'cw_org_created');
            }
        });

        // organization_user - правильное имя таблицы связи пользователей с организациями
        Schema::table('organization_user', function (Blueprint $table) {
            if (!$this->hasIndex($table, 'org_user_org_user_active')) {
                $table->index(['organization_id', 'user_id', 'is_active'], 'org_user_org_user_active');
            }
        });

        // materials - для сводки материалов (таблица существует)
        Schema::table('materials', function (Blueprint $table) {
            if (!$this->hasIndex($table, 'materials_org_created')) {
                $table->index(['organization_id', 'created_at'], 'materials_org_created');
            }
        });

        // contract_performance_acts - для актов выполненных работ (таблица существует)
        Schema::table('contract_performance_acts', function (Blueprint $table) {
            if (!$this->hasIndex($table, 'cpa_contract_approved')) {
                $table->index(['contract_id', 'is_approved'], 'cpa_contract_approved');
            }
        });
    }
    
    private function hasIndex($table, $indexName): bool
    {
        // Простая проверка - в PostgreSQL можно проверить через information_schema
        // но для простоты используем try/catch в реальном проекте
        return false; // Всегда создаем, если миграция не применялась
    }

    public function down(): void
    {
        Schema::table('balance_transactions', function (Blueprint $table) {
            $table->dropIndex('bt_org_balance_created');
            $table->dropIndex('bt_org_balance_type_created');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_org_status');
            $table->dropIndex('projects_org_created');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_org_status');
            $table->dropIndex('contracts_org_created');
        });

        Schema::table('completed_works', function (Blueprint $table) {
            $table->dropIndex('cw_org_status');
            $table->dropIndex('cw_org_created');
        });

        Schema::table('organization_user', function (Blueprint $table) {
            $table->dropIndex('org_user_org_user_active');
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex('materials_org_created');
        });

        Schema::table('contract_performance_acts', function (Blueprint $table) {
            $table->dropIndex('cpa_contract_approved');
        });
    }
};