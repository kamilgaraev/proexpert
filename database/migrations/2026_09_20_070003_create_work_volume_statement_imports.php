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
        Schema::create('work_volume_statement_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('operation_key', 128);
            $table->string('operation_hash', 64);
            $table->string('source_file_path');
            $table->string('source_file_hash', 64);
            $table->string('source_file_name');
            $table->unsignedBigInteger('source_file_size');
            $table->string('sheet_name')->nullable();
            $table->jsonb('sheet_names');
            $table->jsonb('options');
            $table->jsonb('raw_rows');
            $table->jsonb('preview_rows');
            $table->jsonb('preview_errors');
            $table->unsignedInteger('preview_version')->default(1);
            $table->string('preview_hash', 64);
            $table->string('status', 24)->default('draft');
            $table->foreignId('statement_id')->nullable()->constrained('work_volume_statements')->restrictOnDelete();
            $table->string('registration_hash', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'project_id', 'operation_key'], 'wvsi_scope_operation');
        });
        Schema::table('work_volume_statements', function (Blueprint $table): void {
            $table->foreignId('source_import_id')->nullable()->constrained('work_volume_statement_imports')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION wvsi_protect_source() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' OR OLD.status = 'registered' THEN
        RAISE EXCEPTION 'wvs_import_source_immutable' USING ERRCODE = '55000';
    END IF;
    IF (to_jsonb(NEW) - ARRAY['preview_rows', 'preview_errors', 'preview_version', 'preview_hash', 'status', 'statement_id', 'registration_hash', 'updated_at']) IS DISTINCT FROM
       (to_jsonb(OLD) - ARRAY['preview_rows', 'preview_errors', 'preview_version', 'preview_hash', 'status', 'statement_id', 'registration_hash', 'updated_at']) THEN
        RAISE EXCEPTION 'wvs_import_source_immutable' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER wvsi_protect_source BEFORE UPDATE OR DELETE ON work_volume_statement_imports
FOR EACH ROW EXECUTE FUNCTION wvsi_protect_source();
SQL);
    }

    public function down(): void
    {
        Schema::table('work_volume_statements', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_import_id'));
        Schema::dropIfExists('work_volume_statement_imports');
        DB::unprepared('DROP FUNCTION IF EXISTS wvsi_protect_source();');
    }
};
