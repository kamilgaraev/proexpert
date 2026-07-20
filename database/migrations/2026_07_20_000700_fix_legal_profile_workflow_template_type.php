<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            DO $$
            DECLARE
                unresolved_count BIGINT;
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'legal_archive_document_type_profiles'
                      AND column_name = 'workflow_template_id'
                      AND data_type = 'uuid'
                ) THEN
                    EXECUTE 'SELECT count(*) FROM legal_archive_document_type_profiles WHERE workflow_template_id IS NOT NULL'
                    INTO unresolved_count;
                    IF unresolved_count > 0 THEN
                        RAISE EXCEPTION 'legal_profile_workflow_template_uuid_reconciliation_required:%', unresolved_count
                            USING HINT = 'Explicitly reconcile every legacy UUID to a tenant-owned current workflow template before retrying.';
                    END IF;
                    IF NOT EXISTS (
                        SELECT 1 FROM information_schema.columns
                        WHERE table_schema = current_schema()
                          AND table_name = 'legal_archive_document_type_profiles'
                          AND column_name = 'workflow_template_bigint_id'
                    ) THEN
                        ALTER TABLE legal_archive_document_type_profiles
                            ADD COLUMN workflow_template_bigint_id BIGINT NULL;
                    END IF;
                END IF;
            END $$
            SQL);

        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'legal_archive_document_type_profiles'
                      AND column_name = 'workflow_template_id'
                      AND data_type = 'uuid'
                ) AND NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'legal_archive_document_type_profiles'
                      AND column_name = 'workflow_template_legacy_uuid'
                ) THEN
                    ALTER TABLE legal_archive_document_type_profiles
                        RENAME COLUMN workflow_template_id TO workflow_template_legacy_uuid;
                END IF;
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'legal_archive_document_type_profiles'
                      AND column_name = 'workflow_template_bigint_id'
                ) AND NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'legal_archive_document_type_profiles'
                      AND column_name = 'workflow_template_id'
                ) THEN
                    ALTER TABLE legal_archive_document_type_profiles
                        RENAME COLUMN workflow_template_bigint_id TO workflow_template_id;
                END IF;
            END $$
            SQL);

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_templates_org_code_id_unique ON legal_workflow_templates (organization_id, code, id)');
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_workflow_template_heads_org_template_unique ON legal_workflow_template_heads (organization_id, template_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_doc_profiles_workflow_template_idx ON legal_archive_document_type_profiles (organization_id, workflow_template_id) WHERE workflow_template_id IS NOT NULL');

        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'legal_workflow_template_heads_current_fk') THEN
                    ALTER TABLE legal_workflow_template_heads
                        ADD CONSTRAINT legal_workflow_template_heads_current_fk
                        FOREIGN KEY (organization_id, code, template_id)
                        REFERENCES legal_workflow_templates (organization_id, code, id)
                        ON DELETE RESTRICT NOT VALID;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'legal_doc_profiles_workflow_template_head_fk') THEN
                    ALTER TABLE legal_archive_document_type_profiles
                        ADD CONSTRAINT legal_doc_profiles_workflow_template_head_fk
                        FOREIGN KEY (organization_id, workflow_template_id)
                        REFERENCES legal_workflow_template_heads (organization_id, template_id)
                        ON DELETE RESTRICT NOT VALID;
                END IF;
            END $$
            SQL);
        DB::statement('ALTER TABLE legal_workflow_template_heads VALIDATE CONSTRAINT legal_workflow_template_heads_current_fk');
        DB::statement('ALTER TABLE legal_archive_document_type_profiles VALIDATE CONSTRAINT legal_doc_profiles_workflow_template_head_fk');

        DB::statement(<<<'SQL'
            DO $$
            DECLARE
                unresolved_count BIGINT;
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'legal_archive_document_type_profiles'
                      AND column_name = 'workflow_template_legacy_uuid'
                ) THEN
                    EXECUTE 'SELECT count(*) FROM legal_archive_document_type_profiles WHERE workflow_template_legacy_uuid IS NOT NULL'
                    INTO unresolved_count;
                    IF unresolved_count > 0 THEN
                        RAISE EXCEPTION 'legal_profile_workflow_template_uuid_reconciliation_required:%', unresolved_count;
                    END IF;
                    ALTER TABLE legal_archive_document_type_profiles DROP COLUMN workflow_template_legacy_uuid;
                END IF;
            END $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE legal_archive_document_type_profiles DROP CONSTRAINT IF EXISTS legal_doc_profiles_workflow_template_head_fk');
        DB::statement('ALTER TABLE legal_workflow_template_heads DROP CONSTRAINT IF EXISTS legal_workflow_template_heads_current_fk');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS legal_doc_profiles_workflow_template_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS legal_workflow_template_heads_org_template_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS legal_workflow_templates_org_code_id_unique');
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'legal_archive_document_type_profiles'
                      AND column_name = 'workflow_template_legacy_uuid'
                ) THEN
                    ALTER TABLE legal_archive_document_type_profiles
                        ADD COLUMN workflow_template_legacy_uuid UUID NULL;
                END IF;
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'legal_archive_document_type_profiles'
                      AND column_name = 'workflow_template_id'
                      AND data_type = 'bigint'
                ) THEN
                    ALTER TABLE legal_archive_document_type_profiles DROP COLUMN workflow_template_id;
                END IF;
                ALTER TABLE legal_archive_document_type_profiles
                    RENAME COLUMN workflow_template_legacy_uuid TO workflow_template_id;
            END $$
            SQL);
    }
};
