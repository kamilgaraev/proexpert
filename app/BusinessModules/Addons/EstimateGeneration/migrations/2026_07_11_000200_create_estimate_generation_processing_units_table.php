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
        Schema::table('estimate_generation_documents', function (Blueprint $table): void {
            $table->string('source_version', 80)->nullable()->after('checksum_sha256');
            $table->string('units_finalized_source_version', 80)->nullable()->after('source_version');
            $table->string('units_reconciled_source_version', 80)->nullable()->after('units_finalized_source_version');
            $table->string('units_reconcile_claim_token', 36)->nullable()->after('units_reconciled_source_version');
            $table->timestampTz('units_reconcile_lease_expires_at')->nullable()->after('units_reconcile_claim_token');
            $table->index(['id', 'source_version'], 'eg_documents_source_idx');
        });

        Schema::create('estimate_generation_processing_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('estimate_generation_documents')->cascadeOnDelete();
            $table->string('unit_type', 40);
            $table->unsignedInteger('unit_index');
            $table->string('source_version', 80);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('claim_token', 36)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('output_version', 80)->nullable();
            $table->unsignedInteger('output_count')->default(0);
            $table->unsignedSmallInteger('dispatch_attempt_count')->default(0);
            $table->timestampTz('last_dispatched_at')->nullable();
            $table->timestampTz('next_dispatch_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->string('failure_fingerprint', 64)->nullable();
            $table->jsonb('locator')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
            $table->unique(
                ['document_id', 'unit_type', 'unit_index', 'source_version'],
                'eg_processing_units_identity_uq',
            );
            $table->index(['session_id', 'source_version', 'status'], 'eg_units_session_source_status_idx');
            $table->index(['status', 'lease_expires_at'], 'eg_units_status_lease_idx');
            $table->index(['organization_id', 'project_id', 'document_id'], 'eg_units_tenant_document_idx');
        });

        Schema::table('estimate_generation_document_pages', function (Blueprint $table): void {
            $table->foreignId('processing_unit_id')->nullable()
                ->constrained('estimate_generation_processing_units')->nullOnDelete();
            $table->string('source_version', 80)->nullable();
            $table->string('output_version', 80)->nullable();
            $table->unique('processing_unit_id', 'eg_document_pages_unit_uq');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_generation_documents ADD CONSTRAINT eg_documents_reconcile_claim_ck CHECK ((units_reconcile_claim_token IS NULL) = (units_reconcile_lease_expires_at IS NULL))');
            DB::statement("ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_type_ck CHECK (unit_type IN ('pdf_page','spreadsheet_sheet','raster_image','sketch','cad_drawing','text_page'))");
            DB::statement("ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_status_ck CHECK (status IN ('pending','running','completed','failed','superseded'))");
            DB::statement('ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_index_ck CHECK (unit_index BETWEEN 1 AND 100000)');
            DB::statement("ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_claim_ck CHECK ((status = 'running' AND claim_token IS NOT NULL AND lease_expires_at IS NOT NULL) OR (status <> 'running' AND claim_token IS NULL AND lease_expires_at IS NULL))");
            DB::statement("ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_output_ck CHECK ((status = 'completed' AND output_version IS NOT NULL) OR (status <> 'completed' AND output_version IS NULL))");
            DB::statement('ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_source_ck CHECK (char_length(source_version) BETWEEN 1 AND 80)');
            DB::statement('ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_attempt_ck CHECK (attempt_count >= 0)');
            DB::statement('ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_dispatch_ck CHECK (dispatch_attempt_count >= 0)');
            DB::statement("ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_terminal_ck CHECK ((status = 'completed' AND output_count >= 1 AND completed_at IS NOT NULL AND failure_code IS NULL AND failure_fingerprint IS NULL) OR (status = 'failed' AND output_count = 0 AND failed_at IS NOT NULL AND failure_code IS NOT NULL AND failure_fingerprint IS NOT NULL) OR status IN ('pending','running','superseded'))");
            DB::statement("ALTER TABLE estimate_generation_processing_units ADD CONSTRAINT eg_units_json_ck CHECK (jsonb_typeof(locator) = 'object' AND jsonb_typeof(metadata) = 'object')");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_generation_documents DROP CONSTRAINT IF EXISTS eg_documents_reconcile_claim_ck');
        }

        Schema::table('estimate_generation_document_pages', function (Blueprint $table): void {
            $table->dropUnique('eg_document_pages_unit_uq');
            $table->dropConstrainedForeignId('processing_unit_id');
            $table->dropColumn(['source_version', 'output_version']);
        });
        Schema::dropIfExists('estimate_generation_processing_units');
        Schema::table('estimate_generation_documents', function (Blueprint $table): void {
            $table->dropIndex('eg_documents_source_idx');
            $table->dropColumn(['source_version', 'units_finalized_source_version', 'units_reconciled_source_version', 'units_reconcile_claim_token', 'units_reconcile_lease_expires_at']);
        });
    }
};
