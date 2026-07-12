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
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_norm_semantic_scores');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_norms DROP COLUMN IF EXISTS search_vector');
        }
        Schema::table('estimate_norms', function (Blueprint $table): void {
            $table->dropColumn(['canonical_unit', 'unit_dimension', 'material', 'technology', 'structure', 'object_type', 'region_code', 'valid_from', 'valid_to']);
        });
    }
};
