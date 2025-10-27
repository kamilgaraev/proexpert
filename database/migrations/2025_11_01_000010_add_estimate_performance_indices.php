<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX estimates_org_status_created_idx ON estimates(organization_id, status, created_at DESC)');
        
        DB::statement('CREATE INDEX estimates_project_status_idx ON estimates(project_id, status) WHERE project_id IS NOT NULL');
        
        DB::statement('CREATE INDEX estimates_contract_status_idx ON estimates(contract_id, status) WHERE contract_id IS NOT NULL');
        
        DB::statement('CREATE INDEX estimates_active_idx ON estimates(organization_id, status, updated_at DESC) WHERE deleted_at IS NULL AND status != \'cancelled\'');

        DB::statement('CREATE INDEX estimate_items_estimate_sort_idx ON estimate_items(estimate_id, position_number)');
        
        DB::statement('CREATE INDEX estimate_items_estimate_type_idx ON estimate_items(estimate_id, item_type)');
        
        DB::statement('CREATE INDEX estimate_items_active_idx ON estimate_items(estimate_id) WHERE deleted_at IS NULL');

        DB::statement('CREATE INDEX normative_rates_collection_code_idx ON normative_rates(collection_id, code)');
        
        DB::statement('CREATE INDEX normative_rates_section_idx ON normative_rates(section_id) WHERE section_id IS NOT NULL');

        DB::statement('CREATE INDEX normative_sections_collection_parent_idx ON normative_sections(collection_id, parent_id)');
        
        DB::statement('CREATE INDEX normative_sections_path_idx ON normative_sections USING GIST(path gist_trgm_ops) WHERE path IS NOT NULL');

        DB::statement('CREATE INDEX price_indices_lookup_idx ON price_indices(index_type, region_code, year DESC, quarter DESC)');

        DB::statement('CREATE INDEX regional_coefficients_active_idx ON regional_coefficients(coefficient_type, region_code, is_active) WHERE is_active = true');

        DB::statement("
            CREATE MATERIALIZED VIEW mv_estimate_statistics AS
            SELECT 
                e.organization_id,
                e.status,
                COUNT(*) as estimates_count,
                SUM(e.total_amount) as total_amount_sum,
                AVG(e.total_amount) as avg_amount,
                COUNT(DISTINCT e.project_id) as projects_count,
                COUNT(DISTINCT e.contract_id) as contracts_count,
                MAX(e.updated_at) as last_updated
            FROM estimates e
            WHERE e.deleted_at IS NULL
            GROUP BY e.organization_id, e.status;
        ");

        DB::statement('CREATE UNIQUE INDEX mv_estimate_statistics_org_status_idx ON mv_estimate_statistics(organization_id, status)');

        DB::statement("
            CREATE MATERIALIZED VIEW mv_normative_rates_usage AS
            SELECT 
                nr.id as rate_id,
                nr.collection_id,
                nr.code,
                nr.name,
                COUNT(DISTINCT ei.estimate_id) as used_in_estimates,
                COUNT(ei.id) as usage_count,
                SUM(ei.quantity) as total_quantity,
                MAX(ei.updated_at) as last_used_at
            FROM normative_rates nr
            LEFT JOIN estimate_items ei ON ei.normative_rate_id = nr.id AND ei.deleted_at IS NULL
            GROUP BY nr.id, nr.collection_id, nr.code, nr.name;
        ");

        DB::statement('CREATE UNIQUE INDEX mv_normative_rates_usage_rate_idx ON mv_normative_rates_usage(rate_id)');
        DB::statement('CREATE INDEX mv_normative_rates_usage_collection_idx ON mv_normative_rates_usage(collection_id, usage_count DESC)');

        DB::statement("
            CREATE MATERIALIZED VIEW mv_estimate_library_statistics AS
            SELECT 
                el.id as library_id,
                el.organization_id,
                el.name,
                COUNT(DISTINCT eli.id) as items_count,
                SUM(eli.usage_count) as total_usage_count,
                COUNT(DISTINCT elu.estimate_id) as used_in_estimates,
                MAX(elu.used_at) as last_used_at
            FROM estimate_libraries el
            LEFT JOIN estimate_library_items eli ON eli.library_id = el.id AND eli.deleted_at IS NULL
            LEFT JOIN estimate_library_usage elu ON elu.library_item_id = eli.id
            WHERE el.deleted_at IS NULL
            GROUP BY el.id, el.organization_id, el.name;
        ");

        DB::statement('CREATE UNIQUE INDEX mv_estimate_library_statistics_library_idx ON mv_estimate_library_statistics(library_id)');
        DB::statement('CREATE INDEX mv_estimate_library_statistics_org_usage_idx ON mv_estimate_library_statistics(organization_id, total_usage_count DESC)');

        DB::statement("
            CREATE OR REPLACE FUNCTION refresh_estimate_materialized_views() RETURNS void AS $$
            BEGIN
                REFRESH MATERIALIZED VIEW CONCURRENTLY mv_estimate_statistics;
                REFRESH MATERIALIZED VIEW CONCURRENTLY mv_normative_rates_usage;
                REFRESH MATERIALIZED VIEW CONCURRENTLY mv_estimate_library_statistics;
            END
            $$ LANGUAGE plpgsql;
        ");
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS refresh_estimate_materialized_views()');
        
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_estimate_library_statistics');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_normative_rates_usage');
        DB::statement('DROP MATERIALIZED VIEW IF EXISTS mv_estimate_statistics');
        
        DB::statement('DROP INDEX IF EXISTS regional_coefficients_active_idx');
        DB::statement('DROP INDEX IF EXISTS price_indices_lookup_idx');
        DB::statement('DROP INDEX IF EXISTS normative_sections_path_idx');
        DB::statement('DROP INDEX IF EXISTS normative_sections_collection_parent_idx');
        DB::statement('DROP INDEX IF EXISTS normative_rates_section_idx');
        DB::statement('DROP INDEX IF EXISTS normative_rates_collection_code_idx');
        DB::statement('DROP INDEX IF EXISTS estimate_items_active_idx');
        DB::statement('DROP INDEX IF EXISTS estimate_items_estimate_type_idx');
        DB::statement('DROP INDEX IF EXISTS estimate_items_estimate_sort_idx');
        DB::statement('DROP INDEX IF EXISTS estimates_active_idx');
        DB::statement('DROP INDEX IF EXISTS estimates_contract_status_idx');
        DB::statement('DROP INDEX IF EXISTS estimates_project_status_idx');
        DB::statement('DROP INDEX IF EXISTS estimates_org_status_created_idx');
    }
};

