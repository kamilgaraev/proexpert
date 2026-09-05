<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceShape(true);

        DB::statement(<<<'SQL'
CREATE FUNCTION procurement_award_validate_subset_rank()
RETURNS trigger AS $$
DECLARE
    expected_proposal_id bigint;
    expected_version_id bigint;
    expected_selected_rank bigint;
BEGIN
    IF NEW.completeness <> 'comparable_subset' THEN
        RETURN NEW;
    END IF;

    SELECT proposal_id, proposal_version_id
    INTO expected_proposal_id, expected_version_id
    FROM procurement_award_evidence_candidates
    WHERE event_id = NEW.id AND comparable
    ORDER BY comparison_total::numeric, proposal_id
    LIMIT 1;

    SELECT ranked.position INTO expected_selected_rank
    FROM (
        SELECT proposal_id, row_number() OVER (ORDER BY comparison_total::numeric, proposal_id) AS position
        FROM procurement_award_evidence_candidates
        WHERE event_id = NEW.id AND comparable
    ) ranked
    WHERE ranked.proposal_id = NEW.selected_proposal_id;

    IF expected_proposal_id IS NULL
       OR expected_selected_rank IS NULL
       OR NEW.cheapest_proposal_id IS DISTINCT FROM expected_proposal_id
       OR NEW.cheapest_proposal_version_id IS DISTINCT FROM expected_version_id
       OR NEW.selected_rank IS DISTINCT FROM expected_selected_rank
       OR NEW.cheapest_rank IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'procurement award comparable subset rank mismatch' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        DB::statement(<<<'SQL'
CREATE CONSTRAINT TRIGGER proc_award_subset_rank_event_check
AFTER INSERT ON procurement_award_evidence_events
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION procurement_award_validate_subset_rank()
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('procurement_award_evidence_events')->where('completeness', 'comparable_subset')->exists()) {
            throw new RuntimeException('procurement_award_subset_history_prevents_rollback');
        }

        DB::statement('DROP TRIGGER proc_award_subset_rank_event_check ON procurement_award_evidence_events');
        DB::statement('DROP FUNCTION procurement_award_validate_subset_rank()');
        $this->replaceShape(false);
    }

    private function replaceShape(bool $allowSubset): void
    {
        $states = "'complete', 'not_comparable', 'gap', 'legacy_unverified'";
        $unranked = "completeness <> 'complete'";
        $subset = '';
        if ($allowSubset) {
            $states .= ", 'comparable_subset'";
            $unranked = "completeness NOT IN ('complete', 'comparable_subset')";
            $subset = <<<'SQL'
        OR (completeness = 'comparable_subset'
            AND comparable_count > 0
            AND comparable_count < candidate_count
            AND selected_proposal_version_id IS NOT NULL
            AND cheapest_proposal_id IS NOT NULL
            AND cheapest_proposal_version_id IS NOT NULL
            AND selected_rank IS NOT NULL
            AND selected_rank <= comparable_count
            AND cheapest_rank IS NOT NULL
            AND cheapest_rank = 1)
SQL;
        }

        DB::statement('ALTER TABLE procurement_award_evidence_events DROP CONSTRAINT proc_award_event_shape_check');
        DB::statement(<<<SQL
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
    AND completeness IN ($states)
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
$subset
        OR ($unranked
            AND cheapest_proposal_id IS NULL
            AND cheapest_proposal_version_id IS NULL
            AND selected_rank IS NULL
            AND cheapest_rank IS NULL)
    )
)
SQL);
    }
};
