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
        Schema::create('contractor_scorecard_review_coverage', function (Blueprint $table): void {
            $table->string('source_code', 64)->primary();
            $table->timestampTz('coverage_started_at', 6);
            $table->char('evidence_hash', 64);
        });
        Schema::create('contractor_scorecard_review_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('review_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->timestampTz('observed_at', 6);
            $table->boolean('is_deleted');
            $table->jsonb('payload');
            $table->char('evidence_hash', 64)->unique();
            $table->index(
                ['organization_id', 'review_id', 'observed_at', 'id'],
                'contractor_review_event_timeline_idx',
            );
        });
        Schema::create('contractor_scorecard_review_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->char('source_hash', 64);
            $table->timestampTz('as_of', 6);
            $table->jsonb('scope_identity');
            $table->jsonb('filters');
            $table->unsignedBigInteger('row_count');
            $table->unsignedBigInteger('unknown_count');
            $table->timestampTz('generated_at', 6);
            $table->unique(
                ['organization_id', 'source_hash'],
                'contractor_review_snapshot_identity_unique',
            );
        });
        Schema::create('contractor_scorecard_review_snapshot_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->char('snapshot_id', 26);
            $table->unsignedBigInteger('review_id');
            $table->unsignedBigInteger('offer_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('contractor_organization_id');
            $table->unsignedBigInteger('contractor_profile_id');
            $table->unsignedBigInteger('category_id');
            $table->decimal('quality_score', 8, 4);
            $table->decimal('deadline_score', 8, 4);
            $table->decimal('communication_score', 8, 4);
            $table->decimal('safety_score', 8, 4)->nullable();
            $table->decimal('financial_discipline_score', 8, 4)->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('review_observed_at', 6);
            $table->char('review_evidence_hash', 64);
            $table->char('membership_evidence_hash', 64);
            $table->string('row_key', 128);
            $table->unique(
                ['organization_id', 'snapshot_id', 'review_id'],
                'contractor_review_snapshot_row_unique',
            );
        });
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION most_capture_contractor_scorecard_review_v1()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                source_row jsonb;
                observed_value timestamptz;
                payload_hash text;
            BEGIN
                source_row := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
                observed_value := clock_timestamp();
                payload_hash := encode(sha256(convert_to(
                    concat_ws('|', source_row->>'id', observed_value, TG_OP, source_row::text),
                    'UTF8'
                )), 'hex');
                INSERT INTO contractor_scorecard_review_events (
                    review_id, organization_id, project_id, observed_at,
                    is_deleted, payload, evidence_hash
                ) VALUES (
                    (source_row->>'id')::bigint,
                    (source_row->>'reviewer_organization_id')::bigint,
                    (source_row->>'project_id')::bigint,
                    observed_value,
                    TG_OP = 'DELETE',
                    source_row,
                    payload_hash
                );
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$
            SQL);
        DB::statement(
            'CREATE TRIGGER marketplace_reviews_scorecard_evidence '
            .'AFTER INSERT OR UPDATE OR DELETE ON marketplace_hiring_offer_reviews FOR EACH ROW '
            .'EXECUTE FUNCTION most_capture_contractor_scorecard_review_v1()',
        );
        DB::statement(<<<'SQL'
            INSERT INTO contractor_scorecard_review_events (
                review_id, organization_id, project_id, observed_at,
                is_deleted, payload, evidence_hash
            )
            SELECT
                source.id,
                source.reviewer_organization_id,
                source.project_id,
                captured.observed_at,
                FALSE,
                to_jsonb(source),
                encode(sha256(convert_to(
                    concat_ws('|', source.id, captured.observed_at, 'BACKFILL', to_jsonb(source)::text),
                    'UTF8'
                )), 'hex')
            FROM marketplace_hiring_offer_reviews source
            CROSS JOIN LATERAL (SELECT clock_timestamp() AS observed_at) captured
            SQL);
        DB::table('contractor_scorecard_review_coverage')->insert([
            'source_code' => 'marketplace_reviews',
            'coverage_started_at' => DB::raw('clock_timestamp()'),
            'evidence_hash' => hash('sha256', 'contractor-review-coverage:marketplace_reviews'),
        ]);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION most_prevent_contractor_review_evidence_mutation_v1()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'contractor_review_evidence_is_immutable';
            END;
            $$
            SQL);
        foreach ([
            'contractor_scorecard_review_coverage',
            'contractor_scorecard_review_events',
            'contractor_scorecard_review_snapshots',
            'contractor_scorecard_review_snapshot_rows',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION most_prevent_contractor_review_evidence_mutation_v1()',
            );
        }
    }

    public function down(): void
    {
        DB::statement(
            'DROP TRIGGER IF EXISTS marketplace_reviews_scorecard_evidence '
            .'ON marketplace_hiring_offer_reviews',
        );
        DB::statement('DROP FUNCTION IF EXISTS most_capture_contractor_scorecard_review_v1()');
        Schema::dropIfExists('contractor_scorecard_review_snapshot_rows');
        Schema::dropIfExists('contractor_scorecard_review_snapshots');
        Schema::dropIfExists('contractor_scorecard_review_events');
        Schema::dropIfExists('contractor_scorecard_review_coverage');
        DB::statement('DROP FUNCTION IF EXISTS most_prevent_contractor_review_evidence_mutation_v1()');
    }
};
