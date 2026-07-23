<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->constraints() as $name => [$table, $definition]) {
            $actual = DB::selectOne(
                <<<'SQL'
SELECT table_class.relname AS table_name,
       pg_get_constraintdef(c.oid, true) AS definition,
       c.convalidated::integer AS validated,
       c.condeferrable::integer AS deferrable,
       c.condeferred::integer AS deferred
FROM pg_constraint c
JOIN pg_class table_class ON table_class.oid = c.conrelid
JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
WHERE namespace.nspname = current_schema() AND c.conname = ?
SQL,
                [$name],
            );
            if ($actual !== null) {
                if (
                    $actual->table_name !== $table
                    || $this->normalize($actual->definition) !== $this->normalize(str_replace(' NOT VALID', '', $definition))
                    || (bool) $actual->deferrable
                    || (bool) $actual->deferred
                ) {
                    throw new RuntimeException("legal_document_access_constraint_descriptor_mismatch:{$name}");
                }

                continue;
            }
            DB::unprepared("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }
        $this->installImmutableGuards();
        $this->assertOwnerPrincipalTriggerDescriptor();
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_access_migrations_are_forward_only');
    }

    private function constraints(): array
    {
        $abilities = <<<'SQL'
CHECK (jsonb_typeof(abilities) = 'array' AND jsonb_array_length(abilities) > 0 AND abilities <@ '["view","comment","approve","sign","download","manage"]'::jsonb AND (NOT abilities ? 'manage' OR subject_kind = 'internal_user'))
SQL;
        $anchor = <<<'SQL'
CHECK (anchor IS NULL OR (jsonb_typeof(anchor) = 'object' AND anchor ?& ARRAY['type','x','y','width','height'] AND anchor - ARRAY['type','x','y','width','height'] = '{}'::jsonb AND anchor->>'type' = 'rect' AND jsonb_typeof(anchor->'x') = 'number' AND jsonb_typeof(anchor->'y') = 'number' AND jsonb_typeof(anchor->'width') = 'number' AND jsonb_typeof(anchor->'height') = 'number' AND (anchor->>'x')::numeric >= 0 AND (anchor->>'y')::numeric >= 0 AND (anchor->>'width')::numeric > 0 AND (anchor->>'height')::numeric > 0 AND (anchor->>'x')::numeric + (anchor->>'width')::numeric <= 1 AND (anchor->>'y')::numeric + (anchor->>'height')::numeric <= 1))
SQL;

        return [
            'legal_documents_source_type_check' => ['legal_archive_documents', "CHECK (source_type IS NULL OR source_type IN ('project','contract','supplementary_agreement','performance_act','purchase_order','payment_document','commercial_proposal')) NOT VALID"],
            'legal_document_party_snapshot_sets_version_fk' => ['legal_document_party_snapshot_sets', 'FOREIGN KEY (document_version_id, document_id, organization_id) REFERENCES legal_archive_document_versions (id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_party_snapshot_sets_captured_by_fk' => ['legal_document_party_snapshot_sets', 'FOREIGN KEY (captured_by_user_id) REFERENCES users (id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_parties_document_fk' => ['legal_document_parties', 'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents (id, organization_id) ON DELETE CASCADE NOT VALID'],
            'legal_document_parties_snapshot_set_fk' => ['legal_document_parties', 'FOREIGN KEY (snapshot_set_id, document_version_id, document_id, organization_id) REFERENCES legal_document_party_snapshot_sets (id, document_version_id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_parties_organization_fk' => ['legal_document_parties', 'FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT VALID'],
            'legal_document_parties_party_organization_fk' => ['legal_document_parties', 'FOREIGN KEY (party_organization_id) REFERENCES organizations (id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_parties_counterparty_fk' => ['legal_document_parties', 'FOREIGN KEY (counterparty_id, organization_id) REFERENCES counterparties (id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_parties_role_check' => ['legal_document_parties', "CHECK (party_role IN ('customer','contractor','supplier','buyer','seller','lessor','lessee','licensor','licensee','agent','principal','other')) NOT VALID"],
            'legal_document_parties_source_check' => ['legal_document_parties', "CHECK (jsonb_typeof(snapshot) = 'object' AND ((data_source = 'organization' AND party_organization_id IS NOT NULL AND counterparty_id IS NULL) OR (data_source = 'counterparty' AND counterparty_id IS NOT NULL AND party_organization_id IS NULL) OR (data_source IN ('manual','import') AND party_organization_id IS NULL AND counterparty_id IS NULL))) NOT VALID"],
            'legal_document_access_document_fk' => ['legal_document_access_grants', 'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents (id, organization_id) ON DELETE CASCADE NOT VALID'],
            'legal_document_access_owner_organization_fk' => ['legal_document_access_grants', 'FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT VALID'],
            'legal_document_access_subject_organization_fk' => ['legal_document_access_grants', 'FOREIGN KEY (subject_organization_id) REFERENCES organizations (id) ON DELETE CASCADE NOT VALID'],
            'legal_document_access_subject_membership_fk' => ['legal_document_access_grants', 'FOREIGN KEY (subject_user_id, subject_organization_id) REFERENCES organization_user (user_id, organization_id) ON DELETE CASCADE NOT VALID'],
            'legal_document_access_subject_check' => ['legal_document_access_grants', "CHECK ((subject_kind = 'internal_user' AND subject_organization_id = organization_id AND subject_user_id IS NOT NULL AND subject_role_slug IS NULL) OR (subject_kind = 'internal_role' AND subject_organization_id = organization_id AND subject_user_id IS NULL AND NULLIF(btrim(subject_role_slug), '') IS NOT NULL) OR (subject_kind = 'external_org' AND subject_organization_id <> organization_id AND subject_user_id IS NULL AND subject_role_slug IS NULL) OR (subject_kind = 'external_user' AND subject_organization_id <> organization_id AND subject_user_id IS NOT NULL AND subject_role_slug IS NULL)) NOT VALID"],
            'legal_document_access_granted_by_fk' => ['legal_document_access_grants', 'FOREIGN KEY (granted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_access_revoked_by_fk' => ['legal_document_access_grants', 'FOREIGN KEY (revoked_by_user_id) REFERENCES users (id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_access_abilities_check' => ['legal_document_access_grants', $abilities.' NOT VALID'],
            'legal_document_access_revocation_check' => ['legal_document_access_grants', "CHECK ((revoked_at IS NULL AND revoked_by_user_id IS NULL AND revocation_reason IS NULL) OR (revoked_at IS NOT NULL AND revoked_by_user_id IS NOT NULL AND NULLIF(btrim(revocation_reason), '') IS NOT NULL)) NOT VALID"],
            'legal_document_access_expiry_check' => ['legal_document_access_grants', 'CHECK (expires_at IS NULL OR expires_at > created_at) NOT VALID'],
            'legal_document_comments_document_fk' => ['legal_document_comments', 'FOREIGN KEY (document_id, organization_id) REFERENCES legal_archive_documents (id, organization_id) ON DELETE CASCADE NOT VALID'],
            'legal_document_comments_version_fk' => ['legal_document_comments', 'FOREIGN KEY (document_version_id, document_id, organization_id) REFERENCES legal_archive_document_versions (id, document_id, organization_id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_comments_author_fk' => ['legal_document_comments', 'FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_comments_resolved_by_fk' => ['legal_document_comments', 'FOREIGN KEY (resolved_by_user_id) REFERENCES users (id) ON DELETE RESTRICT NOT VALID'],
            'legal_document_comments_body_check' => ['legal_document_comments', "CHECK (NULLIF(btrim(body), '') IS NOT NULL AND char_length(body) <= 10000) NOT VALID"],
            'legal_document_comments_page_check' => ['legal_document_comments', 'CHECK (page_number IS NULL OR page_number >= 1) NOT VALID'],
            'legal_document_comments_anchor_check' => ['legal_document_comments', $anchor.' NOT VALID'],
            'legal_document_comments_visibility_check' => ['legal_document_comments', "CHECK (visibility IN ('internal','all_parties','author_and_responsible')) NOT VALID"],
            'legal_document_comments_resolution_check' => ['legal_document_comments', "CHECK ((status = 'open' AND resolution IS NULL AND resolved_by_user_id IS NULL AND resolved_at IS NULL AND resolution_idempotency_key IS NULL AND resolution_request_hash IS NULL) OR (status = 'resolved' AND resolved_by_user_id IS NOT NULL AND resolved_at IS NOT NULL AND (resolution_idempotency_key IS NULL) = (resolution_request_hash IS NULL))) NOT VALID"],
            'legal_document_comments_hash_check' => ['legal_document_comments', "CHECK (((idempotency_key IS NULL AND request_hash IS NULL) OR (idempotency_key IS NOT NULL AND request_hash ~ '^[a-f0-9]{64}$')) AND (resolution_request_hash IS NULL OR resolution_request_hash ~ '^[a-f0-9]{64}$')) NOT VALID"],
        ];
    }

    private function installImmutableGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION legal_document_party_snapshot_set_immutable_guard() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'legal_document_party_snapshot_set_is_immutable' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS legal_document_party_snapshot_set_immutable_guard ON legal_document_party_snapshot_sets;
CREATE TRIGGER legal_document_party_snapshot_set_immutable_guard
BEFORE UPDATE OR DELETE ON legal_document_party_snapshot_sets
FOR EACH ROW EXECUTE FUNCTION legal_document_party_snapshot_set_immutable_guard();

CREATE OR REPLACE FUNCTION legal_document_party_immutable_guard() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'legal_document_party_is_immutable' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS legal_document_party_immutable_guard ON legal_document_parties;
CREATE TRIGGER legal_document_party_immutable_guard
BEFORE UPDATE OR DELETE ON legal_document_parties
FOR EACH ROW EXECUTE FUNCTION legal_document_party_immutable_guard();

CREATE OR REPLACE FUNCTION legal_document_owner_principal_guard() RETURNS trigger AS $$
BEGIN
    IF OLD.created_by_user_id IS DISTINCT FROM NEW.created_by_user_id
       OR OLD.owner_user_id IS DISTINCT FROM NEW.owner_user_id THEN
        RAISE EXCEPTION 'legal_document_owner_principal_is_immutable' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS legal_document_owner_principal_guard ON legal_archive_documents;
CREATE TRIGGER legal_document_owner_principal_guard
BEFORE UPDATE OF created_by_user_id, owner_user_id ON legal_archive_documents
FOR EACH ROW EXECUTE FUNCTION legal_document_owner_principal_guard();

CREATE OR REPLACE FUNCTION legal_document_access_grant_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'legal_document_access_grant_delete_forbidden' USING ERRCODE = '55000';
    END IF;
    IF OLD.revoked_at IS NOT NULL OR NEW.revoked_at IS NULL
       OR (OLD.organization_id, OLD.document_id, OLD.subject_kind, OLD.subject_organization_id,
           OLD.subject_user_id, OLD.subject_role_slug,
           OLD.abilities, OLD.granted_by_user_id, OLD.expires_at, OLD.created_at)
          IS DISTINCT FROM
          (NEW.organization_id, NEW.document_id, NEW.subject_kind, NEW.subject_organization_id,
           NEW.subject_user_id, NEW.subject_role_slug,
           NEW.abilities, NEW.granted_by_user_id, NEW.expires_at, NEW.created_at) THEN
        RAISE EXCEPTION 'legal_document_access_grant_is_immutable' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS legal_document_access_grant_guard ON legal_document_access_grants;
CREATE TRIGGER legal_document_access_grant_guard
BEFORE UPDATE OR DELETE ON legal_document_access_grants
FOR EACH ROW EXECUTE FUNCTION legal_document_access_grant_guard();

CREATE OR REPLACE FUNCTION legal_document_comment_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'legal_document_comment_delete_forbidden' USING ERRCODE = '55000';
    END IF;
    IF OLD.status <> 'open' OR NEW.status <> 'resolved'
       OR (OLD.organization_id, OLD.document_id, OLD.document_version_id, OLD.author_user_id,
           OLD.body, OLD.page_number, OLD.anchor, OLD.visibility, OLD.is_blocking,
           OLD.idempotency_key, OLD.request_hash, OLD.created_at)
          IS DISTINCT FROM
          (NEW.organization_id, NEW.document_id, NEW.document_version_id, NEW.author_user_id,
           NEW.body, NEW.page_number, NEW.anchor, NEW.visibility, NEW.is_blocking,
           NEW.idempotency_key, NEW.request_hash, NEW.created_at) THEN
        RAISE EXCEPTION 'legal_document_comment_is_immutable' USING ERRCODE = '55000';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS legal_document_comment_guard ON legal_document_comments;
CREATE TRIGGER legal_document_comment_guard
BEFORE UPDATE OR DELETE ON legal_document_comments
FOR EACH ROW EXECUTE FUNCTION legal_document_comment_guard();
SQL);
    }

    private function normalize(mixed $definition): string
    {
        $normalized = strtolower((string) $definition);
        $normalized = str_replace('not valid', '', $normalized);
        $normalized = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $normalized);
        $normalized = (string) preg_replace('/["\s()]+/', '', $normalized);
        $normalized = str_replace(['=anyarray[', ']'], ['in', ''], $normalized);

        return $normalized;
    }

    private function assertOwnerPrincipalTriggerDescriptor(): void
    {
        $definition = DB::selectOne(<<<'SQL'
SELECT pg_get_triggerdef(trigger.oid, true) AS definition
FROM pg_trigger AS trigger
JOIN pg_class AS owner ON owner.oid = trigger.tgrelid
JOIN pg_namespace AS namespace ON namespace.oid = owner.relnamespace
WHERE namespace.nspname = current_schema()
  AND owner.relname = 'legal_archive_documents'
  AND trigger.tgname = 'legal_document_owner_principal_guard'
  AND trigger.tgisinternal = false
SQL);
        $expected = 'CREATE TRIGGER legal_document_owner_principal_guard BEFORE UPDATE OF created_by_user_id, owner_user_id ON legal_archive_documents FOR EACH ROW EXECUTE FUNCTION legal_document_owner_principal_guard()';
        if ($definition === null || $this->normalize($definition->definition) !== $this->normalize($expected)) {
            throw new RuntimeException('legal_document_access_trigger_descriptor_mismatch:legal_document_owner_principal_guard');
        }
    }
};
