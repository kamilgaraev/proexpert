<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['estimate_generation_project_model_evidence_bindings', 'eg_project_model_evidence_model_scope_fk'],
            ['estimate_generation_project_model_relations', 'eg_project_model_relations_model_scope_fk'],
            ['estimate_generation_project_model_corrections', 'eg_project_model_corrections_model_scope_fk'],
            ['estimate_generation_project_model_assertions', 'eg_project_model_assertions_model_scope_fk'],
            ['estimate_generation_project_model_entities', 'eg_project_model_entities_model_scope_fk'],
        ] as [$table, $constraint]) {
            DB::statement(sprintf(
                'ALTER TABLE IF EXISTS %s DROP CONSTRAINT IF EXISTS %s',
                $table,
                $constraint,
            ));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Project model cutover is forward-only.');
    }
};
