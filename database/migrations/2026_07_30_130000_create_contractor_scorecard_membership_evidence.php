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
        Schema::create('contractor_scorecard_membership_coverage', function (Blueprint $table): void {
            $table->string('subject_type', 32)->primary();
            $table->timestampTz('coverage_started_at', 6);
            $table->char('evidence_hash', 64);
        });
        Schema::create('contractor_scorecard_membership_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->timestampTz('observed_at', 6);
            $table->boolean('is_deleted');
            $table->jsonb('payload');
            $table->char('evidence_hash', 64)->unique();
            $table->index(
                ['subject_type', 'subject_id', 'observed_at', 'id'],
                'contractor_membership_event_timeline_idx',
            );
            $table->index(
                ['organization_id', 'subject_type', 'observed_at', 'id'],
                'contractor_membership_event_scope_idx',
            );
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION most_capture_contractor_scorecard_membership_v1()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                source_row jsonb;
                source_id bigint;
                source_organization_id bigint;
                subject_kind text;
                deleted_value boolean;
                observed_value timestamptz;
                payload_hash text;
            BEGIN
                source_row := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
                source_id := (source_row->>'id')::bigint;
                deleted_value := TG_OP = 'DELETE'
                    OR COALESCE((source_row->>'deleted_at')::timestamptz, NULL) IS NOT NULL;
                observed_value := clock_timestamp();
                subject_kind := CASE TG_TABLE_NAME
                    WHEN 'contractors' THEN 'contractor'
                    WHEN 'suppliers' THEN 'supplier'
                    WHEN 'marketplace_contractor_profiles' THEN 'profile'
                    WHEN 'marketplace_contractor_categories' THEN 'profile_category'
                    ELSE NULL
                END;
                source_organization_id := CASE
                    WHEN source_row ? 'organization_id' THEN (source_row->>'organization_id')::bigint
                    ELSE NULL
                END;
                payload_hash := encode(sha256(convert_to(
                    concat_ws('|', subject_kind, source_id, observed_value, deleted_value, source_row::text),
                    'UTF8'
                )), 'hex');
                INSERT INTO contractor_scorecard_membership_events (
                    subject_type, subject_id, organization_id, observed_at,
                    is_deleted, payload, evidence_hash
                ) VALUES (
                    subject_kind, source_id, source_organization_id, observed_value,
                    deleted_value, source_row, payload_hash
                );
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$
            SQL);
        $sources = [
            'contractors' => ['contractor', 'source.organization_id', 'source.deleted_at IS NOT NULL'],
            'suppliers' => ['supplier', 'source.organization_id', 'source.deleted_at IS NOT NULL'],
            'marketplace_contractor_profiles' => ['profile', 'source.organization_id', 'FALSE'],
            'marketplace_contractor_categories' => ['profile_category', 'NULL', 'FALSE'],
        ];
        foreach ($sources as $table => [$subjectType, $organizationExpression, $deletedExpression]) {
            DB::statement(
                "CREATE TRIGGER {$table}_contractor_membership_evidence "
                ."AFTER INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW "
                .'EXECUTE FUNCTION most_capture_contractor_scorecard_membership_v1()',
            );
            DB::statement(<<<SQL
                INSERT INTO contractor_scorecard_membership_events (
                    subject_type, subject_id, organization_id, observed_at,
                    is_deleted, payload, evidence_hash
                )
                SELECT
                    '{$subjectType}',
                    source.id,
                    {$organizationExpression},
                    captured.observed_at,
                    {$deletedExpression},
                    to_jsonb(source),
                    encode(sha256(convert_to(
                        concat_ws(
                            '|',
                            '{$subjectType}',
                            source.id,
                            captured.observed_at,
                            {$deletedExpression},
                            to_jsonb(source)::text
                        ),
                        'UTF8'
                    )), 'hex')
                FROM {$table} source
                CROSS JOIN LATERAL (SELECT clock_timestamp() AS observed_at) captured
                SQL);
        }
        foreach (['contractor', 'supplier', 'profile', 'profile_category'] as $subjectType) {
            DB::table('contractor_scorecard_membership_coverage')->insert([
                'subject_type' => $subjectType,
                'coverage_started_at' => DB::raw('clock_timestamp()'),
                'evidence_hash' => hash('sha256', 'contractor-membership-coverage:'.$subjectType),
            ]);
        }
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION most_prevent_contractor_membership_evidence_mutation_v1()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'contractor_membership_evidence_is_immutable';
            END;
            $$
            SQL);
        foreach ([
            'contractor_scorecard_membership_coverage',
            'contractor_scorecard_membership_events',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION most_prevent_contractor_membership_evidence_mutation_v1()',
            );
        }
    }

    public function down(): void
    {
        foreach ([
            'contractors',
            'suppliers',
            'marketplace_contractor_profiles',
            'marketplace_contractor_categories',
        ] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_contractor_membership_evidence ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS most_capture_contractor_scorecard_membership_v1()');
        Schema::dropIfExists('contractor_scorecard_membership_events');
        Schema::dropIfExists('contractor_scorecard_membership_coverage');
        DB::statement('DROP FUNCTION IF EXISTS most_prevent_contractor_membership_evidence_mutation_v1()');
    }
};
