<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('estimate_generation_sheet_analysis_operations', function (Blueprint $table): void {
            $table->uuid('operation_id')->primary(); $table->string('kind', 16); $table->unsignedBigInteger('organization_id'); $table->unsignedBigInteger('project_id'); $table->unsignedBigInteger('session_id'); $table->unsignedBigInteger('document_id'); $table->unsignedBigInteger('unit_id'); $table->char('source_version', 71); $table->string('status', 16); $table->uuid('lease_token')->nullable(); $table->timestampTz('lease_expires_at')->nullable(); $table->unsignedInteger('attempt_count')->default(0); $table->jsonb('analysis_payload')->default('{}'); $table->jsonb('initial_routing')->default('{}'); $table->jsonb('final_routing')->default('{}'); $table->string('failure_reason', 160)->nullable(); $table->timestampTz('completed_at')->nullable(); $table->timestampTz('audit_recorded_at')->nullable(); $table->timestampsTz();
            $table->unique(['session_id','document_id','unit_id','source_version','kind'], 'eg_sheet_analysis_scope_kind_uq');
            $table->index(['status','lease_expires_at'], 'eg_sheet_analysis_claim_idx');
            $table->foreign(['session_id','organization_id','project_id'], 'eg_sheet_analysis_session_fk')->references(['id','organization_id','project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') { DB::statement("ALTER TABLE estimate_generation_sheet_analysis_operations ADD CONSTRAINT eg_sheet_analysis_status_ck CHECK (kind IN ('primary','targeted') AND status IN ('queued','claimed','completed','failed','needs_review','exhausted') AND ((lease_token IS NULL AND lease_expires_at IS NULL) OR (lease_token IS NOT NULL AND lease_expires_at IS NOT NULL)) AND jsonb_typeof(analysis_payload) = 'object' AND jsonb_typeof(initial_routing) = 'object' AND jsonb_typeof(final_routing) = 'object')"); DB::statement("CREATE UNIQUE INDEX eg_sheet_analysis_audit_operation_uq ON estimate_generation_audit_events (session_id, event_type, (payload->>'operation_id')) WHERE event_type = 'sheet_targeted_reanalysis'"); }
    }
    public function down(): void { Schema::dropIfExists('estimate_generation_sheet_analysis_operations'); }
};
