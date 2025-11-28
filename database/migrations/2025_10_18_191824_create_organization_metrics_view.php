<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        DB::statement("DROP VIEW IF EXISTS organization_metrics");
    }
};
