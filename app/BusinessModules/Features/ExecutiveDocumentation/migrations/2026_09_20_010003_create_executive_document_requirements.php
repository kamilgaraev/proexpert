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
        Schema::create('executive_document_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('document_set_id')->constrained('executive_document_sets')->cascadeOnDelete();
            $table->foreignId('work_type_id')->nullable()->constrained('work_types')->nullOnDelete();
            $table->foreignId('project_location_id')->nullable()->constrained('project_locations')->nullOnDelete();
            $table->foreignId('completed_work_id')->nullable()->constrained('completed_works')->nullOnDelete();
            $table->string('stage', 64);
            $table->string('requirement_key', 128);
            $table->string('title', 255);
            $table->string('profile_type', 100);
            $table->string('applicability', 32)->default('required');
            $table->unsignedInteger('revision')->default(1);
            $table->text('applicability_reason')->nullable();
            $table->unsignedBigInteger('applicability_by')->nullable();
            $table->timestampTz('applicability_at')->nullable();
            $table->string('source', 255);
            $table->string('source_revision', 128);
            $table->jsonb('rule_snapshot');
            $table->jsonb('coverage_scope')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->text('not_applicable_reason')->nullable();
            $table->foreignId('not_applicable_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('not_applicable_at')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'project_id', 'document_set_id']);
            $table->index(['document_set_id', 'stage', 'profile_type']);
            $table->index(['document_set_id', 'superseded_at']);
        });
        Schema::create('executive_document_requirement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requirement_id')->constrained('executive_document_requirements')->restrictOnDelete();
            $table->unsignedBigInteger('document_set_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 40);
            $table->string('operation_key', 128)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->unique(['document_set_id', 'operation_key'], 'executive_requirement_operation_unique');
            $table->jsonb('before_snapshot')->nullable();
            $table->jsonb('after_snapshot');
            $table->timestampTz('created_at');
            $table->index(['document_set_id', 'id']);
            $table->index(['requirement_id', 'id']);
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_executive_requirement_event() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'executive_requirement_event_immutable';
END;
$$;
CREATE TRIGGER executive_requirement_event_immutable
BEFORE UPDATE OR DELETE ON executive_document_requirement_events
FOR EACH ROW EXECUTE FUNCTION guard_executive_requirement_event();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_document_requirement_events');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_executive_requirement_event()');
        Schema::dropIfExists('executive_document_requirements');
    }
};
