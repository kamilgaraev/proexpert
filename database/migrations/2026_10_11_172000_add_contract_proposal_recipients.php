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
        Schema::create('contract_builder_proposal_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('proposal_id')->constrained('contract_builder_proposals')->restrictOnDelete();
            $table->string('party_key', 36);
            $table->string('party_name', 512);
            $table->foreignId('party_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('decision_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->text('decision_reason')->nullable();
            $table->string('decision_kind', 16)->nullable();
            $table->text('decision_basis')->nullable();
            $table->jsonb('decision_proof')->nullable();
            $table->string('decision_key', 191)->nullable();
            $table->char('decision_fingerprint', 64)->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->unique(['proposal_id', 'party_key'], 'contract_proposal_recipient_unique');
            $table->unique(['proposal_id', 'party_organization_id'], 'contract_proposal_recipient_org_unique');
            $table->index(['party_organization_id', 'proposal_id']);
        });
        DB::unprepared(<<<'SQL'
INSERT INTO contract_builder_proposal_recipients
 (proposal_id, party_key, party_name, party_organization_id, status, decided_by, decision_organization_id,
  decision_reason, decision_kind, decision_basis, decision_proof, decision_key, decision_fingerprint, decided_at)
SELECT p.id, COALESCE(party->>'party_key', party->>'side'), COALESCE(party->>'name', 'Участник договора'),
       (party->>'linked_organization_id')::bigint, p.status, p.decided_by, p.decision_organization_id,
       p.decision_reason, p.decision_kind, p.decision_basis, p.decision_proof, p.decision_key, p.decision_fingerprint, p.decided_at
FROM contract_builder_proposals p, jsonb_array_elements(p.content->'parties') party
WHERE COALESCE((party->>'linked_organization_id')::bigint, 0) <> p.author_organization_id;
ALTER TABLE contract_builder_proposal_recipients ADD CONSTRAINT contract_proposal_recipient_shape CHECK (
 (party_key IN ('first', 'second') OR party_key ~ '^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$')
 AND length(trim(party_name)) > 0
 AND ((status = 'pending' AND decided_by IS NULL AND decided_at IS NULL)
 OR (status IN ('accepted','rejected') AND decided_by IS NOT NULL AND decided_at IS NOT NULL
     AND decision_key IS NOT NULL AND decision_fingerprint IS NOT NULL
     AND ((decision_kind = 'connected' AND party_organization_id IS NOT NULL
           AND decision_organization_id = party_organization_id AND decision_proof IS NULL)
      OR (decision_kind = 'external' AND party_organization_id IS NULL AND decision_organization_id IS NOT NULL
          AND decision_basis IS NOT NULL AND length(trim(decision_basis)) > 0
          AND decision_proof IS NOT NULL AND jsonb_typeof(decision_proof) = 'object'))))
);
CREATE FUNCTION guard_contract_proposal_recipient() RETURNS trigger AS $$
DECLARE frozen jsonb; author_id bigint;
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'contract_proposal_recipient_immutable'; END IF;
 IF TG_OP = 'INSERT' THEN
   SELECT content, author_organization_id INTO frozen, author_id FROM contract_builder_proposals WHERE id = NEW.proposal_id AND status = 'pending';
   IF NOT EXISTS (SELECT 1 FROM jsonb_array_elements(frozen->'parties') p
       WHERE COALESCE(p->>'party_key',p->>'side') = NEW.party_key
        AND (p->>'linked_organization_id')::bigint IS NOT DISTINCT FROM NEW.party_organization_id
        AND COALESCE(NEW.party_organization_id,0) <> author_id)
      OR NEW.status <> 'pending' THEN RAISE EXCEPTION 'contract_proposal_recipient_invalid'; END IF;
 ELSE
   IF OLD.status <> 'pending' OR NEW.status NOT IN ('accepted','rejected')
      OR (to_jsonb(OLD) - ARRAY['status','decided_by','decision_organization_id','decision_reason','decision_kind','decision_basis','decision_proof','decision_key','decision_fingerprint','decided_at'])
        IS DISTINCT FROM
         (to_jsonb(NEW) - ARRAY['status','decided_by','decision_organization_id','decision_reason','decision_kind','decision_basis','decision_proof','decision_key','decision_fingerprint','decided_at'])
      THEN RAISE EXCEPTION 'contract_proposal_recipient_immutable'; END IF;
   SELECT author_organization_id INTO author_id FROM contract_builder_proposals WHERE id = NEW.proposal_id AND status = 'pending';
   IF author_id IS NULL OR (NEW.decision_kind = 'external' AND NEW.decision_organization_id <> author_id)
      THEN RAISE EXCEPTION 'contract_proposal_recipient_invalid'; END IF;
 END IF;
 RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_proposal_recipient_guard BEFORE INSERT OR UPDATE OR DELETE ON contract_builder_proposal_recipients
FOR EACH ROW EXECUTE FUNCTION guard_contract_proposal_recipient();
ALTER TABLE contract_builder_proposals DROP CONSTRAINT contract_proposal_shape;
ALTER TABLE contract_builder_proposals ADD CONSTRAINT contract_proposal_shape CHECK (
 draft_version > 0 AND lock_version > 0 AND jsonb_typeof(content) = 'object'
 AND (recipient_organization_id IS NULL OR recipient_organization_id <> author_organization_id)
 AND ((status = 'pending' AND decided_by IS NULL AND decided_at IS NULL AND accepted_revision_id IS NULL)
 OR (status IN ('accepted','rejected') AND decided_by IS NOT NULL AND decided_at IS NOT NULL
     AND decision_key IS NOT NULL AND decision_fingerprint IS NOT NULL
     AND ((decision_kind = 'connected' AND decision_organization_id IS NOT NULL AND decision_organization_id <> author_organization_id AND decision_proof IS NULL)
      OR (decision_kind = 'external' AND decision_organization_id = author_organization_id
          AND decision_basis IS NOT NULL AND length(trim(decision_basis)) > 0 AND decision_proof IS NOT NULL AND jsonb_typeof(decision_proof) = 'object'))
     AND ((status = 'accepted' AND accepted_revision_id IS NOT NULL) OR (status = 'rejected' AND accepted_revision_id IS NULL))))
);
CREATE OR REPLACE FUNCTION guard_contract_builder_proposal() RETURNS trigger AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'contract_builder_proposal_immutable'; END IF;
 IF OLD.status <> 'pending' OR NEW.status NOT IN ('pending','accepted','rejected') OR NEW.lock_version <> OLD.lock_version + 1
    OR (to_jsonb(OLD) - ARRAY['status','decided_by','decision_organization_id','decision_reason','decision_kind','decision_basis','decision_proof','decision_key','decision_fingerprint','decided_at','accepted_revision_id','lock_version'])
      IS DISTINCT FROM
       (to_jsonb(NEW) - ARRAY['status','decided_by','decision_organization_id','decision_reason','decision_kind','decision_basis','decision_proof','decision_key','decision_fingerprint','decided_at','accepted_revision_id','lock_version'])
    THEN RAISE EXCEPTION 'contract_builder_proposal_immutable'; END IF;
 IF NEW.status = 'accepted' AND (NOT EXISTS (SELECT 1 FROM contract_builder_proposal_recipients WHERE proposal_id = NEW.id)
     OR EXISTS (SELECT 1 FROM contract_builder_proposal_recipients WHERE proposal_id = NEW.id AND status <> 'accepted')
     OR (SELECT count(*) FROM contract_builder_proposal_recipients WHERE proposal_id = NEW.id) <>
        (SELECT count(*) FROM jsonb_array_elements(NEW.content->'parties') p WHERE COALESCE((p->>'linked_organization_id')::bigint,0) <> NEW.author_organization_id))
    THEN RAISE EXCEPTION 'contract_proposal_decisions_incomplete'; END IF;
 IF NEW.status = 'rejected' AND NOT EXISTS (SELECT 1 FROM contract_builder_proposal_recipients WHERE proposal_id = NEW.id AND status = 'rejected')
    THEN RAISE EXCEPTION 'contract_proposal_decisions_incomplete'; END IF;
 IF NEW.status <> 'pending' AND NOT EXISTS (SELECT 1 FROM contract_builder_proposal_recipients r WHERE r.proposal_id = NEW.id
     AND r.status = NEW.status AND r.decision_key = NEW.decision_key AND r.decision_fingerprint = NEW.decision_fingerprint
     AND r.decided_by = NEW.decided_by AND r.decision_organization_id = NEW.decision_organization_id)
    THEN RAISE EXCEPTION 'contract_proposal_final_decision_invalid'; END IF;
 RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        if (DB::table('contract_builder_proposal_recipients')->select('proposal_id')->groupBy('proposal_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('contract_proposal_recipient_rollback_requires_data_migration');
        }
        $original = file_get_contents(__DIR__.'/2026_09_19_121000_create_contract_builder_proposals.php');
        if ($original === false || preg_match("/DB::unprepared\(<<<'SQL'\R([\s\S]+?)\RSQL\);/", $original, $match) !== 1) {
            throw new RuntimeException('contract_proposal_original_guard_missing');
        }
        DB::unprepared('DROP TRIGGER contract_builder_proposal_guard ON contract_builder_proposals; DROP FUNCTION guard_contract_builder_proposal(); ALTER TABLE contract_builder_proposals DROP CONSTRAINT contract_proposal_shape;');
        DB::unprepared($match[1]);
        Schema::drop('contract_builder_proposal_recipients');
        DB::unprepared('DROP FUNCTION guard_contract_proposal_recipient();');
    }
};
