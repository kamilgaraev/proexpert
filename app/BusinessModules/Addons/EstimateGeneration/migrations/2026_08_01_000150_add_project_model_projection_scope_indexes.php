<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Support\TrainingBenchmarkOnlineMigrationRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('estimate_generation_building_models', function (Blueprint $table): void {
                $table->unique(['id', 'organization_id', 'project_id', 'session_id', 'content_version'], 'eg_building_models_projection_scope_uq');
            });
            Schema::table('estimate_generation_building_model_evidence', function (Blueprint $table): void {
                $table->unique(['building_model_id', 'evidence_id', 'organization_id', 'project_id', 'session_id'], 'eg_building_model_evidence_projection_scope_uq');
            });

            return;
        }

        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $timeouts = $runtime->configureSessionTimeouts();
        try {
            $this->assertNoDuplicateKeys('estimate_generation_building_models', ['id', 'organization_id', 'project_id', 'session_id', 'content_version'], 'eg_building_models_projection_scope_uq');
            $runtime->ensureConcurrentIndex('eg_building_models_projection_scope_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_building_models_projection_scope_uq ON estimate_generation_building_models (id, organization_id, project_id, session_id, content_version)');
            $runtime->checkpoint('000150.building_models_projection_scope_index');

            $this->assertNoDuplicateKeys('estimate_generation_building_model_evidence', ['building_model_id', 'evidence_id', 'organization_id', 'project_id', 'session_id'], 'eg_building_model_evidence_projection_scope_uq');
            $runtime->ensureConcurrentIndex('eg_building_model_evidence_projection_scope_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_building_model_evidence_projection_scope_uq ON estimate_generation_building_model_evidence (building_model_id, evidence_id, organization_id, project_id, session_id)');
            $runtime->checkpoint('000150.building_model_evidence_projection_scope_index');
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('estimate_generation_building_model_evidence', function (Blueprint $table): void {
                $table->dropUnique('eg_building_model_evidence_projection_scope_uq');
            });
            Schema::table('estimate_generation_building_models', function (Blueprint $table): void {
                $table->dropUnique('eg_building_models_projection_scope_uq');
            });

            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_building_model_evidence_projection_scope_uq');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_building_models_projection_scope_uq');
    }

    private function assertNoDuplicateKeys(string $table, array $columns, string $index): void
    {
        $duplicate = DB::table($table)
            ->select($columns)
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->first();

        if ($duplicate !== null) {
            throw new \RuntimeException('estimate_generation.project_model_unique_index_duplicates:'.$index);
        }
    }
};
