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
        Schema::create('contract_proposal_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained('contract_builder_proposals')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('clause_id', 512)->nullable();
            $table->boolean('blocking')->default(false);
            $table->text('body');
            $table->char('content_hash', 64);
            $table->string('request_key', 191);
            $table->char('request_fingerprint', 64);
            $table->timestampTz('created_at');
            $table->unique(['proposal_id', 'created_by', 'request_key'], 'contract_proposal_comment_request');
            $table->index(['proposal_id', 'clause_id']);
        });
        Schema::create('contract_proposal_clause_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_id')->constrained('contract_builder_proposal_recipients')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('clause_id', 512);
            $table->string('decision', 16);
            $table->text('reason');
            $table->text('basis')->nullable();
            $table->jsonb('proof')->nullable();
            $table->char('content_hash', 64);
            $table->string('request_key', 191);
            $table->char('request_fingerprint', 64);
            $table->timestampTz('created_at');
            $table->unique(['recipient_id', 'clause_id'], 'contract_proposal_clause_final');
            $table->unique(['recipient_id', 'created_by', 'request_key'], 'contract_proposal_clause_request');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE contract_proposal_comments ADD CONSTRAINT contract_proposal_comment_shape CHECK (
 length(trim(body)) BETWEEN 1 AND 2000 AND (NOT blocking OR clause_id IS NOT NULL)
 AND (clause_id IS NULL OR (length(clause_id) BETWEEN 1 AND 512 AND clause_id ~ '^[A-Za-z0-9_-]+$'))
);
ALTER TABLE contract_proposal_clause_decisions ADD CONSTRAINT contract_proposal_clause_shape CHECK (
 length(clause_id) BETWEEN 1 AND 512 AND clause_id ~ '^[A-Za-z0-9_-]+$' AND decision IN ('accepted','rejected') AND length(trim(reason)) BETWEEN 1 AND 2000
 AND (proof IS NULL OR (jsonb_typeof(proof) = 'object' AND basis IS NOT NULL AND length(trim(basis)) > 0))
);
CREATE FUNCTION guard_contract_proposal_review() RETURNS trigger AS $$
BEGIN
 RAISE EXCEPTION 'contract_proposal_review_immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_proposal_comment_guard BEFORE UPDATE OR DELETE ON contract_proposal_comments
FOR EACH ROW EXECUTE FUNCTION guard_contract_proposal_review();
CREATE TRIGGER contract_proposal_clause_guard BEFORE UPDATE OR DELETE ON contract_proposal_clause_decisions
FOR EACH ROW EXECUTE FUNCTION guard_contract_proposal_review();
CREATE FUNCTION guard_contract_proposal_review_insert() RETURNS trigger AS $$
DECLARE frozen jsonb; author_id bigint; recipient_org bigint; expected_hash char(64); target_id bigint;
BEGIN
 IF TG_TABLE_NAME = 'contract_proposal_comments' THEN
   target_id := NEW.proposal_id;
   SELECT content, author_organization_id, content_hash INTO frozen, author_id, expected_hash
     FROM contract_builder_proposals WHERE id = target_id AND status = 'pending';
   IF NEW.organization_id <> author_id AND NOT EXISTS (SELECT 1 FROM contract_builder_proposal_recipients WHERE proposal_id = target_id AND party_organization_id = NEW.organization_id)
     THEN RAISE EXCEPTION 'contract_proposal_comment_organization_invalid'; END IF;
   IF NEW.blocking AND EXISTS (SELECT 1 FROM contract_builder_proposal_recipients WHERE proposal_id = target_id AND status <> 'pending')
     THEN RAISE EXCEPTION 'contract_proposal_comment_after_decision'; END IF;
 ELSE
   SELECT p.id, p.content, p.author_organization_id, p.content_hash, r.party_organization_id
     INTO target_id, frozen, author_id, expected_hash, recipient_org
     FROM contract_builder_proposal_recipients r JOIN contract_builder_proposals p ON p.id = r.proposal_id
     WHERE r.id = NEW.recipient_id AND p.status = 'pending' AND r.status = 'pending';
   IF recipient_org IS NOT NULL AND (NEW.organization_id <> recipient_org OR NEW.proof IS NOT NULL OR NEW.basis IS NOT NULL)
      OR recipient_org IS NULL AND (NEW.organization_id <> author_id OR NEW.proof IS NULL OR NEW.basis IS NULL OR length(trim(NEW.basis)) = 0)
     THEN RAISE EXCEPTION 'contract_proposal_clause_organization_invalid'; END IF;
 END IF;
 IF expected_hash IS NULL OR NEW.content_hash <> expected_hash
    OR NEW.clause_id IS NOT NULL AND NOT jsonb_path_exists(frozen->'document', '$.** ? (@.type == "clause" && @.attrs.id == $id)', jsonb_build_object('id', NEW.clause_id))
    THEN RAISE EXCEPTION 'contract_proposal_review_target_invalid'; END IF;
 RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_proposal_comment_insert_guard BEFORE INSERT ON contract_proposal_comments
FOR EACH ROW EXECUTE FUNCTION guard_contract_proposal_review_insert();
CREATE TRIGGER contract_proposal_clause_insert_guard BEFORE INSERT ON contract_proposal_clause_decisions
FOR EACH ROW EXECUTE FUNCTION guard_contract_proposal_review_insert();
CREATE FUNCTION guard_contract_proposal_recipient_review() RETURNS trigger AS $$
BEGIN
 IF NEW.status = 'accepted' AND (
   EXISTS (SELECT 1 FROM contract_proposal_comments c WHERE c.proposal_id = NEW.proposal_id AND c.blocking
     AND NOT EXISTS (SELECT 1 FROM contract_proposal_clause_decisions d WHERE d.recipient_id = NEW.id AND d.clause_id = c.clause_id AND d.decision = 'accepted'))
   OR EXISTS (SELECT 1 FROM contract_proposal_clause_decisions WHERE recipient_id = NEW.id AND decision = 'rejected'))
   THEN RAISE EXCEPTION 'contract_proposal_clause_decisions_incomplete'; END IF;
 RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_proposal_recipient_review_guard BEFORE UPDATE ON contract_builder_proposal_recipients
FOR EACH ROW EXECUTE FUNCTION guard_contract_proposal_recipient_review();
SQL);
    }

    public function down(): void
    {
        if (DB::table('contract_proposal_comments')->exists() || DB::table('contract_proposal_clause_decisions')->exists()) throw new RuntimeException('contract_proposal_review_requires_preservation');
        DB::unprepared('DROP TRIGGER contract_proposal_recipient_review_guard ON contract_builder_proposal_recipients; DROP FUNCTION guard_contract_proposal_recipient_review();');
        Schema::drop('contract_proposal_clause_decisions');
        Schema::drop('contract_proposal_comments');
        DB::unprepared('DROP FUNCTION guard_contract_proposal_review(); DROP FUNCTION guard_contract_proposal_review_insert();');
    }
};
