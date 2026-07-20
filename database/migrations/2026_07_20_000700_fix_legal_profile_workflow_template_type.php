<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'legal_archive_document_type_profiles' AND column_name = 'workflow_template_bigint_id')
                   AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'legal_archive_document_type_profiles' AND column_name = 'workflow_template_id' AND data_type = 'bigint') THEN
                    ALTER TABLE legal_archive_document_type_profiles ADD COLUMN workflow_template_bigint_id BIGINT NULL;
                END IF;
            END $$
            SQL);
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'legal_archive_document_type_profiles' AND column_name = 'workflow_template_bigint_id') THEN
                    UPDATE legal_archive_document_type_profiles AS profile
                    SET workflow_template_bigint_id = head.template_id
                    FROM legal_workflow_template_heads AS head
                    WHERE profile.workflow_template_bigint_id IS NULL
                      AND head.organization_id = profile.organization_id
                      AND head.code = profile.code;
                END IF;
            END $$
            SQL);
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'legal_archive_document_type_profiles' AND column_name = 'workflow_template_id' AND data_type = 'uuid')
                   AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'legal_archive_document_type_profiles' AND column_name = 'workflow_template_legacy_uuid') THEN
                    ALTER TABLE legal_archive_document_type_profiles RENAME COLUMN workflow_template_id TO workflow_template_legacy_uuid;
                END IF;
                IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'legal_archive_document_type_profiles' AND column_name = 'workflow_template_bigint_id')
                   AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'legal_archive_document_type_profiles' AND column_name = 'workflow_template_id') THEN
                    ALTER TABLE legal_archive_document_type_profiles RENAME COLUMN workflow_template_bigint_id TO workflow_template_id;
                END IF;
            END $$
            SQL);
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS legal_doc_profiles_workflow_template_idx ON legal_archive_document_type_profiles (organization_id, workflow_template_id) WHERE workflow_template_id IS NOT NULL');
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'legal_doc_profiles_workflow_template_fk') THEN
                    ALTER TABLE legal_archive_document_type_profiles ADD CONSTRAINT legal_doc_profiles_workflow_template_fk FOREIGN KEY (workflow_template_id) REFERENCES legal_workflow_templates(id) NOT VALID;
                END IF;
            END $$
            SQL);
        DB::statement('ALTER TABLE legal_archive_document_type_profiles VALIDATE CONSTRAINT legal_doc_profiles_workflow_template_fk');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE legal_archive_document_type_profiles DROP CONSTRAINT IF EXISTS legal_doc_profiles_workflow_template_fk');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS legal_doc_profiles_workflow_template_idx');
        DB::statement('ALTER TABLE legal_archive_document_type_profiles RENAME COLUMN workflow_template_id TO workflow_template_bigint_id');
        DB::statement('ALTER TABLE legal_archive_document_type_profiles RENAME COLUMN workflow_template_legacy_uuid TO workflow_template_id');
    }
};
