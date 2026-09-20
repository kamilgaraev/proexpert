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
        Schema::create('work_volume_acceptance_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('performance_act_line_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('operation_key', 128);
            $table->string('operation_hash', 64);
            $table->decimal('source_quantity', 24, 6);
            $table->string('source_unit', 32);
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->boolean('sealed')->default(false);
            $table->timestampTz('created_at');
            $table->unique(['performance_act_line_id', 'revision'], 'wvam_source_revision');
            $table->unique(['organization_id', 'project_id', 'operation_key'], 'wvam_scope_operation');
        });
        Schema::create('work_volume_accepted_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mapping_id')->constrained('work_volume_acceptance_mappings')->restrictOnDelete();
            $table->foreignId('statement_line_id')->constrained('work_volume_statement_lines')->restrictOnDelete();
            $table->decimal('quantity', 24, 6);
            $table->jsonb('line_snapshot');
            $table->unique(['mapping_id', 'statement_line_id'], 'wvaa_mapping_line');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE work_volume_acceptance_mappings ADD CONSTRAINT wvam_quantity_valid CHECK (source_quantity > 0 AND revision > 0);
ALTER TABLE work_volume_accepted_allocations ADD CONSTRAINT wvaa_quantity_valid CHECK (quantity > 0);
CREATE FUNCTION wvam_protect_history() RETURNS trigger AS $$
BEGIN
    IF OLD.sealed THEN
        RAISE EXCEPTION 'wvs_acceptance_mapping_immutable' USING ERRCODE = '55000';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER wvam_protect_history BEFORE UPDATE OR DELETE ON work_volume_acceptance_mappings
FOR EACH ROW EXECUTE FUNCTION wvam_protect_history();
CREATE FUNCTION wvaa_protect_history() RETURNS trigger AS $$
DECLARE
    source_id bigint;
    target_id bigint;
BEGIN
    IF TG_OP <> 'INSERT' THEN source_id := OLD.mapping_id; END IF;
    IF TG_OP <> 'DELETE' THEN target_id := NEW.mapping_id; END IF;
    PERFORM id FROM work_volume_acceptance_mappings WHERE id IN (source_id, target_id) ORDER BY id FOR UPDATE;
    IF EXISTS (SELECT 1 FROM work_volume_acceptance_mappings WHERE id IN (source_id, target_id) AND sealed) THEN
        RAISE EXCEPTION 'wvs_acceptance_allocation_immutable' USING ERRCODE = '55000';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER wvaa_protect_history BEFORE INSERT OR UPDATE OR DELETE ON work_volume_accepted_allocations
FOR EACH ROW EXECUTE FUNCTION wvaa_protect_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('work_volume_accepted_allocations');
        Schema::dropIfExists('work_volume_acceptance_mappings');
        DB::unprepared('DROP FUNCTION IF EXISTS wvaa_protect_history(); DROP FUNCTION IF EXISTS wvam_protect_history();');
    }
};
