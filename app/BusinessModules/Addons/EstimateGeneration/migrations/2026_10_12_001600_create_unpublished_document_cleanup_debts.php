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
            throw new RuntimeException('Document cleanup journal requires PostgreSQL.');
        }
        Schema::create('estimate_generation_document_cleanup_debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->restrictOnDelete();
            $table->string('storage_path', 512);
            $table->char('checksum_sha256', 64);
            $table->unsignedBigInteger('file_size_bytes');
            $table->char('identity_hash', 64)->unique();
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_failure_code', 64)->nullable();
            $table->timestampTz('next_attempt_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'next_attempt_at', 'id'], 'eg_cleanup_pending_idx');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_document_cleanup_debts ADD CONSTRAINT eg_cleanup_state_ck
CHECK (status IN ('pending','completed','blocked_reference','blocked_identity','exhausted') AND attempts BETWEEN 0 AND 10
 AND file_size_bytes BETWEEN 1 AND 209715200 AND checksum_sha256 ~ '^[a-f0-9]{64}$' AND identity_hash ~ '^[a-f0-9]{64}$');
CREATE FUNCTION eg_document_cleanup_scope_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM estimate_generation_sessions s WHERE s.id=NEW.session_id
        AND s.organization_id=NEW.organization_id AND s.project_id=NEW.project_id)
      OR NEW.storage_path NOT LIKE ('org-'||NEW.organization_id||'/estimate-generation/sessions/'||NEW.session_id||'/documents/%')
      OR substring(NEW.storage_path from length('org-'||NEW.organization_id||'/estimate-generation/sessions/'||NEW.session_id||'/documents/')+1)
        !~ '^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\.[a-zA-Z0-9]{1,12}$'
    THEN RAISE EXCEPTION 'estimate_generation.cleanup_scope_invalid'; END IF;
    IF TG_OP='UPDATE' AND ROW(NEW.organization_id,NEW.project_id,NEW.session_id,NEW.storage_path,NEW.checksum_sha256,NEW.file_size_bytes,NEW.identity_hash)
      IS DISTINCT FROM ROW(OLD.organization_id,OLD.project_id,OLD.session_id,OLD.storage_path,OLD.checksum_sha256,OLD.file_size_bytes,OLD.identity_hash)
    THEN RAISE EXCEPTION 'estimate_generation.cleanup_identity_immutable'; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER eg_document_cleanup_scope_trg BEFORE INSERT OR UPDATE ON estimate_generation_document_cleanup_debts
FOR EACH ROW EXECUTE FUNCTION eg_document_cleanup_scope_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_document_cleanup_debts');
        DB::statement('DROP FUNCTION IF EXISTS eg_document_cleanup_scope_guard()');
    }
};
