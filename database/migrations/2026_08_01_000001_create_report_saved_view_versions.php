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
        DB::statement("ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_report_code_check CHECK ((report_code COLLATE \"C\") ~ '\\A[a-z][a-z0-9_]{2,63}\\Z')");
        DB::statement("ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_contract_version_check CHECK (btrim(contract_version, ' ' || chr(9) || chr(10) || chr(13) || chr(11)) <> '')");
        DB::statement("ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_content_hash_check CHECK ((content_hash COLLATE \"C\") ~ '\\A[a-f0-9]{64}\\Z')");
        DB::statement("ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_definition_hash_check CHECK ((report_definition_hash COLLATE \"C\") ~ '\\A[a-f0-9]{64}\\Z')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_saved_view_version_json_is_php_hydratable(
                value_json jsonb,
                current_depth integer DEFAULT 1
            )
            RETURNS boolean
            LANGUAGE plpgsql
            IMMUTABLE
            STRICT
            PARALLEL SAFE
            AS $$
            DECLARE
                child_value jsonb;
                value_type text;
            BEGIN
                IF current_depth < 1 OR current_depth > 512 THEN
                    RETURN false;
                END IF;

                value_type := jsonb_typeof(value_json);

                IF value_type IN ('null', 'string', 'boolean') THEN
                    RETURN true;
                END IF;

                IF value_type = 'number' THEN
                    RETURN abs((value_json #>> '{}')::numeric)
                        <= '1.7976931348623157e308'::numeric;
                END IF;

                IF value_type = 'array' THEN
                    FOR child_value IN
                        SELECT array_set.value
                        FROM jsonb_array_elements(value_json) AS array_set(value)
                    LOOP
                        IF report_saved_view_version_json_is_php_hydratable(
                            child_value,
                            current_depth + 1
                        ) IS NOT TRUE THEN
                            RETURN false;
                        END IF;
                    END LOOP;

                    RETURN true;
                END IF;

                IF value_type = 'object' THEN
                    FOR child_value IN
                        SELECT object_set.value
                        FROM jsonb_each(value_json) AS object_set(key, value)
                    LOOP
                        IF report_saved_view_version_json_is_php_hydratable(
                            child_value,
                            current_depth + 1
                        ) IS NOT TRUE THEN
                            RETURN false;
                        END IF;
                    END LOOP;

                    RETURN true;
                END IF;

                RETURN false;
            EXCEPTION
                WHEN numeric_value_out_of_range OR invalid_text_representation THEN
                    RETURN false;
            END;
            $$;
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_saved_view_version_columns_are_valid(columns_json jsonb)
            RETURNS boolean
            LANGUAGE plpgsql
            IMMUTABLE
            STRICT
            PARALLEL SAFE
            AS $$
            BEGIN
                IF jsonb_typeof(columns_json) <> 'array' THEN
                    RETURN false;
                END IF;

                IF jsonb_array_length(columns_json) < 1 THEN
                    RETURN false;
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM jsonb_array_elements(columns_json) AS columns_set(column_value)
                    WHERE jsonb_typeof(column_value) <> 'string'
                        OR (((column_value #>> '{}') COLLATE "C") ~ '\A[a-z][a-z0-9_]{0,63}\Z') IS NOT TRUE
                ) THEN
                    RETURN false;
                END IF;

                RETURN (
                    SELECT count(*) = count(DISTINCT (column_value #>> '{}') COLLATE "C")
                    FROM jsonb_array_elements(columns_json) AS columns_set(column_value)
                );
            END;
            $$;
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE report_saved_view_versions
                ADD CONSTRAINT report_saved_view_versions_content_shape_check
                CHECK (
                    (jsonb_typeof(content_json) = 'object') IS TRUE
                    AND report_saved_view_version_json_is_php_hydratable(content_json) IS TRUE
                    AND (content_json ?& ARRAY['schema_version', 'report_code', 'contract_version', 'name', 'visibility', 'filters', 'comparison', 'sort', 'columns']) IS TRUE
                    AND ((content_json - ARRAY['schema_version', 'report_code', 'contract_version', 'name', 'visibility', 'filters', 'comparison', 'sort', 'columns']) = '{}'::jsonb) IS TRUE
                    AND (jsonb_typeof(content_json -> 'name') = 'string') IS TRUE
                    AND (btrim(content_json ->> 'name', ' ' || chr(9) || chr(10) || chr(13) || chr(11)) <> '') IS TRUE
                    AND (char_length(content_json ->> 'name') <= 120) IS TRUE
                    AND (jsonb_typeof(content_json -> 'visibility') = 'string') IS TRUE
                    AND ((content_json ->> 'visibility') IN ('private', 'organization')) IS TRUE
                    AND (jsonb_typeof(content_json -> 'filters') IN ('array', 'object')) IS TRUE
                    AND (jsonb_typeof(content_json -> 'comparison') IN ('array', 'object')) IS TRUE
                    AND (jsonb_typeof(content_json -> 'sort') = 'object') IS TRUE
                    AND ((content_json -> 'sort') ?& ARRAY['field', 'direction']) IS TRUE
                    AND (((content_json -> 'sort') - ARRAY['field', 'direction']) = '{}'::jsonb) IS TRUE
                    AND (jsonb_typeof(content_json -> 'sort' -> 'field') = 'string') IS TRUE
                    AND (((content_json -> 'sort' ->> 'field') COLLATE "C") ~ '\A[a-z][a-z0-9_]{0,63}\Z') IS TRUE
                    AND (jsonb_typeof(content_json -> 'sort' -> 'direction') = 'string') IS TRUE
                    AND ((content_json -> 'sort' ->> 'direction') IN ('asc', 'desc')) IS TRUE
                    AND report_saved_view_version_columns_are_valid(content_json -> 'columns') IS TRUE
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
        DB::statement('DROP FUNCTION IF EXISTS report_saved_view_version_columns_are_valid(jsonb)');
        DB::statement(
            'DROP FUNCTION IF EXISTS report_saved_view_version_json_is_php_hydratable(jsonb, integer)',
        );

        Schema::table('report_saved_views', function (Blueprint $table): void {
            $table->dropUnique('report_saved_views_identity_unique');
        });
    }
};
