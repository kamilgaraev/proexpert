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
        Schema::table('estimate_norms', function (Blueprint $table): void {
            $table->string('canonical_unit', 50)->nullable();
            $table->string('unit_dimension', 50)->nullable();
            $table->string('material', 150)->nullable();
            $table->string('technology', 150)->nullable();
            $table->string('structure', 150)->nullable();
            $table->string('object_type', 100)->nullable();
            $table->string('region_code', 20)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->index(['collection_id', 'canonical_unit'], 'estimate_norms_collection_unit_idx');
            $table->index(['section_code', 'unit_dimension'], 'estimate_norms_section_dimension_idx');
        });

        DB::table('estimate_norms')->update(['canonical_unit' => DB::raw('unit')]);
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("UPDATE estimate_norms SET unit_dimension = CASE WHEN canonical_unit IN ('м2','м²') THEN 'area' WHEN canonical_unit IN ('м3','м³') THEN 'volume' WHEN canonical_unit IN ('м','м.п.') THEN 'length' WHEN canonical_unit IN ('шт','компл') THEN 'count' ELSE NULL END");
            DB::statement("UPDATE estimate_norms SET material = NULLIF(raw_payload->>'material',''), technology = NULLIF(raw_payload->>'technology',''), structure = NULLIF(raw_payload->>'structure',''), object_type = NULLIF(raw_payload->>'object_type',''), region_code = NULLIF(raw_payload->>'region_code',''), valid_from = CASE WHEN raw_payload->>'valid_from' ~ '^\\d{4}-\\d{2}-\\d{2}$' THEN (raw_payload->>'valid_from')::date END, valid_to = CASE WHEN raw_payload->>'valid_to' ~ '^\\d{4}-\\d{2}-\\d{2}$' THEN (raw_payload->>'valid_to')::date END");
            DB::statement("ALTER TABLE estimate_norms ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('russian', coalesce(code,'') || ' ' || coalesce(name,'') || ' ' || coalesce(section_name,''))) STORED");
            DB::statement('CREATE INDEX estimate_norms_search_vector_gin ON estimate_norms USING gin (search_vector)');
            DB::statement('ALTER TABLE estimate_norms ADD CONSTRAINT estimate_norms_validity_ck CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)');
        }

        Schema::create('estimate_norm_semantic_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('estimate_norm_id')->constrained('estimate_norms')->cascadeOnDelete();
            $table->string('index_version', 100);
            $table->char('query_hash', 64);
            $table->decimal('score', 12, 10);
            $table->timestamps();
            $table->unique(['estimate_norm_id', 'index_version', 'query_hash'], 'estimate_norm_semantic_version_uq');
            $table->index(['index_version', 'query_hash', 'score'], 'estimate_norm_semantic_lookup_idx');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_norm_semantic_scores ADD CONSTRAINT estimate_norm_semantic_score_ck CHECK (score >= 0 AND score <= 1)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_norm_semantic_scores');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_norms DROP CONSTRAINT IF EXISTS estimate_norms_validity_ck');
            DB::statement('DROP INDEX IF EXISTS estimate_norms_search_vector_gin');
            DB::statement('ALTER TABLE estimate_norms DROP COLUMN IF EXISTS search_vector');
        }
        Schema::table('estimate_norms', function (Blueprint $table): void {
            $table->dropIndex('estimate_norms_collection_unit_idx');
            $table->dropIndex('estimate_norms_section_dimension_idx');
            $table->dropColumn(['canonical_unit', 'unit_dimension', 'material', 'technology', 'structure', 'object_type', 'region_code', 'valid_from', 'valid_to']);
        });
    }
};
