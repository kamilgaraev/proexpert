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
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_norms ADD COLUMN search_vector tsvector NULL');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION estimate_norms_retrieval_fields_v1() RETURNS trigger AS $$
BEGIN
 NEW.canonical_unit := COALESCE(NEW.canonical_unit, NEW.unit);
 NEW.unit_dimension := COALESCE(NEW.unit_dimension, CASE WHEN NEW.unit IN ('м2','м²') THEN 'area' WHEN NEW.unit IN ('м3','м³') THEN 'volume' WHEN NEW.unit IN ('м','м.п.') THEN 'length' WHEN NEW.unit IN ('шт','компл') THEN 'count' END);
 NEW.search_vector := to_tsvector('russian', coalesce(NEW.code,'') || ' ' || coalesce(NEW.name,'') || ' ' || coalesce(NEW.section_name,''));
 RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER estimate_norms_retrieval_fields_v1 BEFORE INSERT OR UPDATE OF code,name,section_name,unit,canonical_unit,unit_dimension ON estimate_norms FOR EACH ROW EXECUTE FUNCTION estimate_norms_retrieval_fields_v1();
SQL);
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
        Schema::create('estimate_normative_retrieval_rollouts', function (Blueprint $table): void {
            $table->string('schema_version', 100)->primary();
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('target_max_id')->default(0);
            $table->string('backfill_status', 30)->default('pending');
            $table->string('deploy_phase', 30)->default('pending');
            $table->string('deploy_status', 30)->default('pending');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_normative_retrieval_rollouts');
        Schema::dropIfExists('estimate_norm_semantic_scores');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS estimate_norms_retrieval_fields_v1 ON estimate_norms');
            DB::statement('DROP FUNCTION IF EXISTS estimate_norms_retrieval_fields_v1()');
            DB::statement('ALTER TABLE estimate_norms DROP COLUMN IF EXISTS search_vector');
        }
        Schema::table('estimate_norms', function (Blueprint $table): void {
            $table->dropColumn(['canonical_unit', 'unit_dimension', 'material', 'technology', 'structure', 'object_type', 'region_code', 'valid_from', 'valid_to']);
        });
    }
};
