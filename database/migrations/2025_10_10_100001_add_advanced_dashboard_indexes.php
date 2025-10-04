<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляем индексы для оптимизации аналитических запросов
     * Advanced Dashboard модуля
     */
    public function up(): void
    {
        // Индексы для contracts (финансовая и предиктивная аналитика)
        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['organization_id', 'status'], 'idx_contracts_org_status');
            $table->index(['project_id', 'status'], 'idx_contracts_project_status');
            $table->index(['organization_id', 'created_at'], 'idx_contracts_org_created');
            $table->index('progress', 'idx_contracts_progress');
        });
        
        // Индексы для completed_works (KPI, финансы)
        Schema::table('completed_works', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'idx_completed_works_user_date');
            $table->index(['project_id', 'created_at'], 'idx_completed_works_project_date');
            $table->index('created_at', 'idx_completed_works_created');
        });
        
        // Индексы для materials (предиктивная аналитика)
        Schema::table('materials', function (Blueprint $table) {
            $table->index(['organization_id', 'balance'], 'idx_materials_org_balance');
        });
        
        // Индексы для projects (общие запросы)
        Schema::table('projects', function (Blueprint $table) {
            $table->index(['organization_id', 'created_at'], 'idx_projects_org_created');
        });
        
        // Индексы для dashboards (модуль Advanced Dashboard)
        Schema::table('dashboards', function (Blueprint $table) {
            $table->index(['user_id', 'organization_id', 'is_default'], 'idx_dashboards_user_org_default');
            $table->index(['organization_id', 'is_shared'], 'idx_dashboards_org_shared');
            $table->index('slug', 'idx_dashboards_slug');
            $table->index('created_at', 'idx_dashboards_created');
        });
        
        // Индексы для dashboard_alerts
        Schema::table('dashboard_alerts', function (Blueprint $table) {
            $table->index(['organization_id', 'is_active'], 'idx_alerts_org_active');
            $table->index(['user_id', 'is_active'], 'idx_alerts_user_active');
            $table->index(['alert_type', 'target_entity'], 'idx_alerts_type_entity');
            $table->index(['target_entity', 'target_entity_id'], 'idx_alerts_target');
            $table->index('last_checked_at', 'idx_alerts_last_checked');
            $table->index(['is_active', 'last_checked_at'], 'idx_alerts_active_checked');
        });
        
        // Индексы для scheduled_reports
        Schema::table('scheduled_reports', function (Blueprint $table) {
            $table->index(['organization_id', 'is_active'], 'idx_reports_org_active');
            $table->index(['is_active', 'next_run_at'], 'idx_reports_active_next_run');
            $table->index('next_run_at', 'idx_reports_next_run');
            $table->index('frequency', 'idx_reports_frequency');
        });
        
        // JSONB индексы для PostgreSQL (если используется PostgreSQL)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_dashboards_layout_gin ON dashboards USING gin (layout)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_dashboards_widgets_gin ON dashboards USING gin (widgets)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_dashboards_filters_gin ON dashboards USING gin (filters)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_alerts_conditions_gin ON dashboard_alerts USING gin (conditions)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Contracts
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('idx_contracts_org_status');
            $table->dropIndex('idx_contracts_project_status');
            $table->dropIndex('idx_contracts_org_created');
            $table->dropIndex('idx_contracts_progress');
        });
        
        // Completed Works
        Schema::table('completed_works', function (Blueprint $table) {
            $table->dropIndex('idx_completed_works_user_date');
            $table->dropIndex('idx_completed_works_project_date');
            $table->dropIndex('idx_completed_works_created');
        });
        
        // Materials
        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex('idx_materials_org_balance');
        });
        
        // Projects
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('idx_projects_org_created');
        });
        
        // Dashboards
        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropIndex('idx_dashboards_user_org_default');
            $table->dropIndex('idx_dashboards_org_shared');
            $table->dropIndex('idx_dashboards_slug');
            $table->dropIndex('idx_dashboards_created');
        });
        
        // Alerts
        Schema::table('dashboard_alerts', function (Blueprint $table) {
            $table->dropIndex('idx_alerts_org_active');
            $table->dropIndex('idx_alerts_user_active');
            $table->dropIndex('idx_alerts_type_entity');
            $table->dropIndex('idx_alerts_target');
            $table->dropIndex('idx_alerts_last_checked');
            $table->dropIndex('idx_alerts_active_checked');
        });
        
        // Scheduled Reports
        Schema::table('scheduled_reports', function (Blueprint $table) {
            $table->dropIndex('idx_reports_org_active');
            $table->dropIndex('idx_reports_active_next_run');
            $table->dropIndex('idx_reports_next_run');
            $table->dropIndex('idx_reports_frequency');
        });
        
        // JSONB индексы для PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_dashboards_layout_gin');
            DB::statement('DROP INDEX IF EXISTS idx_dashboards_widgets_gin');
            DB::statement('DROP INDEX IF EXISTS idx_dashboards_filters_gin');
            DB::statement('DROP INDEX IF EXISTS idx_alerts_conditions_gin');
        }
    }
};

