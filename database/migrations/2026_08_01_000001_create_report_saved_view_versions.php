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
        Schema::table('report_saved_views', function (Blueprint $table): void {
            $table->unique(
                ['id', 'organization_id', 'owner_id'],
                'report_saved_views_identity_unique',
            );
        });

        Schema::create('report_saved_view_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('saved_view_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedInteger('revision');
            $table->string('report_code', 64);
            $table->string('contract_version', 32);
            $table->unsignedSmallInteger('presentation_schema_version');
            $table->jsonb('content_json');
            $table->char('content_hash', 64);
            $table->char('report_definition_hash', 64);
            $table->timestampTz('created_at', 6);

            $table->foreign(
                ['saved_view_id', 'organization_id', 'owner_id'],
                'report_saved_view_versions_head_identity_foreign',
            )
                ->references(['id', 'organization_id', 'owner_id'])
                ->on('report_saved_views')
                ->restrictOnDelete();
            $table->unique(
                ['saved_view_id', 'revision'],
                'report_saved_view_versions_revision_unique',
            );
            $table->index(
                ['saved_view_id', 'content_hash'],
                'report_saved_view_versions_content_index',
            );
            $table->index(
                ['organization_id', 'saved_view_id', 'revision'],
                'report_saved_view_versions_scope_revision_index',
            );
        });

        DB::statement('ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_revision_check CHECK (revision >= 1)');
        DB::statement('ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_schema_check CHECK (presentation_schema_version = 1)');
        DB::statement("ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_report_code_check CHECK (report_code ~ '^[a-z][a-z0-9_]{2,63}$')");
        DB::statement("ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_contract_version_check CHECK (btrim(contract_version) <> '')");
        DB::statement("ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_content_hash_check CHECK (content_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_definition_hash_check CHECK (report_definition_hash ~ '^[a-f0-9]{64}$')");
        DB::statement(<<<'SQL'
            ALTER TABLE report_saved_view_versions
                ADD CONSTRAINT report_saved_view_versions_content_shape_check
                CHECK (
                    (jsonb_typeof(content_json) = 'object') IS TRUE
                    AND (content_json ?& ARRAY['schema_version', 'report_code', 'contract_version', 'name', 'visibility', 'filters', 'comparison', 'sort', 'columns']) IS TRUE
                    AND ((content_json - ARRAY['schema_version', 'report_code', 'contract_version', 'name', 'visibility', 'filters', 'comparison', 'sort', 'columns']) = '{}'::jsonb) IS TRUE
                    AND (jsonb_typeof(content_json -> 'name') = 'string') IS TRUE
                    AND (btrim(content_json ->> 'name') <> '') IS TRUE
                    AND (char_length(content_json ->> 'name') <= 120) IS TRUE
                    AND (jsonb_typeof(content_json -> 'visibility') = 'string') IS TRUE
                    AND ((content_json ->> 'visibility') IN ('private', 'organization')) IS TRUE
                    AND (jsonb_typeof(content_json -> 'filters') IN ('array', 'object')) IS TRUE
                    AND (jsonb_typeof(content_json -> 'comparison') IN ('array', 'object')) IS TRUE
                    AND (jsonb_typeof(content_json -> 'sort') = 'object') IS TRUE
                    AND ((content_json -> 'sort') ?& ARRAY['field', 'direction']) IS TRUE
                    AND (((content_json -> 'sort') - ARRAY['field', 'direction']) = '{}'::jsonb) IS TRUE
                    AND (jsonb_typeof(content_json -> 'sort' -> 'field') = 'string') IS TRUE
                    AND (jsonb_typeof(content_json -> 'sort' -> 'direction') = 'string') IS TRUE
                    AND ((content_json -> 'sort' ->> 'direction') IN ('asc', 'desc')) IS TRUE
                    AND (jsonb_typeof(content_json -> 'columns') = 'array') IS TRUE
                    AND (jsonb_array_length(content_json -> 'columns') >= 1) IS TRUE
                )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE report_saved_view_versions
                ADD CONSTRAINT report_saved_view_versions_content_schema_binding_check
                CHECK (
                    (content_json ? 'schema_version') IS TRUE
                    AND (jsonb_typeof(content_json -> 'schema_version') = 'number') IS TRUE
                    AND ((content_json ->> 'schema_version') = presentation_schema_version::text) IS TRUE
                )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE report_saved_view_versions
                ADD CONSTRAINT report_saved_view_versions_content_code_binding_check
                CHECK (
                    (content_json ? 'report_code') IS TRUE
                    AND (jsonb_typeof(content_json -> 'report_code') = 'string') IS TRUE
                    AND ((content_json ->> 'report_code') = report_code) IS TRUE
                )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE report_saved_view_versions
                ADD CONSTRAINT report_saved_view_versions_content_contract_binding_check
                CHECK (
                    (content_json ? 'contract_version') IS TRUE
                    AND (jsonb_typeof(content_json -> 'contract_version') = 'string') IS TRUE
                    AND ((content_json ->> 'contract_version') = contract_version) IS TRUE
                )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_report_saved_view_version_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'report_saved_view_versions_are_immutable'
                    USING ERRCODE = '55000';
            END;
            $$;

            CREATE TRIGGER report_saved_view_versions_immutable_guard
                BEFORE UPDATE OR DELETE ON report_saved_view_versions
                FOR EACH ROW
                EXECUTE FUNCTION reject_report_saved_view_version_mutation();
            SQL);

        Schema::table('report_saved_views', function (Blueprint $table): void {
            $table->unsignedInteger('current_revision')->nullable();
            $table->index(
                ['id', 'current_revision'],
                'report_saved_views_current_revision_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE report_saved_views
                ADD CONSTRAINT report_saved_views_current_revision_foreign
                FOREIGN KEY (id, current_revision)
                REFERENCES report_saved_view_versions (saved_view_id, revision)
                ON DELETE RESTRICT
                DEFERRABLE INITIALLY DEFERRED
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE report_saved_views DROP CONSTRAINT IF EXISTS report_saved_views_current_revision_foreign',
        );

        Schema::table('report_saved_views', function (Blueprint $table): void {
            $table->dropIndex('report_saved_views_current_revision_index');
            $table->dropColumn('current_revision');
        });

        DB::statement(
            'DROP TRIGGER IF EXISTS report_saved_view_versions_immutable_guard ON report_saved_view_versions',
        );
        Schema::dropIfExists('report_saved_view_versions');
        DB::statement('DROP FUNCTION IF EXISTS reject_report_saved_view_version_mutation()');

        Schema::table('report_saved_views', function (Blueprint $table): void {
            $table->dropUnique('report_saved_views_identity_unique');
        });
    }
};
