<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Индексы для balance_transactions для оптимизации финансовой сводки
        Schema::table('balance_transactions', function (Blueprint $table) {
            $table->index(['organization_balance_id', 'created_at'], 'bt_org_balance_created');
            $table->index(['organization_balance_id', 'type', 'created_at'], 'bt_org_balance_type_created');
        });

        // Индексы для projects для оптимизации проектной сводки
        Schema::table('projects', function (Blueprint $table) {
            $table->index(['organization_id', 'status'], 'projects_org_status');
            $table->index(['organization_id', 'created_at'], 'projects_org_created');
        });

        // Индексы для contracts для оптимизации контрактной сводки
        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['organization_id', 'status'], 'contracts_org_status');
            $table->index(['organization_id', 'created_at'], 'contracts_org_created');
        });

        // Индексы для completed_works
        Schema::table('completed_works', function (Blueprint $table) {
            $table->index(['organization_id', 'status'], 'cw_org_status');
            $table->index(['organization_id', 'created_at'], 'cw_org_created');
        });

        // Индексы для user_role_assignments для оптимизации команды
        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->index(['context_id', 'is_active', 'user_id'], 'ura_context_active_user');
            $table->index(['user_id', 'context_id', 'is_active'], 'ura_user_context_active');
        });

        // Индексы для user_organization для оптимизации связей пользователей с организациями
        Schema::table('user_organization', function (Blueprint $table) {
            $table->index(['organization_id', 'user_id'], 'user_org_org_user');
        });

        // Индексы для authorization_contexts
        Schema::table('authorization_contexts', function (Blueprint $table) {
            $table->index(['type', 'resource_id'], 'auth_ctx_type_resource');
        });

        // Индексы для contract_performance_acts
        if (Schema::hasTable('contract_performance_acts')) {
            Schema::table('contract_performance_acts', function (Blueprint $table) {
                $table->index(['contract_id', 'is_approved'], 'cpa_contract_approved');
            });
        }

        // Индексы для materials
        if (Schema::hasTable('materials')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->index(['organization_id', 'created_at'], 'materials_org_created');
            });
        }
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

        Schema::table('user_role_assignments', function (Blueprint $table) {
            $table->dropIndex('ura_context_active_user');
            $table->dropIndex('ura_user_context_active');
        });

        Schema::table('user_organization', function (Blueprint $table) {
            $table->dropIndex('user_org_org_user');
        });

        Schema::table('authorization_contexts', function (Blueprint $table) {
            $table->dropIndex('auth_ctx_type_resource');
        });

        if (Schema::hasTable('contract_performance_acts')) {
            Schema::table('contract_performance_acts', function (Blueprint $table) {
                $table->dropIndex('cpa_contract_approved');
            });
        }

        if (Schema::hasTable('materials')) {
            Schema::table('materials', function (Blueprint $table) {
                $table->dropIndex('materials_org_created');
            });
        }
    }
};