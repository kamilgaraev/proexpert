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
        $columns = ['id', 'building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version'];

        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('estimate_generation_project_model_corrections', function (Blueprint $table) use ($columns): void {
                $table->unique($columns, 'eg_project_model_corrections_scope_uq');
            });

            return;
        }

        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $timeouts = $runtime->configureSessionTimeouts();
        try {
            $runtime->ensureConcurrentIndex(
                'eg_project_model_corrections_scope_uq',
                'CREATE UNIQUE INDEX CONCURRENTLY eg_project_model_corrections_scope_uq ON estimate_generation_project_model_corrections (id, building_model_id, organization_id, project_id, session_id, source_version)'
            );
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('estimate_generation_project_model_corrections', function (Blueprint $table): void {
                $table->dropUnique('eg_project_model_corrections_scope_uq');
            });

            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_project_model_corrections_scope_uq');
    }
};
