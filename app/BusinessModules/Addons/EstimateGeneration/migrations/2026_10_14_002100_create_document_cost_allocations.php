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
            throw new RuntimeException('Document cost allocations require PostgreSQL.');
        }
        Schema::create('ai_credit_provider_cost_allocations', function (Blueprint $table): void {
            $table->foreignId('source_provider_usage_id')->primary()->constrained('ai_credit_provider_usages')->restrictOnDelete();
            $table->uuid('source_attempt_id')->unique();
            $table->foreign('source_attempt_id')->references('attempt_id')->on('estimate_generation_credit_attempts')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->restrictOnDelete();
            $table->foreignId('destination_reservation_id')->constrained('ai_credit_reservations')->restrictOnDelete();
            $table->uuid('destination_operation_id');
            $table->foreign('destination_operation_id')->references('operation_id')->on('estimate_generation_credit_operations')->restrictOnDelete();
            $table->uuid('result_revision_id')->nullable();
            $table->char('result_content_hash', 64);
            $table->unsignedBigInteger('cost_micro_rub');
            $table->jsonb('source_pin');
            $table->timestampTz('created_at');
            $table->index(['organization_id', 'destination_reservation_id'], 'ai_credit_cost_destination_idx');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE ai_credit_provider_cost_allocations ADD CONSTRAINT ai_credit_cost_allocation_ck CHECK (
 cost_micro_rub > 0 AND result_content_hash ~ '^[a-f0-9]{64}$' AND jsonb_typeof(source_pin)='object');
CREATE FUNCTION eg_document_cost_allocation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP <> 'INSERT' THEN RAISE EXCEPTION 'estimate_generation.document_cost_allocation_immutable'; END IF;
 IF NOT EXISTS (
  SELECT 1 FROM estimate_generation_credit_attempts a
  JOIN estimate_generation_credit_operations source ON source.operation_id=a.operation_id
  JOIN ai_credit_provider_usages u ON u.id=NEW.source_provider_usage_id AND u.usage_key=a.attempt_id::text AND u.ai_credit_reservation_id=a.reservation_id
  JOIN estimate_generation_ai_usage v ON v.attempt_id=a.attempt_id
  JOIN estimate_generation_vision_physical_attempts p ON p.attempt_id=a.attempt_id AND p.credit_operation_id=a.operation_id AND p.credit_role=a.role
  JOIN estimate_generation_processing_units t ON t.id=v.unit_id AND t.id=p.unit_id
  JOIN estimate_generation_documents d ON d.id=v.document_id AND d.id=t.document_id
  JOIN estimate_generation_document_pages g ON g.id=v.page_id AND g.processing_unit_id=t.id AND g.document_id=d.id
  JOIN estimate_generation_credit_operations destination ON destination.operation_id=NEW.destination_operation_id
  JOIN ai_credit_reservations r ON r.id=NEW.destination_reservation_id AND r.id=destination.reservation_id
  WHERE a.attempt_id=NEW.source_attempt_id AND a.organization_id=NEW.organization_id AND a.project_id=NEW.project_id AND a.session_id=NEW.session_id
   AND source.organization_id=NEW.organization_id AND source.project_id=NEW.project_id AND source.session_id=NEW.session_id
   AND destination.organization_id=NEW.organization_id AND destination.project_id=NEW.project_id AND destination.session_id=NEW.session_id
   AND u.organization_id=NEW.organization_id AND r.organization_id=NEW.organization_id AND r.user_id=destination.actor_user_id
   AND source.kind='documents' AND source.status='settled' AND source.consumed_units_minor=0
   AND destination.kind IN ('generation','analysis','rebuild') AND destination.status IN ('reserved','running') AND r.status='reserved'
   AND a.status='recorded' AND a.usage_transferred_at IS NOT NULL AND a.role IN ('vision','ocr') AND v.operation=a.role
   AND u.is_successful AND u.cost_micro_rub=NEW.cost_micro_rub AND v.status='succeeded' AND v.usage_status='measured' AND v.pricing_status='available' AND v.currency='RUB'
   AND u.metadata->>'cost_available'='true' AND u.metadata->>'provider_usage_available'='true'
   AND u.metadata->>'cost_is_estimate' IS DISTINCT FROM 'true'
   AND u.metadata->>'estimator_usage_fingerprint'=a.usage_fingerprint AND v.immutable_fingerprint='sha256:' || a.usage_fingerprint
   AND t.organization_id=NEW.organization_id AND t.project_id=NEW.project_id AND t.session_id=NEW.session_id AND t.status='completed'
   AND d.organization_id=NEW.organization_id AND d.project_id=NEW.project_id AND d.session_id=NEW.session_id AND d.status IN ('ready','needs_review') AND d.processing_control_status<>'cancelled'
   AND g.organization_id=NEW.organization_id AND g.project_id=NEW.project_id AND g.session_id=NEW.session_id AND g.excluded_at IS NULL
   AND t.source_version='sha256:' || lower(d.checksum_sha256) AND g.source_version=t.source_version
   AND t.metadata->>'processing_attempt_id'=p.processing_lineage_id::text AND t.metadata->>'credit_operation_id'=source.operation_id::text
   AND NEW.source_pin=jsonb_build_object('source_attempt_id',a.attempt_id::text,'source_operation_id',source.operation_id::text,
    'source_provider_usage_id',u.id,'cost_micro_rub',u.cost_micro_rub,'document_id',d.id,'page_id',g.id,'unit_id',t.id,
    'source_version',t.source_version,'processing_lineage_id',p.processing_lineage_id::text,'usage_fingerprint',a.usage_fingerprint)
   AND destination.processing_plan->'document_costs'->>'contract'='estimator-document-costs:v1'
   AND destination.processing_plan->'document_costs'->'items' @> jsonb_build_array(NEW.source_pin)
 ) THEN RAISE EXCEPTION 'estimate_generation.document_cost_allocation_scope_mismatch'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER eg_document_cost_allocation_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON ai_credit_provider_cost_allocations
FOR EACH ROW EXECUTE FUNCTION eg_document_cost_allocation_guard();
CREATE FUNCTION eg_document_cost_allocation_publication_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM estimate_generation_credit_operations o JOIN ai_credit_reservations r ON r.id=o.reservation_id
  WHERE o.operation_id=NEW.destination_operation_id AND o.status='settled' AND r.id=NEW.destination_reservation_id
   AND r.status='finalized' AND r.consumed_minor>0 AND o.consumed_units_minor=r.consumed_minor
   AND o.result_revision_id IS NOT DISTINCT FROM NEW.result_revision_id
   AND o.receipt->>'result_content_hash'=NEW.result_content_hash AND o.receipt->>'useful_result'='true')
 THEN RAISE EXCEPTION 'estimate_generation.document_cost_allocation_unpublished'; END IF;
 RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER eg_document_cost_allocation_publication_trg AFTER INSERT ON ai_credit_provider_cost_allocations
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION eg_document_cost_allocation_publication_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_provider_cost_allocations');
        DB::statement('DROP FUNCTION IF EXISTS eg_document_cost_allocation_guard()');
        DB::statement('DROP FUNCTION IF EXISTS eg_document_cost_allocation_publication_guard()');
    }
};
