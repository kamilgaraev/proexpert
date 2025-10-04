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
        $this->createIndexIfNotExists('contracts', 'idx_contracts_org_status', ['organization_id', 'status']);
        $this->createIndexIfNotExists('contracts', 'idx_contracts_project_status', ['project_id', 'status']);
        $this->createIndexIfNotExists('contracts', 'idx_contracts_org_created', ['organization_id', 'created_at']);
        $this->createIndexIfNotExists('contracts', 'idx_contracts_progress', ['progress']);

        
        // Индексы для completed_works (KPI, финансы)
        $this->createIndexIfNotExists('completed_works', 'idx_completed_works_user_date', ['user_id', 'created_at']);
        $this->createIndexIfNotExists('completed_works', 'idx_completed_works_project_date', ['project_id', 'created_at']);
        $this->createIndexIfNotExists('completed_works', 'idx_completed_works_created', ['created_at']);
        
        // Индексы для materials (предиктивная аналитика)
        $this->createIndexIfNotExists('materials', 'idx_materials_org_balance', ['organization_id', 'balance']);
        
        // Индексы для projects (общие запросы)
        $this->createIndexIfNotExists('projects', 'idx_projects_org_created', ['organization_id', 'created_at']);
        
        // Индексы для dashboards (модуль Advanced Dashboard)
        // Создаем только если таблица существует
        if ($this->tableExists('dashboards')) {
            $this->createIndexIfNotExists('dashboards', 'idx_dashboards_user_org_default', ['user_id', 'organization_id', 'is_default']);
            $this->createIndexIfNotExists('dashboards', 'idx_dashboards_org_shared', ['organization_id', 'is_shared']);
            $this->createIndexIfNotExists('dashboards', 'idx_dashboards_slug', ['slug']);
            $this->createIndexIfNotExists('dashboards', 'idx_dashboards_created', ['created_at']);
        }
        
        // Индексы для dashboard_alerts
        if ($this->tableExists('dashboard_alerts')) {
            $this->createIndexIfNotExists('dashboard_alerts', 'idx_alerts_org_active', ['organization_id', 'is_active']);
            $this->createIndexIfNotExists('dashboard_alerts', 'idx_alerts_user_active', ['user_id', 'is_active']);
            $this->createIndexIfNotExists('dashboard_alerts', 'idx_alerts_type_entity', ['alert_type', 'target_entity']);
            $this->createIndexIfNotExists('dashboard_alerts', 'idx_alerts_target', ['target_entity', 'target_entity_id']);
            $this->createIndexIfNotExists('dashboard_alerts', 'idx_alerts_last_checked', ['last_checked_at']);
            $this->createIndexIfNotExists('dashboard_alerts', 'idx_alerts_active_checked', ['is_active', 'last_checked_at']);
        }
        
        // Индексы для scheduled_reports
        if ($this->tableExists('scheduled_reports')) {
            $this->createIndexIfNotExists('scheduled_reports', 'idx_reports_org_active', ['organization_id', 'is_active']);
            $this->createIndexIfNotExists('scheduled_reports', 'idx_reports_active_next_run', ['is_active', 'next_run_at']);
            $this->createIndexIfNotExists('scheduled_reports', 'idx_reports_next_run', ['next_run_at']);
            $this->createIndexIfNotExists('scheduled_reports', 'idx_reports_frequency', ['frequency']);
        }
        
        // JSONB индексы для PostgreSQL (если используется PostgreSQL)
        if (DB::getDriverName() === 'pgsql') {
            if ($this->tableExists('dashboards')) {
                DB::statement('CREATE INDEX IF NOT EXISTS idx_dashboards_layout_gin ON dashboards USING gin (layout)');
                DB::statement('CREATE INDEX IF NOT EXISTS idx_dashboards_widgets_gin ON dashboards USING gin (widgets)');
                DB::statement('CREATE INDEX IF NOT EXISTS idx_dashboards_filters_gin ON dashboards USING gin (filters)');
            }
            if ($this->tableExists('dashboard_alerts')) {
                DB::statement('CREATE INDEX IF NOT EXISTS idx_alerts_conditions_gin ON dashboard_alerts USING gin (conditions)');
            }
        }
    }

    /**
     * Проверка существования таблицы
     */
    protected function tableExists(string $table): bool
    {
        $exists = DB::select(
            "SELECT 1 FROM information_schema.tables WHERE table_name = ?",
            [$table]
        );
        
        return !empty($exists);
    }

    /**
     * Проверка и создание индекса если не существует
     */
    protected function createIndexIfNotExists(string $table, string $indexName, array $columns): void
    {
        // Проверка существования индекса
        $indexExists = DB::select(
            "SELECT 1 FROM pg_indexes WHERE indexname = ?",
            [$indexName]
        );
        
        if (!empty($indexExists)) {
            return; // Индекс уже существует
        }
        
        // Проверка существования всех колонок
        foreach ($columns as $column) {
            $columnExists = DB::select(
                "SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?",
                [$table, $column]
            );
            
            if (empty($columnExists)) {
                // Колонка не существует - пропускаем создание индекса
                return;
            }
        }
        
        // Создаем индекс только если все колонки существуют
        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->index($columns, $indexName);
        });
    }
    
    /**
     * Удаление индекса если существует
     */
    protected function dropIndexIfExists(string $table, string $indexName): void
    {
        $exists = DB::select(
            "SELECT 1 FROM pg_indexes WHERE indexname = ?",
            [$indexName]
        );
        
        if (!empty($exists)) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                $blueprint->dropIndex($indexName);
            });
        }
    }
    
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Contracts
        $this->dropIndexIfExists('contracts', 'idx_contracts_org_status');
        $this->dropIndexIfExists('contracts', 'idx_contracts_project_status');
        $this->dropIndexIfExists('contracts', 'idx_contracts_org_created');
        $this->dropIndexIfExists('contracts', 'idx_contracts_progress');
        
        // Completed Works
        $this->dropIndexIfExists('completed_works', 'idx_completed_works_user_date');
        $this->dropIndexIfExists('completed_works', 'idx_completed_works_project_date');
        $this->dropIndexIfExists('completed_works', 'idx_completed_works_created');
        
        // Materials
        $this->dropIndexIfExists('materials', 'idx_materials_org_balance');
        
        // Projects
        $this->dropIndexIfExists('projects', 'idx_projects_org_created');
        
        // Dashboards
        $this->dropIndexIfExists('dashboards', 'idx_dashboards_user_org_default');
        $this->dropIndexIfExists('dashboards', 'idx_dashboards_org_shared');
        $this->dropIndexIfExists('dashboards', 'idx_dashboards_slug');
        $this->dropIndexIfExists('dashboards', 'idx_dashboards_created');
        
        // Alerts
        $this->dropIndexIfExists('dashboard_alerts', 'idx_alerts_org_active');
        $this->dropIndexIfExists('dashboard_alerts', 'idx_alerts_user_active');
        $this->dropIndexIfExists('dashboard_alerts', 'idx_alerts_type_entity');
        $this->dropIndexIfExists('dashboard_alerts', 'idx_alerts_target');
        $this->dropIndexIfExists('dashboard_alerts', 'idx_alerts_last_checked');
        $this->dropIndexIfExists('dashboard_alerts', 'idx_alerts_active_checked');
        
        // Scheduled Reports
        $this->dropIndexIfExists('scheduled_reports', 'idx_reports_org_active');
        $this->dropIndexIfExists('scheduled_reports', 'idx_reports_active_next_run');
        $this->dropIndexIfExists('scheduled_reports', 'idx_reports_next_run');
        $this->dropIndexIfExists('scheduled_reports', 'idx_reports_frequency');
        
        // JSONB индексы для PostgreSQL
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_dashboards_layout_gin');
            DB::statement('DROP INDEX IF EXISTS idx_dashboards_widgets_gin');
            DB::statement('DROP INDEX IF EXISTS idx_dashboards_filters_gin');
            DB::statement('DROP INDEX IF EXISTS idx_alerts_conditions_gin');
        }
    }
};

