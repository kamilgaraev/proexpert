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
        Schema::create('contract_builder_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('contract_builder_instances')->restrictOnDelete();
            $table->unsignedBigInteger('base_revision_id');
            $table->foreignId('draft_id')->constrained('contract_builder_drafts')->restrictOnDelete();
            $table->unsignedInteger('draft_version');
            $table->foreignId('author_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('recipient_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('message');
            $table->jsonb('content');
            $table->char('content_hash', 64);
            $table->string('status', 16)->default('pending');
            $table->string('request_key', 191);
            $table->char('request_fingerprint', 64);
            $table->uuid('revision_request_key')->unique();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('decision_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->string('decision_kind', 16)->nullable();
            $table->text('decision_basis')->nullable();
            $table->jsonb('decision_proof')->nullable();
            $table->string('decision_key', 191)->nullable();
            $table->char('decision_fingerprint', 64)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->unsignedBigInteger('accepted_revision_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampTz('created_at');
            $table->unique(['instance_id', 'author_organization_id', 'request_key'], 'contract_proposal_request_unique');
            $table->foreign(['base_revision_id', 'instance_id'])->references(['id', 'instance_id'])->on('contract_builder_revisions')->restrictOnDelete();
            $table->foreign(['accepted_revision_id', 'instance_id'])->references(['id', 'instance_id'])->on('contract_builder_revisions')->restrictOnDelete();
            $table->index(['instance_id', 'status', 'id']);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE contract_builder_proposals ADD CONSTRAINT contract_proposal_shape CHECK (
    draft_version > 0 AND lock_version > 0 AND jsonb_typeof(content) = 'object'
    AND (recipient_organization_id IS NULL OR recipient_organization_id <> author_organization_id)
    AND ((status = 'pending' AND decided_by IS NULL AND decided_at IS NULL AND accepted_revision_id IS NULL)
      OR (status IN ('accepted', 'rejected') AND decided_by IS NOT NULL AND decided_at IS NOT NULL
          AND decision_key IS NOT NULL AND decision_fingerprint IS NOT NULL
          AND ((decision_kind = 'connected' AND recipient_organization_id IS NOT NULL AND decision_organization_id = recipient_organization_id AND decision_proof IS NULL)
              OR (decision_kind = 'external' AND recipient_organization_id IS NULL AND decision_organization_id = author_organization_id
                  AND decision_basis IS NOT NULL AND length(trim(decision_basis)) > 0 AND decision_proof IS NOT NULL AND jsonb_typeof(decision_proof) = 'object'))
          AND ((status = 'accepted' AND accepted_revision_id IS NOT NULL) OR (status = 'rejected' AND accepted_revision_id IS NULL))))
);
CREATE FUNCTION guard_contract_builder_proposal() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'contract_builder_proposal_immutable';
    END IF;
    IF OLD.status <> 'pending' OR NEW.status NOT IN ('accepted', 'rejected')
       OR (to_jsonb(OLD) - ARRAY['status','decided_by','decision_organization_id','decision_reason','decision_kind','decision_basis','decision_proof','decision_key','decision_fingerprint','decided_at','accepted_revision_id','lock_version'])
          IS DISTINCT FROM
          (to_jsonb(NEW) - ARRAY['status','decided_by','decision_organization_id','decision_reason','decision_kind','decision_basis','decision_proof','decision_key','decision_fingerprint','decided_at','accepted_revision_id','lock_version']) THEN
        RAISE EXCEPTION 'contract_builder_proposal_immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_builder_proposal_guard BEFORE UPDATE OR DELETE ON contract_builder_proposals
FOR EACH ROW EXECUTE FUNCTION guard_contract_builder_proposal();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_builder_proposals');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_contract_builder_proposal();');
    }
};
