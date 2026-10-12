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
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Document admission requires PostgreSQL.');
        }
        Schema::create('estimate_generation_document_admissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('kind', 12);
            $table->unsignedInteger('expected_version');
            $table->char('request_hash', 64);
            $table->string('status', 12)->default('pending');
            $table->unsignedInteger('reserved_slots');
            $table->jsonb('objects');
            $table->jsonb('document_ids')->default('[]');
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['organization_id', 'request_id'], 'eg_admission_request_uq');
            $table->index(['status', 'expires_at', 'id'], 'eg_admission_recovery_idx');
            $table->index(['session_id', 'status'], 'eg_admission_slots_idx');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_document_admissions ADD CONSTRAINT eg_admission_state_ck
CHECK (kind IN ('upload','reuse') AND status IN ('pending','accepted','failed')
 AND expected_version >= 0 AND reserved_slots BETWEEN 0 AND 1000
 AND request_hash ~ '^[a-f0-9]{64}$' AND jsonb_typeof(objects)='array'
 AND jsonb_array_length(objects)=reserved_slots AND jsonb_typeof(document_ids)='array'
 AND (status<>'accepted' OR jsonb_array_length(document_ids)=reserved_slots)
 AND (status='accepted' OR jsonb_array_length(document_ids)=0));
CREATE FUNCTION eg_document_admission_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE object jsonb;
BEGIN
    IF NOT EXISTS (SELECT 1 FROM estimate_generation_sessions s WHERE s.id=NEW.session_id
        AND s.organization_id=NEW.organization_id AND s.project_id=NEW.project_id)
    THEN RAISE EXCEPTION 'estimate_generation.admission_scope_invalid'; END IF;
    FOR object IN SELECT value FROM jsonb_array_elements(NEW.objects) LOOP
        IF jsonb_typeof(object)<>'object' OR jsonb_typeof(object->'size')<>'number'
          OR jsonb_typeof(object->'path')<>'string' OR jsonb_typeof(object->'sha256')<>'string'
          OR object->>'size' !~ '^[1-9][0-9]{0,8}$'
          OR (object->>'size')::bigint NOT BETWEEN 1 AND 209715200
          OR object->>'sha256' !~ '^[a-f0-9]{64}$'
          OR object->>'path' NOT LIKE ('org-'||NEW.organization_id||'/estimate-generation/sessions/'||NEW.session_id||'/documents/%')
          OR substring(object->>'path' from length('org-'||NEW.organization_id||'/estimate-generation/sessions/'||NEW.session_id||'/documents/')+1)
            !~ '^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\.[a-zA-Z0-9]{1,12}$'
          OR NOT (object ?& ARRAY['path','sha256','size']) OR (SELECT count(*) FROM jsonb_object_keys(object))<>3
        THEN RAISE EXCEPTION 'estimate_generation.admission_object_invalid'; END IF;
    END LOOP;
    IF TG_OP='UPDATE' AND ROW(NEW.organization_id,NEW.project_id,NEW.session_id,NEW.user_id,NEW.request_id,NEW.kind,
      NEW.expected_version,NEW.request_hash,NEW.reserved_slots,NEW.objects,NEW.expires_at)
      IS DISTINCT FROM ROW(OLD.organization_id,OLD.project_id,OLD.session_id,OLD.user_id,OLD.request_id,OLD.kind,
      OLD.expected_version,OLD.request_hash,OLD.reserved_slots,OLD.objects,OLD.expires_at)
    THEN RAISE EXCEPTION 'estimate_generation.admission_identity_immutable'; END IF;
    IF TG_OP='UPDATE' AND OLD.status IN ('accepted','failed') AND
      ROW(NEW.status,NEW.document_ids) IS DISTINCT FROM ROW(OLD.status,OLD.document_ids)
    THEN RAISE EXCEPTION 'estimate_generation.admission_result_immutable'; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER eg_document_admission_scope_trg BEFORE INSERT OR UPDATE ON estimate_generation_document_admissions
FOR EACH ROW EXECUTE FUNCTION eg_document_admission_scope_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_document_admissions');
        DB::statement('DROP FUNCTION IF EXISTS eg_document_admission_scope_guard()');
    }
};
