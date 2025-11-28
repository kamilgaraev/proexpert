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
        // ВАЖНО: PostgreSQL не позволяет изменять тип колонки, если она используется в представлении
        // Поэтому сначала удаляем представление, изменяем колонку, затем восстанавливаем представление
        
        // 1. Удаляем представление organization_metrics, которое использует total_amount
        DB::statement('DROP VIEW IF EXISTS organization_metrics');
        
        // 2. Изменяем колонку total_amount на nullable
        Schema::table('contracts', function (Blueprint $table) {
            // Делаем total_amount nullable для поддержки контрактов с нефиксированной суммой
            // Для существующих контрактов значение сохранится (NOT NULL -> NULL разрешено)
            $table->decimal('total_amount', 15, 2)->nullable()->change();
        });
        
        // 3. Восстанавливаем представление organization_metrics с учетом nullable total_amount
        DB::statement("
            CREATE OR REPLACE VIEW organization_metrics AS
            SELECT 
                o.id as organization_id,
                o.name as organization_name,
                o.parent_organization_id,
                o.is_holding,
                
                COUNT(DISTINCT p.id) as projects_count,
                COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.id END) as projects_active,
                COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.id END) as projects_completed,
                COALESCE(SUM(p.budget_amount), 0) as total_budget,
                
                COUNT(DISTINCT c.id) as contracts_count,
                COALESCE(SUM(COALESCE(c.total_amount, 0)), 0) as total_contract_amount,
                COALESCE(SUM(CASE WHEN c.status = 'active' THEN COALESCE(c.total_amount, 0) ELSE 0 END), 0) as active_contract_amount,
                
                COUNT(DISTINCT ou.user_id) as users_count,
                
                MAX(p.updated_at) as last_project_update,
                MAX(c.updated_at) as last_contract_update,
                NOW() as calculated_at
            
            FROM organizations o
            LEFT JOIN projects p ON p.organization_id = o.id AND p.deleted_at IS NULL
            LEFT JOIN contracts c ON c.organization_id = o.id AND c.deleted_at IS NULL
            LEFT JOIN organization_user ou ON ou.organization_id = o.id AND ou.is_active = true
            
            GROUP BY o.id, o.name, o.parent_organization_id, o.is_holding
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Удаляем представление organization_metrics перед изменением колонки
        DB::statement('DROP VIEW IF EXISTS organization_metrics');
        
        // 2. Возвращаем NOT NULL, устанавливая 0 для NULL значений
        DB::statement('UPDATE contracts SET total_amount = 0 WHERE total_amount IS NULL');
        
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('total_amount', 15, 2)->nullable(false)->change();
        });
        
        // 3. Восстанавливаем представление organization_metrics (старая версия без COALESCE для total_amount)
        DB::statement("
            CREATE OR REPLACE VIEW organization_metrics AS
            SELECT 
                o.id as organization_id,
                o.name as organization_name,
                o.parent_organization_id,
                o.is_holding,
                
                COUNT(DISTINCT p.id) as projects_count,
                COUNT(DISTINCT CASE WHEN p.status = 'active' THEN p.id END) as projects_active,
                COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.id END) as projects_completed,
                COALESCE(SUM(p.budget_amount), 0) as total_budget,
                
                COUNT(DISTINCT c.id) as contracts_count,
                COALESCE(SUM(c.total_amount), 0) as total_contract_amount,
                COALESCE(SUM(CASE WHEN c.status = 'active' THEN c.total_amount ELSE 0 END), 0) as active_contract_amount,
                
                COUNT(DISTINCT ou.user_id) as users_count,
                
                MAX(p.updated_at) as last_project_update,
                MAX(c.updated_at) as last_contract_update,
                NOW() as calculated_at
            
            FROM organizations o
            LEFT JOIN projects p ON p.organization_id = o.id AND p.deleted_at IS NULL
            LEFT JOIN contracts c ON c.organization_id = o.id AND c.deleted_at IS NULL
            LEFT JOIN organization_user ou ON ou.organization_id = o.id AND ou.is_active = true
            
            GROUP BY o.id, o.name, o.parent_organization_id, o.is_holding
        ");
    }
};
