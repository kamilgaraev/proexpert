<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = ['organization_id', 'project_id', 'version_id', 'derivative_id', 'express_id',
            'global_id', 'category', 'name', 'properties', 'classifications'];
        $values = array_map(static fn (string $column): string =>
            "COALESCE(length({$column}::text)::text || ':' || {$column}::text, '-1:')", $columns);
        DB::statement('ALTER TABLE design_ifc_model_elements ADD COLUMN source_content_hash text GENERATED ALWAYS AS (md5('.implode(' || ', $values).')) STORED, '
            ."ADD COLUMN source_schema_version integer GENERATED ALWAYS AS (CASE WHEN properties #> '{_ifc,schema_version}' = '2'::jsonb THEN 2 ELSE NULL END) STORED");
        DB::statement('CREATE INDEX design_ifc_model_elements_proof_cover_idx ON design_ifc_model_elements (organization_id, project_id, version_id, derivative_id, id) INCLUDE (source_content_hash, source_schema_version, updated_at, category)');
    }

    public function down(): void
    {
        Schema::table('design_ifc_model_elements', static function (Blueprint $table): void {
            $table->dropIndex('design_ifc_model_elements_proof_cover_idx');
            $table->dropColumn(['source_content_hash', 'source_schema_version']);
        });
    }
};
