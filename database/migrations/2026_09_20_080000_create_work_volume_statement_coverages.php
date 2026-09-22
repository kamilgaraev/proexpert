<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_volume_coverage_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('statement_id')->constrained('work_volume_statements')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('operation_key', 128);
            $table->string('operation_hash', 64);
            $table->timestampTz('created_at');
            $table->unique(['statement_id', 'revision'], 'wvcr_statement_revision');
            $table->unique(['statement_id', 'actor_id', 'operation_key'], 'wvcr_actor_operation');
        });
        Schema::create('work_volume_statement_coverages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('statement_id')->constrained('work_volume_statements')->cascadeOnDelete();
            $table->foreignId('statement_line_id')->constrained('work_volume_statement_lines')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('estimate_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 24, 6);
            $table->decimal('estimate_quantity', 24, 6);
            $table->string('estimate_unit_code', 32);
            $table->unsignedInteger('coverage_revision')->default(1);
            $table->string('unit_code', 32);
            $table->foreignId('source_link_id')->nullable();
            $table->jsonb('conversion_basis')->nullable();
            $table->jsonb('source_snapshot')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'project_id', 'contract_id'], 'wvsc_scope_contract');
            $table->index(['statement_id', 'coverage_revision'], 'wvsc_statement_revision');
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION wvsc_protect_history() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'wvs_coverage_history_immutable' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER wvsc_protect_history BEFORE UPDATE OR DELETE ON work_volume_statement_coverages
FOR EACH ROW EXECUTE FUNCTION wvsc_protect_history();
CREATE TRIGGER wvcr_protect_history BEFORE UPDATE OR DELETE ON work_volume_coverage_revisions
FOR EACH ROW EXECUTE FUNCTION wvsc_protect_history();
SQL);
    }
    public function down(): void
    {
        Schema::dropIfExists('work_volume_statement_coverages');
        Schema::dropIfExists('work_volume_coverage_revisions');
        DB::unprepared('DROP FUNCTION IF EXISTS wvsc_protect_history()');
    }
};
