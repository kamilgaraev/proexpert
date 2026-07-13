<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_usage_created_desc_idx ON estimate_generation_ai_usage (created_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_usage_requested_model_created_desc_idx ON estimate_generation_ai_usage (requested_model, created_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_usage_status_created_desc_idx ON estimate_generation_ai_usage (status, created_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_failure_identities_stage_idx ON estimate_generation_failure_identities (stage, id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_failure_identities_category_idx ON estimate_generation_failure_identities (category, id)');
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_failure_occurrence_recorded_idx ON estimate_generation_failure_events (recorded_at DESC, failure_id) WHERE event_type = 'occurred'");
        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_failure_resolution_lookup_idx ON estimate_generation_failure_events (failure_id, resolves_through_sequence DESC) WHERE event_type = 'resolved'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_usage_created_desc_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_usage_requested_model_created_desc_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_usage_status_created_desc_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_failure_identities_stage_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_failure_identities_category_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_failure_occurrence_recorded_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_failure_resolution_lookup_idx');
    }
};
