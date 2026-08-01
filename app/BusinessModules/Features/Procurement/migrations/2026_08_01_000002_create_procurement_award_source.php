<?php

declare(strict_types=1);

use App\BusinessModules\Features\Procurement\Reporting\Award\DTO\ProcurementAwardPolicyDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_proposal_versions', function (Blueprint $table): void {
            $table->char('content_hash', 64)->nullable()->after('attachment_snapshot');
            $table->string('integrity_status', 32)->default('legacy_unverified')->after('content_hash');
            $table->index(['supplier_proposal_id', 'content_hash'], 'supplier_proposal_versions_content_hash_idx');
        });

        Schema::table('supplier_request_versions', function (Blueprint $table): void {
            $table->char('content_hash', 64)->nullable()->after('supplier_snapshot');
            $table->string('integrity_status', 32)->default('legacy_unverified')->after('content_hash');
            $table->index(['supplier_request_id', 'content_hash'], 'supplier_request_versions_content_hash_idx');
        });

        Schema::create('procurement_award_policy_versions', function (Blueprint $table): void {
            $table->uuid('policy_id');
            $table->unsignedInteger('version');
            $table->string('schema_version', 64);
            $table->jsonb('policy_payload');
            $table->char('policy_hash', 64);
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at', 6);
            $table->timestampTz('created_at', 6);

            $table->primary(['policy_id', 'version'], 'proc_award_policy_versions_pk');
            $table->unique(['policy_id', 'version', 'policy_hash'], 'proc_award_policy_pin_unique');
        });

        Schema::create('procurement_award_evidence_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $table->foreignId('supplier_request_id')->constrained('supplier_requests')->restrictOnDelete();
            $table->foreignId('supplier_request_version_id')->nullable()->constrained('supplier_request_versions')->restrictOnDelete();
            $table->char('supplier_request_version_hash', 64)->nullable();
            $table->foreignId('decision_id')->constrained('supplier_proposal_decisions')->restrictOnDelete();
            $table->unsignedInteger('decision_revision');
            $table->unsignedBigInteger('event_sequence');
            $table->string('event_type', 32);
            $table->timestampTz('occurred_at', 6);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('selected_status', 32);
            $table->uuid('policy_id');
            $table->unsignedInteger('policy_version');
            $table->char('policy_hash', 64);
            $table->char('selection_fingerprint', 64);
            $table->char('source_hash', 64);
            $table->char('manifest_hash', 64);
            $table->unsignedInteger('candidate_count');
            $table->unsignedInteger('comparable_count');
            $table->string('completeness', 32);
            $table->jsonb('quarantine_codes');
            $table->foreignId('selected_proposal_id')->constrained('supplier_proposals')->restrictOnDelete();
            $table->foreignId('selected_proposal_version_id')->nullable()->constrained('supplier_proposal_versions')->restrictOnDelete();
            $table->foreignId('cheapest_proposal_id')->nullable()->constrained('supplier_proposals')->restrictOnDelete();
            $table->foreignId('cheapest_proposal_version_id')->nullable()->constrained('supplier_proposal_versions')->restrictOnDelete();
            $table->unsignedInteger('selected_rank')->nullable();
            $table->unsignedInteger('cheapest_rank')->nullable();
            $table->boolean('reason_present');
            $table->unsignedInteger('reason_normalized_length');
            $table->char('reason_digest', 64)->nullable();
            $table->uuid('predecessor_event_id')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->timestampTz('created_at', 6);

            $table->foreign('predecessor_event_id', 'proc_award_event_predecessor_fk')
                ->references('id')
                ->on('procurement_award_evidence_events')
                ->restrictOnDelete();
            $table->foreign(['policy_id', 'policy_version', 'policy_hash'], 'proc_award_event_policy_pin_fk')
                ->references(['policy_id', 'version', 'policy_hash'])
                ->on('procurement_award_policy_versions')
                ->restrictOnDelete();
            $table->unique(
                ['decision_id', 'decision_revision', 'event_type'],
                'proc_award_event_revision_type_unique',
            );
            $table->unique(['decision_id', 'event_sequence'], 'proc_award_event_sequence_unique');
            $table->index(
                ['organization_id', 'project_id', 'event_type', 'occurred_at'],
                'proc_award_event_scope_type_time_idx',
            );
            $table->index(['purchase_request_id', 'supplier_request_id'], 'proc_award_event_request_idx');
        });

        Schema::create('procurement_award_evidence_candidates', function (Blueprint $table): void {
            $table->uuid('event_id');
            $table->unsignedInteger('ordinal');
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $table->foreignId('supplier_request_id')->constrained('supplier_requests')->restrictOnDelete();
            $table->foreignId('supplier_request_version_id')->nullable()->constrained('supplier_request_versions')->restrictOnDelete();
            $table->char('supplier_request_version_hash', 64)->nullable();
            $table->foreignId('proposal_id')->constrained('supplier_proposals')->restrictOnDelete();
            $table->foreignId('proposal_version_id')->nullable()->constrained('supplier_proposal_versions')->restrictOnDelete();
            $table->foreignId('supplier_party_id')->constrained('supplier_parties')->restrictOnDelete();
            $table->string('proposal_status', 50)->nullable();
            $table->date('proposal_valid_until')->nullable();
            $table->char('version_content_hash', 64)->nullable();
            $table->text('subtotal_amount')->nullable();
            $table->text('delivery_amount')->nullable();
            $table->text('vat_amount')->nullable();
            $table->text('total_amount')->nullable();
            $table->text('comparison_total')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('vat_mode', 32)->nullable();
            $table->text('vat_rate')->nullable();
            $table->date('delivery_due_date')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->jsonb('request_line_coverage');
            $table->boolean('comparable');
            $table->jsonb('exclusion_codes');
            $table->char('candidate_hash', 64);

            $table->primary(['event_id', 'ordinal'], 'proc_award_evidence_candidates_pk');
            $table->foreign('event_id', 'proc_award_candidate_event_fk')
                ->references('id')
                ->on('procurement_award_evidence_events')
                ->cascadeOnDelete();
            $table->unique(
                ['event_id', 'proposal_id'],
                'proc_award_candidate_proposal_unique',
            );
            $table->index(['proposal_id', 'proposal_version_id'], 'proc_award_candidate_proposal_idx');
        });

        $policy = ProcurementAwardPolicyDefinition::v1();
        DB::table('procurement_award_policy_versions')->insert([
            'policy_id' => $policy->policyId,
            'version' => $policy->version,
            'schema_version' => $policy->schemaVersion,
            'policy_payload' => json_encode($policy->canonicalPayload(), JSON_THROW_ON_ERROR),
            'policy_hash' => $policy->canonicalHash(),
            'published_by' => null,
            'published_at' => now('UTC'),
            'created_at' => now('UTC'),
        ]);

        if (DB::getDriverName() === 'pgsql') {
            $this->installPostgresGuards();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS proc_award_candidates_deferred_validate ON procurement_award_evidence_candidates');
            DB::statement('DROP TRIGGER IF EXISTS proc_award_events_deferred_validate ON procurement_award_evidence_events');
            DB::statement('DROP TRIGGER IF EXISTS proc_award_candidate_source_validate ON procurement_award_evidence_candidates');
            DB::statement('DROP TRIGGER IF EXISTS proc_award_candidates_append_only ON procurement_award_evidence_candidates');
            DB::statement('DROP TRIGGER IF EXISTS proc_award_events_append_only ON procurement_award_evidence_events');
            DB::statement('DROP TRIGGER IF EXISTS proc_award_policies_append_only ON procurement_award_policy_versions');
            DB::statement('DROP TRIGGER IF EXISTS supplier_proposal_versions_append_only ON supplier_proposal_versions');
            DB::statement('DROP TRIGGER IF EXISTS supplier_request_versions_append_only ON supplier_request_versions');
            DB::statement('DROP FUNCTION IF EXISTS procurement_award_deferred_validate()');
            DB::statement('DROP FUNCTION IF EXISTS procurement_award_candidate_source_validate()');
            DB::statement('DROP FUNCTION IF EXISTS procurement_award_hash_parts(VARIADIC text[])');
            DB::statement('ALTER TABLE supplier_proposal_versions DROP CONSTRAINT IF EXISTS supplier_proposal_versions_integrity_check');
            DB::statement('ALTER TABLE supplier_request_versions DROP CONSTRAINT IF EXISTS supplier_request_versions_integrity_check');
        }

        Schema::dropIfExists('procurement_award_evidence_candidates');
        Schema::dropIfExists('procurement_award_evidence_events');
        Schema::dropIfExists('procurement_award_policy_versions');

        Schema::table('supplier_request_versions', function (Blueprint $table): void {
            $table->dropIndex('supplier_request_versions_content_hash_idx');
            $table->dropColumn(['content_hash', 'integrity_status']);
        });
        Schema::table('supplier_proposal_versions', function (Blueprint $table): void {
            $table->dropIndex('supplier_proposal_versions_content_hash_idx');
            $table->dropColumn(['content_hash', 'integrity_status']);
        });
    }

    private function installPostgresGuards(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE supplier_proposal_versions
ADD CONSTRAINT supplier_proposal_versions_integrity_check
CHECK (
    integrity_status IN ('verified', 'legacy_unverified')
    AND (
        (integrity_status = 'verified' AND content_hash ~ '^[a-f0-9]{64}$')
        OR (integrity_status = 'legacy_unverified' AND content_hash IS NULL)
    )
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE supplier_request_versions
ADD CONSTRAINT supplier_request_versions_integrity_check
CHECK (
    integrity_status IN ('verified', 'legacy_unverified')
    AND (
        (integrity_status = 'verified' AND content_hash ~ '^[a-f0-9]{64}$')
        OR (integrity_status = 'legacy_unverified' AND content_hash IS NULL)
    )
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE procurement_award_policy_versions
ADD CONSTRAINT proc_award_policy_shape_check
CHECK (
    policy_id = '00000000-0000-4000-8000-000000000016'::uuid
    AND version = 1
    AND schema_version = 'procurement-award-policy.v1'
    AND policy_hash ~ '^[a-f0-9]{64}$'
    AND policy_payload->>'competition_mode' = 'supplier_request'
    AND policy_payload->>'currency_mode' = 'exact_only'
    AND (policy_payload->>'candidate_limit')::integer = 100
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE procurement_award_evidence_events
ADD CONSTRAINT proc_award_event_shape_check
CHECK (
    decision_revision > 0
    AND event_sequence > 0
    AND event_type IN ('comparison_captured', 'award_approved', 'award_rejected', 'award_committed', 'selection_superseded')
    AND selected_status IN ('selected', 'approval_required')
    AND source_hash ~ '^[a-f0-9]{64}$'
    AND manifest_hash ~ '^[a-f0-9]{64}$'
    AND selection_fingerprint ~ '^[a-f0-9]{64}$'
    AND policy_hash ~ '^[a-f0-9]{64}$'
    AND candidate_count > 0
    AND comparable_count <= candidate_count
    AND completeness IN ('complete', 'not_comparable', 'gap', 'legacy_unverified')
    AND jsonb_typeof(quarantine_codes) = 'array'
    AND (selected_rank IS NULL OR selected_rank > 0)
    AND (cheapest_rank IS NULL OR cheapest_rank > 0)
    AND reason_normalized_length >= 0
    AND (
        (reason_present AND reason_normalized_length > 0 AND reason_digest ~ '^[a-f0-9]{64}$')
        OR (NOT reason_present AND reason_normalized_length = 0 AND reason_digest IS NULL)
    )
    AND ((event_type = 'award_committed') = (purchase_order_id IS NOT NULL))
    AND (
        (completeness = 'complete'
            AND comparable_count = candidate_count
            AND selected_proposal_version_id IS NOT NULL
            AND cheapest_proposal_id IS NOT NULL
            AND cheapest_proposal_version_id IS NOT NULL
            AND selected_rank IS NOT NULL
            AND cheapest_rank = 1)
        OR (completeness <> 'complete'
            AND cheapest_proposal_id IS NULL
            AND cheapest_proposal_version_id IS NULL
            AND selected_rank IS NULL
            AND cheapest_rank IS NULL)
    )
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE procurement_award_evidence_candidates
ADD CONSTRAINT proc_award_candidate_shape_check
CHECK (
    ordinal > 0
    AND candidate_hash ~ '^[a-f0-9]{64}$'
    AND (version_content_hash IS NULL OR version_content_hash ~ '^[a-f0-9]{64}$')
    AND (supplier_request_version_hash IS NULL OR supplier_request_version_hash ~ '^[a-f0-9]{64}$')
    AND (subtotal_amount IS NULL OR subtotal_amount ~ '^-?(0|[1-9][0-9]*)(\.[0-9]+)?$')
    AND (delivery_amount IS NULL OR delivery_amount ~ '^-?(0|[1-9][0-9]*)(\.[0-9]+)?$')
    AND (vat_amount IS NULL OR vat_amount ~ '^-?(0|[1-9][0-9]*)(\.[0-9]+)?$')
    AND (total_amount IS NULL OR total_amount ~ '^-?(0|[1-9][0-9]*)(\.[0-9]+)?$')
    AND (comparison_total IS NULL OR comparison_total ~ '^(0|[1-9][0-9]*)(\.[0-9]+)?$')
    AND (currency IS NULL OR currency ~ '^[A-Z]{3}$')
    AND jsonb_typeof(request_line_coverage) = 'array'
    AND jsonb_typeof(exclusion_codes) = 'array'
    AND (
        (comparable
            AND proposal_version_id IS NOT NULL
            AND supplier_request_version_id IS NOT NULL
            AND version_content_hash IS NOT NULL
            AND supplier_request_version_hash IS NOT NULL
            AND proposal_status IN ('submitted', 'accepted')
            AND subtotal_amount IS NOT NULL
            AND delivery_amount IS NOT NULL
            AND vat_amount IS NOT NULL
            AND total_amount IS NOT NULL
            AND comparison_total IS NOT NULL
            AND exclusion_codes = '[]'::jsonb)
        OR (NOT comparable AND jsonb_array_length(exclusion_codes) > 0)
    )
)
SQL);
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION procurement_award_hash_parts(VARIADIC parts text[])
RETURNS text AS $$
    SELECT encode(
        sha256(convert_to(string_agg(
            CASE WHEN part IS NULL THEN '-1:' ELSE octet_length(part)::text || ':' || part END,
            '|' ORDER BY ordinal
        ), 'UTF8')),
        'hex'
    )
    FROM unnest(parts) WITH ORDINALITY AS value(part, ordinal)
$$ LANGUAGE sql IMMUTABLE STRICT
SQL);
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION procurement_award_candidate_source_validate()
RETURNS trigger AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM procurement_award_evidence_events event
        WHERE event.id = NEW.event_id
          AND event.event_type = 'comparison_captured'
    ) AND NOT EXISTS (
        SELECT 1 FROM supplier_proposals proposal
        WHERE proposal.id = NEW.proposal_id
          AND proposal.organization_id = NEW.organization_id
          AND proposal.supplier_request_id = NEW.supplier_request_id
          AND proposal.supplier_party_id = NEW.supplier_party_id
          AND proposal.status IS NOT DISTINCT FROM NEW.proposal_status
          AND proposal.valid_until IS NOT DISTINCT FROM NEW.proposal_valid_until
        FOR KEY SHARE OF proposal
    ) THEN
        RAISE EXCEPTION 'procurement award candidate selection state mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement(<<<'SQL'
CREATE TRIGGER proc_award_candidate_source_validate
BEFORE INSERT ON procurement_award_evidence_candidates
FOR EACH ROW EXECUTE FUNCTION procurement_award_candidate_source_validate()
SQL);
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION procurement_award_deferred_validate()
RETURNS trigger AS $$
DECLARE
    checked_event procurement_award_evidence_events%ROWTYPE;
    checked_candidate procurement_award_evidence_candidates%ROWTYPE;
    predecessor procurement_award_evidence_events%ROWTYPE;
    coverage_frame text;
    candidate_hashes text;
    quarantine_frame text;
    expected_hash text;
    actual_count integer;
    actual_comparable integer;
    actual_min_ordinal integer;
    actual_max_ordinal integer;
BEGIN
    IF TG_TABLE_NAME = 'procurement_award_evidence_candidates' THEN
        checked_candidate := NEW;
        SELECT * INTO checked_event FROM procurement_award_evidence_events WHERE id = NEW.event_id;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'procurement award candidate parent missing' USING ERRCODE = '23514';
        END IF;

        IF checked_candidate.organization_id <> checked_event.organization_id
           OR checked_candidate.project_id IS DISTINCT FROM checked_event.project_id
           OR checked_candidate.purchase_request_id <> checked_event.purchase_request_id
           OR checked_candidate.supplier_request_id <> checked_event.supplier_request_id THEN
            RAISE EXCEPTION 'procurement award candidate event scope mismatch' USING ERRCODE = '23514';
        END IF;

        IF checked_event.event_type = 'comparison_captured' AND NOT EXISTS (
            SELECT 1
            FROM supplier_proposals proposal
            JOIN supplier_requests supplier_request ON supplier_request.id = proposal.supplier_request_id
            JOIN purchase_requests purchase_request ON purchase_request.id = supplier_request.purchase_request_id
            LEFT JOIN site_requests site_request ON site_request.id = purchase_request.site_request_id
            WHERE proposal.id = checked_candidate.proposal_id
              AND proposal.organization_id = checked_candidate.organization_id
              AND proposal.supplier_request_id = checked_candidate.supplier_request_id
              AND proposal.supplier_request_version_id IS NOT DISTINCT FROM checked_candidate.supplier_request_version_id
              AND proposal.supplier_party_id = checked_candidate.supplier_party_id
              AND (
                  (checked_candidate.proposal_version_id IS NULL
                      AND checked_candidate.version_content_hash IS NULL
                      AND NOT EXISTS (
                          SELECT 1 FROM supplier_proposal_versions missing_version
                          WHERE missing_version.supplier_proposal_id = proposal.id
                      ))
                  OR EXISTS (
                      SELECT 1 FROM supplier_proposal_versions proposal_version
                      WHERE proposal_version.id = checked_candidate.proposal_version_id
                        AND proposal_version.supplier_proposal_id = proposal.id
                        AND proposal_version.organization_id = checked_candidate.organization_id
                        AND proposal_version.content_hash IS NOT DISTINCT FROM checked_candidate.version_content_hash
                  )
              )
              AND (
                  (checked_candidate.supplier_request_version_id IS NULL
                      AND checked_candidate.supplier_request_version_hash IS NULL)
                  OR EXISTS (
                      SELECT 1 FROM supplier_request_versions request_version
                      WHERE request_version.id = checked_candidate.supplier_request_version_id
                        AND request_version.supplier_request_id = supplier_request.id
                        AND request_version.organization_id = checked_candidate.organization_id
                        AND request_version.content_hash IS NOT DISTINCT FROM checked_candidate.supplier_request_version_hash
                  )
              )
              AND purchase_request.id = checked_candidate.purchase_request_id
              AND purchase_request.organization_id = checked_candidate.organization_id
              AND site_request.project_id IS NOT DISTINCT FROM checked_candidate.project_id
            FOR KEY SHARE OF proposal, supplier_request, purchase_request
        ) THEN
            RAISE EXCEPTION 'procurement award candidate full lineage mismatch' USING ERRCODE = '23514';
        END IF;

        IF checked_event.event_type <> 'comparison_captured' AND NOT EXISTS (
            SELECT 1
            FROM procurement_award_evidence_candidates predecessor_candidate
            WHERE predecessor_candidate.event_id = checked_event.predecessor_event_id
              AND predecessor_candidate.ordinal = checked_candidate.ordinal
              AND predecessor_candidate.candidate_hash = checked_candidate.candidate_hash
        ) THEN
            RAISE EXCEPTION 'procurement award outcome candidate predecessor mismatch' USING ERRCODE = '23514';
        END IF;

        SELECT string_agg(concat_ws(',',
            line->>'supplier_request_line_id',
            COALESCE(line->>'required_quantity', ''),
            COALESCE(line->>'required_unit', ''),
            COALESCE(line->>'covered_quantity', ''),
            COALESCE(line->>'covered_unit', ''),
            CASE WHEN (line->>'covered')::boolean THEN '1' ELSE '0' END
        ), ';' ORDER BY (line->>'supplier_request_line_id')::bigint)
        INTO coverage_frame
        FROM jsonb_array_elements(checked_candidate.request_line_coverage) line;

        expected_hash := procurement_award_hash_parts(VARIADIC ARRAY[
            checked_candidate.organization_id::text,
            checked_candidate.project_id::text,
            checked_candidate.purchase_request_id::text,
            checked_candidate.supplier_request_id::text,
            checked_candidate.supplier_request_version_id::text,
            checked_candidate.supplier_request_version_hash,
            checked_candidate.proposal_id::text,
            checked_candidate.proposal_version_id::text,
            checked_candidate.supplier_party_id::text,
            checked_candidate.proposal_status,
            checked_candidate.proposal_valid_until::text,
            checked_candidate.version_content_hash,
            checked_candidate.subtotal_amount,
            checked_candidate.delivery_amount,
            checked_candidate.vat_amount,
            checked_candidate.total_amount,
            checked_candidate.comparison_total,
            checked_candidate.currency,
            checked_candidate.vat_mode,
            checked_candidate.vat_rate,
            checked_candidate.delivery_due_date::text,
            checked_candidate.lead_time_days::text,
            coverage_frame,
            CASE WHEN checked_candidate.comparable THEN '1' ELSE '0' END,
            (SELECT string_agg(value, ',' ORDER BY value) FROM jsonb_array_elements_text(checked_candidate.exclusion_codes) value)
        ]);
        IF checked_candidate.candidate_hash <> expected_hash THEN
            RAISE EXCEPTION 'procurement award candidate hash mismatch' USING ERRCODE = '23514';
        END IF;
    ELSE
        checked_event := NEW;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM supplier_proposal_decisions decision
        JOIN supplier_requests supplier_request ON supplier_request.id = decision.supplier_request_id
        JOIN purchase_requests purchase_request ON purchase_request.id = supplier_request.purchase_request_id
        LEFT JOIN site_requests site_request ON site_request.id = purchase_request.site_request_id
        WHERE decision.id = checked_event.decision_id
          AND decision.organization_id = checked_event.organization_id
          AND supplier_request.id = checked_event.supplier_request_id
          AND supplier_request.organization_id = checked_event.organization_id
          AND purchase_request.id = checked_event.purchase_request_id
          AND purchase_request.organization_id = checked_event.organization_id
          AND (
              (checked_event.supplier_request_version_id IS NULL
                  AND checked_event.supplier_request_version_hash IS NULL)
              OR EXISTS (
                  SELECT 1 FROM supplier_request_versions request_version
                  WHERE request_version.id = checked_event.supplier_request_version_id
                    AND request_version.supplier_request_id = supplier_request.id
                    AND request_version.organization_id = checked_event.organization_id
                    AND request_version.content_hash IS NOT DISTINCT FROM checked_event.supplier_request_version_hash
              )
          )
          AND (
              checked_event.event_type <> 'comparison_captured'
              OR (
                  decision.winning_supplier_proposal_id = checked_event.selected_proposal_id
                  AND decision.winning_supplier_proposal_version_id IS NOT DISTINCT FROM checked_event.selected_proposal_version_id
                  AND decision.status = checked_event.selected_status
              )
          )
          AND (checked_event.event_type <> 'award_approved' OR decision.status = 'approved')
          AND (checked_event.event_type <> 'award_rejected' OR decision.status = 'rejected')
          AND (checked_event.event_type <> 'award_committed' OR decision.status IN ('selected', 'approved'))
          AND site_request.project_id IS NOT DISTINCT FROM checked_event.project_id
        FOR KEY SHARE OF decision, supplier_request, purchase_request
    ) THEN
        RAISE EXCEPTION 'procurement award event full lineage mismatch' USING ERRCODE = '23514';
    END IF;

    SELECT count(*), count(*) FILTER (WHERE comparable), min(ordinal), max(ordinal),
           string_agg(candidate_hash, ',' ORDER BY ordinal)
    INTO actual_count, actual_comparable, actual_min_ordinal, actual_max_ordinal, candidate_hashes
    FROM procurement_award_evidence_candidates
    WHERE event_id = checked_event.id;

    IF actual_count <> checked_event.candidate_count
       OR actual_comparable <> checked_event.comparable_count
       OR actual_min_ordinal <> 1
       OR actual_max_ordinal <> checked_event.candidate_count THEN
        RAISE EXCEPTION 'procurement award manifest completeness mismatch' USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM procurement_award_evidence_candidates candidate
        WHERE candidate.event_id = checked_event.id
          AND candidate.proposal_id = checked_event.selected_proposal_id
          AND candidate.proposal_version_id IS NOT DISTINCT FROM checked_event.selected_proposal_version_id
          AND candidate.supplier_request_version_id IS NOT DISTINCT FROM checked_event.supplier_request_version_id
          AND candidate.supplier_request_version_hash IS NOT DISTINCT FROM checked_event.supplier_request_version_hash
    ) THEN
        RAISE EXCEPTION 'procurement award selected pair missing from manifest' USING ERRCODE = '23514';
    END IF;

    IF (checked_event.cheapest_proposal_id IS NULL) <> (checked_event.cheapest_proposal_version_id IS NULL)
       OR (checked_event.cheapest_proposal_id IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM procurement_award_evidence_candidates candidate
            WHERE candidate.event_id = checked_event.id
              AND candidate.proposal_id = checked_event.cheapest_proposal_id
              AND candidate.proposal_version_id = checked_event.cheapest_proposal_version_id
              AND candidate.comparable
       )) THEN
        RAISE EXCEPTION 'procurement award cheapest pair invalid' USING ERRCODE = '23514';
    END IF;

    SELECT string_agg(value, ',' ORDER BY value)
    INTO quarantine_frame
    FROM jsonb_array_elements_text(checked_event.quarantine_codes) value;
    expected_hash := procurement_award_hash_parts(VARIADIC ARRAY[
        checked_event.completeness,
        checked_event.selected_proposal_id::text,
        checked_event.selected_proposal_version_id::text,
        checked_event.cheapest_proposal_id::text,
        checked_event.cheapest_proposal_version_id::text,
        checked_event.selected_rank::text,
        checked_event.cheapest_rank::text,
        COALESCE(quarantine_frame, ''),
        candidate_hashes
    ]);
    IF checked_event.manifest_hash <> expected_hash THEN
        RAISE EXCEPTION 'procurement award manifest hash mismatch' USING ERRCODE = '23514';
    END IF;

    IF checked_event.event_sequence = 1 THEN
        IF EXISTS (
            SELECT 1 FROM procurement_award_evidence_events prior_event
            WHERE prior_event.decision_id = checked_event.decision_id
              AND prior_event.id <> checked_event.id
              AND prior_event.event_sequence < checked_event.event_sequence
        ) THEN
            RAISE EXCEPTION 'procurement award event sequence invalid' USING ERRCODE = '23514';
        END IF;
    ELSIF (
        SELECT max(prior_event.event_sequence)
        FROM procurement_award_evidence_events prior_event
        WHERE prior_event.decision_id = checked_event.decision_id
          AND prior_event.id <> checked_event.id
          AND prior_event.event_sequence < checked_event.event_sequence
    ) IS DISTINCT FROM checked_event.event_sequence - 1 THEN
        RAISE EXCEPTION 'procurement award event sequence invalid' USING ERRCODE = '23514';
    END IF;

    IF checked_event.event_type = 'comparison_captured' THEN
        IF checked_event.predecessor_event_id IS NOT NULL
           OR checked_event.decision_revision IS DISTINCT FROM 1 + COALESCE((
                SELECT max(prior_selection.decision_revision)
                FROM procurement_award_evidence_events prior_selection
                WHERE prior_selection.decision_id = checked_event.decision_id
                  AND prior_selection.id <> checked_event.id
                  AND prior_selection.event_type = 'comparison_captured'
                  AND prior_selection.decision_revision < checked_event.decision_revision
           ), 0) THEN
            RAISE EXCEPTION 'procurement award selection transition invalid' USING ERRCODE = '23514';
        END IF;
        IF checked_event.decision_revision > 1 AND NOT EXISTS (
            SELECT 1 FROM procurement_award_evidence_events prior_selection_outcome
            WHERE prior_selection_outcome.decision_id = checked_event.decision_id
              AND prior_selection_outcome.event_sequence = checked_event.event_sequence - 1
              AND prior_selection_outcome.event_type = 'selection_superseded'
              AND prior_selection_outcome.decision_revision = checked_event.decision_revision - 1
              AND prior_selection_outcome.occurred_at <= checked_event.occurred_at
        ) THEN
            RAISE EXCEPTION 'procurement award prior selection was not superseded' USING ERRCODE = '23514';
        END IF;
    ELSE
        IF checked_event.predecessor_event_id IS NULL THEN
            RAISE EXCEPTION 'procurement award predecessor required' USING ERRCODE = '23514';
        END IF;

        SELECT * INTO predecessor
        FROM procurement_award_evidence_events
        WHERE id = checked_event.predecessor_event_id;
        IF NOT FOUND
           OR predecessor.decision_id <> checked_event.decision_id
           OR predecessor.decision_revision <> checked_event.decision_revision
           OR predecessor.event_sequence IS DISTINCT FROM (
                SELECT max(prior_revision_event.event_sequence)
                FROM procurement_award_evidence_events prior_revision_event
                WHERE prior_revision_event.decision_id = checked_event.decision_id
                  AND prior_revision_event.decision_revision = checked_event.decision_revision
                  AND prior_revision_event.id <> checked_event.id
                  AND prior_revision_event.event_sequence < checked_event.event_sequence
           )
           OR checked_event.occurred_at < predecessor.occurred_at THEN
            RAISE EXCEPTION 'procurement award predecessor mismatch' USING ERRCODE = '23514';
        END IF;

        IF predecessor.organization_id <> checked_event.organization_id
           OR predecessor.project_id IS DISTINCT FROM checked_event.project_id
           OR predecessor.purchase_request_id <> checked_event.purchase_request_id
           OR predecessor.supplier_request_id <> checked_event.supplier_request_id
           OR predecessor.supplier_request_version_id IS DISTINCT FROM checked_event.supplier_request_version_id
           OR predecessor.supplier_request_version_hash IS DISTINCT FROM checked_event.supplier_request_version_hash
           OR predecessor.selected_status <> checked_event.selected_status
           OR predecessor.manifest_hash <> checked_event.manifest_hash
           OR predecessor.policy_id <> checked_event.policy_id
           OR predecessor.policy_version <> checked_event.policy_version
           OR predecessor.policy_hash <> checked_event.policy_hash
           OR predecessor.selection_fingerprint <> checked_event.selection_fingerprint
           OR predecessor.candidate_count <> checked_event.candidate_count
           OR predecessor.comparable_count <> checked_event.comparable_count
           OR predecessor.completeness <> checked_event.completeness
           OR predecessor.quarantine_codes <> checked_event.quarantine_codes
           OR predecessor.selected_proposal_id <> checked_event.selected_proposal_id
           OR predecessor.selected_proposal_version_id IS DISTINCT FROM checked_event.selected_proposal_version_id
           OR predecessor.cheapest_proposal_id IS DISTINCT FROM checked_event.cheapest_proposal_id
           OR predecessor.cheapest_proposal_version_id IS DISTINCT FROM checked_event.cheapest_proposal_version_id
           OR predecessor.selected_rank IS DISTINCT FROM checked_event.selected_rank
           OR predecessor.cheapest_rank IS DISTINCT FROM checked_event.cheapest_rank
           OR predecessor.reason_present <> checked_event.reason_present
           OR predecessor.reason_normalized_length <> checked_event.reason_normalized_length
           OR predecessor.reason_digest IS DISTINCT FROM checked_event.reason_digest THEN
            RAISE EXCEPTION 'procurement award predecessor evidence mismatch' USING ERRCODE = '23514';
        END IF;

        IF checked_event.event_type IN ('award_approved', 'award_rejected')
           AND (predecessor.event_type IS DISTINCT FROM 'comparison_captured'
                OR checked_event.selected_status <> 'approval_required') THEN
            RAISE EXCEPTION 'procurement award resolution transition invalid' USING ERRCODE = '23514';
        ELSIF checked_event.event_type = 'selection_superseded'
           AND predecessor.event_type NOT IN ('comparison_captured', 'award_approved') THEN
            RAISE EXCEPTION 'procurement award supersede transition invalid' USING ERRCODE = '23514';
        ELSIF checked_event.event_type = 'award_committed'
           AND NOT (
                predecessor.event_type = 'award_approved'
                OR (predecessor.event_type = 'comparison_captured' AND checked_event.selected_status = 'selected')
           ) THEN
            RAISE EXCEPTION 'procurement award commit transition invalid' USING ERRCODE = '23514';
        END IF;
    END IF;

    IF checked_event.event_type = 'award_committed' AND NOT EXISTS (
        SELECT 1 FROM purchase_orders purchase_order
        WHERE purchase_order.id = checked_event.purchase_order_id
          AND purchase_order.organization_id = checked_event.organization_id
          AND purchase_order.purchase_request_id = checked_event.purchase_request_id
          AND purchase_order.accepted_supplier_proposal_id = checked_event.selected_proposal_id
          AND purchase_order.accepted_supplier_proposal_version_id = checked_event.selected_proposal_version_id
        FOR KEY SHARE
    ) THEN
        RAISE EXCEPTION 'procurement award committed order lineage mismatch' USING ERRCODE = '23514';
    END IF;

    expected_hash := procurement_award_hash_parts(VARIADIC ARRAY[
        checked_event.organization_id::text,
        checked_event.project_id::text,
        checked_event.purchase_request_id::text,
        checked_event.supplier_request_id::text,
        checked_event.supplier_request_version_id::text,
        checked_event.supplier_request_version_hash,
        checked_event.decision_id::text,
        checked_event.decision_revision::text,
        checked_event.event_sequence::text,
        checked_event.event_type,
        to_char(checked_event.occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        checked_event.actor_id::text,
        checked_event.selected_status,
        checked_event.manifest_hash,
        checked_event.policy_id::text,
        checked_event.policy_version::text,
        checked_event.policy_hash,
        checked_event.selection_fingerprint,
        CASE WHEN checked_event.reason_present THEN '1' ELSE '0' END,
        checked_event.reason_normalized_length::text,
        checked_event.reason_digest,
        checked_event.predecessor_event_id::text,
        checked_event.purchase_order_id::text
    ]);
    IF checked_event.source_hash <> expected_hash THEN
        RAISE EXCEPTION 'procurement award source hash mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        DB::statement(<<<'SQL'
CREATE CONSTRAINT TRIGGER proc_award_events_deferred_validate
AFTER INSERT ON procurement_award_evidence_events
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION procurement_award_deferred_validate()
SQL);
        DB::statement(<<<'SQL'
CREATE CONSTRAINT TRIGGER proc_award_candidates_deferred_validate
AFTER INSERT ON procurement_award_evidence_candidates
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION procurement_award_deferred_validate()
SQL);
        foreach ([
            ['procurement_award_policy_versions', 'proc_award_policies_append_only'],
            ['procurement_award_evidence_events', 'proc_award_events_append_only'],
            ['procurement_award_evidence_candidates', 'proc_award_candidates_append_only'],
            ['supplier_proposal_versions', 'supplier_proposal_versions_append_only'],
            ['supplier_request_versions', 'supplier_request_versions_append_only'],
        ] as [$table, $trigger]) {
            DB::statement(sprintf(
                'CREATE TRIGGER %s BEFORE UPDATE OR DELETE ON %s '
                .'FOR EACH ROW EXECUTE FUNCTION procurement_reporting_prevent_mutation()',
                $trigger,
                $table,
            ));
        }
    }
};
