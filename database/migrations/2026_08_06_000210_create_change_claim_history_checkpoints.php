<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $evidence = DB::transaction(function (): array {
            Schema::create('change_claim_history_checkpoints', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('organization_id');
                $table->dateTimeTz('completed_at', 6);
                $table->unsignedBigInteger('change_request_count');
                $table->unsignedBigInteger('change_request_watermark_id');
                $table->char('change_request_set_hash', 64);
                $table->unsignedBigInteger('version_count');
                $table->unsignedBigInteger('version_watermark_id');
                $table->char('version_set_hash', 64);
                $table->unsignedBigInteger('workflow_event_count');
                $table->unsignedBigInteger('workflow_event_watermark_id');
                $table->char('workflow_event_set_hash', 64);
                $table->unsignedBigInteger('claim_link_count');
                $table->unsignedBigInteger('claim_link_watermark_id');
                $table->char('claim_link_set_hash', 64);
                $table->unsignedBigInteger('ledger_count');
                $table->unsignedBigInteger('ledger_watermark_id');
                $table->char('ledger_set_hash', 64);
                $table->unsignedBigInteger('unprojectable_legacy_count');
                $table->char('unprojectable_legacy_set_hash', 64);
                $table->char('source_hash', 64);
                $table->dateTimeTz('created_at', 6);
                $table->dateTimeTz('updated_at', 6);

                $table->unique('organization_id', 'change_claim_history_checkpoint_org_unique');
            });

            DB::statement('LOCK TABLE organizations, change_management_change_requests, change_management_impacts, change_management_approvals, change_management_claims, projects, contracts, contract_project_allocations, change_request_versions, change_workflow_events, change_claim_links, contingency_ledger_entries IN SHARE ROW EXCLUSIVE MODE');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_change_claim_canonical_json_v1(payload jsonb)
RETURNS text
LANGUAGE plpgsql
IMMUTABLE
STRICT
PARALLEL SAFE
AS $$
DECLARE
    canonical text;
BEGIN
    CASE jsonb_typeof(payload)
        WHEN 'object' THEN
            SELECT '{' || COALESCE(string_agg(
                to_jsonb(item.key)::text || ':' || most_change_claim_canonical_json_v1(item.value),
                ',' ORDER BY item.key
            ), '') || '}'
            INTO canonical
            FROM jsonb_each(payload) AS item;
        WHEN 'array' THEN
            SELECT '[' || COALESCE(string_agg(
                most_change_claim_canonical_json_v1(item.value),
                ',' ORDER BY item.ordinality
            ), '') || ']'
            INTO canonical
            FROM jsonb_array_elements(payload) WITH ORDINALITY AS item(value, ordinality);
        ELSE
            canonical := payload::text;
    END CASE;

    RETURN canonical;
END;
$$;

CREATE OR REPLACE FUNCTION most_change_claim_canonical_hash_v1(payload jsonb)
RETURNS text
LANGUAGE sql
IMMUTABLE
STRICT
PARALLEL SAFE
AS $$
    SELECT encode(sha256(convert_to(most_change_claim_canonical_json_v1(payload), 'UTF8')), 'hex')
$$;
SQL);
            DB::statement(<<<'SQL'
WITH boundary AS MATERIALIZED (
    SELECT clock_timestamp()::timestamptz(6) AS completed_at
), request_projection AS MATERIALIZED (
    SELECT
        request.organization_id,
        request.id,
        jsonb_build_object(
            'request', to_jsonb(request),
            'project', COALESCE(to_jsonb(request_project), 'null'::jsonb),
            'allocation', COALESCE(to_jsonb(request_allocation), 'null'::jsonb),
            'contract', COALESCE(to_jsonb(request_contract), 'null'::jsonb),
            'impact', COALESCE(to_jsonb(impact), 'null'::jsonb),
            'approved_evidence', COALESCE(to_jsonb(approval), 'null'::jsonb)
        ) AS source_identity,
        request_project.id IS NULL
            OR request_project.organization_id IS DISTINCT FROM request.organization_id
            OR impact.id IS NULL
            OR request.reporting_currency IS NULL
            OR request.reporting_currency !~ '^[A-Z]{3}$'
            OR request.reporting_contract_project_allocation_id IS NULL
            OR request_allocation.id IS NULL
            OR request_allocation.project_id IS DISTINCT FROM request.project_id
            OR request_contract.id IS NULL
            OR request_contract.organization_id IS DISTINCT FROM request.organization_id
            OR (
                request.approved_at IS NOT NULL
                AND (
                    approval.id IS NULL
                    OR approval.approved_cost_minor IS NULL
                    OR approval.currency IS DISTINCT FROM request.reporting_currency
                )
            ) AS unprojectable
    FROM change_management_change_requests AS request
    LEFT JOIN projects AS request_project
      ON request_project.id = request.project_id
    LEFT JOIN contract_project_allocations AS request_allocation
      ON request_allocation.id = request.reporting_contract_project_allocation_id
    LEFT JOIN contracts AS request_contract
      ON request_contract.id = request_allocation.contract_id
    LEFT JOIN LATERAL (
        SELECT source.*
        FROM change_management_impacts AS source
        WHERE source.organization_id = request.organization_id
          AND source.change_request_id = request.id
        ORDER BY source.id DESC
        LIMIT 1
    ) AS impact ON true
    LEFT JOIN LATERAL (
        SELECT source.*
        FROM change_management_approvals AS source
        WHERE source.organization_id = request.organization_id
          AND source.change_request_id = request.id
          AND source.status = 'approved'
        ORDER BY source.decided_at DESC NULLS LAST, source.id DESC
        LIMIT 1
    ) AS approval ON true
), request_sources AS MATERIALIZED (
    SELECT
        organization_id,
        COUNT(*)::bigint AS change_request_count,
        COALESCE(MAX(id), 0)::bigint AS change_request_watermark_id,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(source_identity::text, 'UTF8')), 'hex'),
            '' ORDER BY id
        ), ''), 'UTF8')), 'hex') AS change_request_set_hash
    FROM request_projection
    GROUP BY organization_id
), request_gaps AS MATERIALIZED (
    SELECT
        organization_id,
        'request_source_incomplete'::text AS gap_kind,
        id AS source_id,
        encode(sha256(convert_to(source_identity::text, 'UTF8')), 'hex') AS source_hash
    FROM request_projection
    WHERE unprojectable
), version_gaps AS MATERIALIZED (
    SELECT
        version.organization_id,
        'version_scope_drift'::text AS gap_kind,
        version.id AS source_id,
        version.source_hash
    FROM change_request_versions AS version
    LEFT JOIN change_management_change_requests AS request
      ON request.id = version.change_request_id
    LEFT JOIN contract_project_allocations AS allocation
      ON allocation.id = version.contract_project_allocation_id
    LEFT JOIN contracts AS contract
      ON contract.id = allocation.contract_id
    LEFT JOIN projects AS version_project
      ON version_project.id = version.project_id
    WHERE request.id IS NULL
       OR request.organization_id IS DISTINCT FROM version.organization_id
       OR request.project_id IS DISTINCT FROM version.project_id
       OR version_project.id IS NULL
       OR version_project.organization_id IS DISTINCT FROM version.organization_id
       OR version.contract_project_allocation_id IS NULL
       OR allocation.id IS NULL
       OR allocation.project_id IS DISTINCT FROM version.project_id
       OR version.contract_id IS NULL
       OR contract.id IS DISTINCT FROM version.contract_id
       OR contract.organization_id IS DISTINCT FROM version.organization_id
       OR version.source_hash IS DISTINCT FROM most_change_claim_canonical_hash_v1(jsonb_build_object(
           'organization_id', version.organization_id,
           'change_request_id', version.change_request_id,
           'version', version.version,
           'project_id', version.project_id,
           'contract_id', version.contract_id,
           'contract_project_allocation_id', version.contract_project_allocation_id,
           'initiator_user_id', version.initiator_user_id,
           'initiator_type', version.initiator_type,
           'reason', version.reason,
           'owner_user_id', version.owner_user_id,
           'status', version.status,
           'proposed_cost_minor', version.proposed_cost_minor,
           'proposed_schedule_days', version.proposed_schedule_days,
           'approved_cost_minor', version.approved_cost_minor,
           'approved_schedule_days', version.approved_schedule_days,
           'currency', version.currency,
           'currency_source', version.currency_source,
           'effective_at', to_char(
               version.effective_at AT TIME ZONE 'UTC',
               'YYYY-MM-DD"T"HH24:MI:SS'
           ) || '+00:00'
       ))
), workflow_event_gaps AS MATERIALIZED (
    SELECT
        event.organization_id,
        'workflow_event_scope_drift'::text AS gap_kind,
        event.id AS source_id,
        event.event_hash AS source_hash
    FROM change_workflow_events AS event
    LEFT JOIN change_request_versions AS version
      ON version.organization_id = event.organization_id
     AND version.change_request_id = event.change_request_id
     AND version.version = event.version
    WHERE version.id IS NULL
       OR version.project_id IS DISTINCT FROM event.project_id
       OR event.event_hash IS DISTINCT FROM most_change_claim_canonical_hash_v1(jsonb_build_object(
           'organization_id', event.organization_id,
           'change_request_id', event.change_request_id,
           'version', event.version,
           'project_id', event.project_id,
           'event_type', event.event_type,
           'prior_status', event.prior_status,
           'current_status', event.current_status,
           'actor_id', event.actor_id,
           'occurred_at', to_char(
               event.occurred_at AT TIME ZONE 'UTC',
               'YYYY-MM-DD"T"HH24:MI:SS'
           ) || '+00:00'
       ))
), claim_link_gaps AS MATERIALIZED (
    SELECT
        link.organization_id,
        'claim_link_scope_drift'::text AS gap_kind,
        link.id AS source_id,
        link.source_hash
    FROM change_claim_links AS link
    LEFT JOIN change_request_versions AS version
      ON version.id = link.change_request_version_id
    LEFT JOIN change_management_claims AS claim
      ON claim.id = link.change_claim_id
    WHERE version.id IS NULL
       OR claim.id IS NULL
       OR version.organization_id IS DISTINCT FROM link.organization_id
       OR claim.organization_id IS DISTINCT FROM link.organization_id
       OR claim.project_id IS DISTINCT FROM version.project_id
       OR (
           claim.change_request_id IS NOT NULL
           AND claim.change_request_id IS DISTINCT FROM version.change_request_id
       )
       OR link.source_hash IS DISTINCT FROM most_change_claim_canonical_hash_v1(jsonb_build_object(
           'organization_id', link.organization_id,
           'change_request_version_id', link.change_request_version_id,
           'change_claim_id', link.change_claim_id,
           'claim_version', link.claim_version,
           'claim_amount_minor', link.claim_amount_minor,
           'currency', link.currency,
           'relationship_type', link.relationship_type
       ))
), ledger_gaps AS MATERIALIZED (
    SELECT
        ledger.organization_id,
        'ledger_scope_drift'::text AS gap_kind,
        ledger.id AS source_id,
        ledger.entry_hash AS source_hash
    FROM contingency_ledger_entries AS ledger
    LEFT JOIN contract_project_allocations AS allocation
      ON allocation.id = ledger.contract_project_allocation_id
    LEFT JOIN contracts AS contract
      ON contract.id = allocation.contract_id
    LEFT JOIN projects AS ledger_project
      ON ledger_project.id = ledger.project_id
    WHERE allocation.id IS NULL
       OR allocation.project_id IS DISTINCT FROM ledger.project_id
       OR contract.id IS NULL
       OR contract.organization_id IS DISTINCT FROM ledger.organization_id
       OR ledger_project.id IS NULL
       OR ledger_project.organization_id IS DISTINCT FROM ledger.organization_id
       OR ledger.entry_hash IS DISTINCT FROM most_change_claim_canonical_hash_v1(jsonb_build_object(
           'organization_id', ledger.organization_id,
           'project_id', ledger.project_id,
           'contract_project_allocation_id', ledger.contract_project_allocation_id,
           'currency', ledger.currency,
           'currency_source', ledger.currency_source,
           'movement_type', ledger.movement_type,
           'signed_amount_minor', ledger.signed_amount_minor,
           'effective_on', to_char(ledger.effective_on, 'YYYY-MM-DD'),
           'effective_at', to_char(
               ledger.effective_at AT TIME ZONE 'UTC',
               'YYYY-MM-DD"T"HH24:MI:SS'
           ) || '+00:00',
           'source_type', ledger.source_type,
           'source_id', ledger.source_id,
           'source_version', ledger.source_version,
           'idempotency_key', ledger.idempotency_key
       ))
), integrity_gaps AS MATERIALIZED (
    SELECT * FROM request_gaps
    UNION ALL
    SELECT * FROM version_gaps
    UNION ALL
    SELECT * FROM workflow_event_gaps
    UNION ALL
    SELECT * FROM claim_link_gaps
    UNION ALL
    SELECT * FROM ledger_gaps
), integrity_gap_sources AS MATERIALIZED (
    SELECT
        organization_id,
        COUNT(*)::bigint AS unprojectable_legacy_count,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_array(
                gap_kind,
                source_id,
                source_hash
            )::text, 'UTF8')), 'hex'),
            '' ORDER BY gap_kind, source_id
        ), ''), 'UTF8')), 'hex') AS unprojectable_legacy_set_hash
    FROM integrity_gaps
    GROUP BY organization_id
), version_sources AS MATERIALIZED (
    SELECT
        organization_id,
        COUNT(*)::bigint AS version_count,
        COALESCE(MAX(id), 0)::bigint AS version_watermark_id,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_array(id, source_hash)::text, 'UTF8')), 'hex'),
            '' ORDER BY id
        ), ''), 'UTF8')), 'hex') AS version_set_hash
    FROM change_request_versions
    GROUP BY organization_id
), workflow_event_sources AS MATERIALIZED (
    SELECT
        organization_id,
        COUNT(*)::bigint AS workflow_event_count,
        COALESCE(MAX(id), 0)::bigint AS workflow_event_watermark_id,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_array(id, event_hash)::text, 'UTF8')), 'hex'),
            '' ORDER BY id
        ), ''), 'UTF8')), 'hex') AS workflow_event_set_hash
    FROM change_workflow_events
    GROUP BY organization_id
), claim_link_sources AS MATERIALIZED (
    SELECT
        organization_id,
        COUNT(*)::bigint AS claim_link_count,
        COALESCE(MAX(id), 0)::bigint AS claim_link_watermark_id,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_array(id, source_hash)::text, 'UTF8')), 'hex'),
            '' ORDER BY id
        ), ''), 'UTF8')), 'hex') AS claim_link_set_hash
    FROM change_claim_links
    GROUP BY organization_id
), ledger_sources AS MATERIALIZED (
    SELECT
        organization_id,
        COUNT(*)::bigint AS ledger_count,
        COALESCE(MAX(id), 0)::bigint AS ledger_watermark_id,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_array(id, entry_hash)::text, 'UTF8')), 'hex'),
            '' ORDER BY id
        ), ''), 'UTF8')), 'hex') AS ledger_set_hash
    FROM contingency_ledger_entries
    GROUP BY organization_id
), checkpoint_rows AS MATERIALIZED (
    SELECT
        organization.id AS organization_id,
        boundary.completed_at,
        COALESCE(request_sources.change_request_count, 0)::bigint AS change_request_count,
        COALESCE(request_sources.change_request_watermark_id, 0)::bigint AS change_request_watermark_id,
        COALESCE(request_sources.change_request_set_hash, encode(sha256(convert_to('', 'UTF8')), 'hex')) AS change_request_set_hash,
        COALESCE(version_sources.version_count, 0)::bigint AS version_count,
        COALESCE(version_sources.version_watermark_id, 0)::bigint AS version_watermark_id,
        COALESCE(version_sources.version_set_hash, encode(sha256(convert_to('', 'UTF8')), 'hex')) AS version_set_hash,
        COALESCE(workflow_event_sources.workflow_event_count, 0)::bigint AS workflow_event_count,
        COALESCE(workflow_event_sources.workflow_event_watermark_id, 0)::bigint AS workflow_event_watermark_id,
        COALESCE(workflow_event_sources.workflow_event_set_hash, encode(sha256(convert_to('', 'UTF8')), 'hex')) AS workflow_event_set_hash,
        COALESCE(claim_link_sources.claim_link_count, 0)::bigint AS claim_link_count,
        COALESCE(claim_link_sources.claim_link_watermark_id, 0)::bigint AS claim_link_watermark_id,
        COALESCE(claim_link_sources.claim_link_set_hash, encode(sha256(convert_to('', 'UTF8')), 'hex')) AS claim_link_set_hash,
        COALESCE(ledger_sources.ledger_count, 0)::bigint AS ledger_count,
        COALESCE(ledger_sources.ledger_watermark_id, 0)::bigint AS ledger_watermark_id,
        COALESCE(ledger_sources.ledger_set_hash, encode(sha256(convert_to('', 'UTF8')), 'hex')) AS ledger_set_hash,
        COALESCE(integrity_gap_sources.unprojectable_legacy_count, 0)::bigint AS unprojectable_legacy_count,
        COALESCE(integrity_gap_sources.unprojectable_legacy_set_hash, encode(sha256(convert_to('', 'UTF8')), 'hex')) AS unprojectable_legacy_set_hash
    FROM organizations AS organization
    CROSS JOIN boundary
    LEFT JOIN request_sources ON request_sources.organization_id = organization.id
    LEFT JOIN version_sources ON version_sources.organization_id = organization.id
    LEFT JOIN workflow_event_sources ON workflow_event_sources.organization_id = organization.id
    LEFT JOIN claim_link_sources ON claim_link_sources.organization_id = organization.id
    LEFT JOIN ledger_sources ON ledger_sources.organization_id = organization.id
    LEFT JOIN integrity_gap_sources ON integrity_gap_sources.organization_id = organization.id
)
INSERT INTO change_claim_history_checkpoints (
    organization_id,
    completed_at,
    change_request_count,
    change_request_watermark_id,
    change_request_set_hash,
    version_count,
    version_watermark_id,
    version_set_hash,
    workflow_event_count,
    workflow_event_watermark_id,
    workflow_event_set_hash,
    claim_link_count,
    claim_link_watermark_id,
    claim_link_set_hash,
    ledger_count,
    ledger_watermark_id,
    ledger_set_hash,
    unprojectable_legacy_count,
    unprojectable_legacy_set_hash,
    source_hash,
    created_at,
    updated_at
)
SELECT
    organization_id,
    completed_at,
    change_request_count,
    change_request_watermark_id,
    change_request_set_hash,
    version_count,
    version_watermark_id,
    version_set_hash,
    workflow_event_count,
    workflow_event_watermark_id,
    workflow_event_set_hash,
    claim_link_count,
    claim_link_watermark_id,
    claim_link_set_hash,
    ledger_count,
    ledger_watermark_id,
    ledger_set_hash,
    unprojectable_legacy_count,
    unprojectable_legacy_set_hash,
    encode(sha256(convert_to(jsonb_build_object(
        'organization_id', organization_id,
        'completed_at', to_char(completed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'change_request_count', change_request_count,
        'change_request_watermark_id', change_request_watermark_id,
        'change_request_set_hash', change_request_set_hash,
        'version_count', version_count,
        'version_watermark_id', version_watermark_id,
        'version_set_hash', version_set_hash,
        'workflow_event_count', workflow_event_count,
        'workflow_event_watermark_id', workflow_event_watermark_id,
        'workflow_event_set_hash', workflow_event_set_hash,
        'claim_link_count', claim_link_count,
        'claim_link_watermark_id', claim_link_watermark_id,
        'claim_link_set_hash', claim_link_set_hash,
        'ledger_count', ledger_count,
        'ledger_watermark_id', ledger_watermark_id,
        'ledger_set_hash', ledger_set_hash,
        'unprojectable_legacy_count', unprojectable_legacy_count,
        'unprojectable_legacy_set_hash', unprojectable_legacy_set_hash
    )::text, 'UTF8')), 'hex'),
    completed_at,
    completed_at
FROM checkpoint_rows
SQL);

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_change_claim_source_insert_guard_v1()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    request_organization_id bigint;
    request_project_id bigint;
    request_allocation_id bigint;
    project_organization_id bigint;
    allocation_project_id bigint;
    allocation_contract_id bigint;
    contract_organization_id bigint;
    version_organization_id bigint;
    version_change_request_id bigint;
    version_project_id bigint;
    version_currency char(3);
    claim_organization_id bigint;
    claim_project_id bigint;
    claim_change_request_id bigint;
    expected_hash text;
BEGIN
    CASE TG_TABLE_NAME
        WHEN 'change_request_versions' THEN
            SELECT
                request.organization_id,
                request.project_id,
                request.reporting_contract_project_allocation_id,
                project.organization_id,
                allocation.project_id,
                allocation.contract_id,
                contract.organization_id
            INTO
                request_organization_id,
                request_project_id,
                request_allocation_id,
                project_organization_id,
                allocation_project_id,
                allocation_contract_id,
                contract_organization_id
            FROM change_management_change_requests AS request
            LEFT JOIN projects AS project ON project.id = NEW.project_id
            LEFT JOIN contract_project_allocations AS allocation
              ON allocation.id = NEW.contract_project_allocation_id
            LEFT JOIN contracts AS contract ON contract.id = allocation.contract_id
            WHERE request.id = NEW.change_request_id;

            IF NOT FOUND
                OR NEW.organization_id IS DISTINCT FROM request_organization_id
                OR NEW.project_id IS DISTINCT FROM request_project_id
                OR project_organization_id IS DISTINCT FROM NEW.organization_id
                OR NEW.contract_project_allocation_id IS DISTINCT FROM request_allocation_id
                OR (
                    NEW.contract_project_allocation_id IS NULL
                    AND NEW.contract_id IS NOT NULL
                )
                OR (
                    NEW.contract_project_allocation_id IS NOT NULL
                    AND (
                        allocation_project_id IS DISTINCT FROM NEW.project_id
                        OR allocation_contract_id IS DISTINCT FROM NEW.contract_id
                        OR contract_organization_id IS DISTINCT FROM NEW.organization_id
                    )
                )
            THEN
                RAISE EXCEPTION 'change_claim_version_scope_mismatch'
                    USING ERRCODE = '23514';
            END IF;

            expected_hash := most_change_claim_canonical_hash_v1(jsonb_build_object(
                'organization_id', NEW.organization_id,
                'change_request_id', NEW.change_request_id,
                'version', NEW.version,
                'project_id', NEW.project_id,
                'contract_id', NEW.contract_id,
                'contract_project_allocation_id', NEW.contract_project_allocation_id,
                'initiator_user_id', NEW.initiator_user_id,
                'initiator_type', NEW.initiator_type,
                'reason', NEW.reason,
                'owner_user_id', NEW.owner_user_id,
                'status', NEW.status,
                'proposed_cost_minor', NEW.proposed_cost_minor,
                'proposed_schedule_days', NEW.proposed_schedule_days,
                'approved_cost_minor', NEW.approved_cost_minor,
                'approved_schedule_days', NEW.approved_schedule_days,
                'currency', NEW.currency,
                'currency_source', NEW.currency_source,
                'effective_at', to_char(
                    NEW.effective_at AT TIME ZONE 'UTC',
                    'YYYY-MM-DD"T"HH24:MI:SS'
                ) || '+00:00'
            ));
            IF NEW.source_hash::text !~ '^[a-f0-9]{64}$'
                OR NEW.source_hash::text IS DISTINCT FROM expected_hash
            THEN
                RAISE EXCEPTION 'change_claim_version_hash_mismatch'
                    USING ERRCODE = '23514';
            END IF;

        WHEN 'change_workflow_events' THEN
            SELECT
                version.organization_id,
                version.change_request_id,
                version.project_id,
                project.organization_id
            INTO
                version_organization_id,
                version_change_request_id,
                version_project_id,
                project_organization_id
            FROM change_request_versions AS version
            LEFT JOIN projects AS project ON project.id = version.project_id
            WHERE version.organization_id = NEW.organization_id
              AND version.change_request_id = NEW.change_request_id
              AND version.version = NEW.version;

            IF NOT FOUND
                OR version_project_id IS DISTINCT FROM NEW.project_id
                OR project_organization_id IS DISTINCT FROM NEW.organization_id
            THEN
                RAISE EXCEPTION 'change_claim_event_scope_mismatch'
                    USING ERRCODE = '23514';
            END IF;

            expected_hash := most_change_claim_canonical_hash_v1(jsonb_build_object(
                'organization_id', NEW.organization_id,
                'change_request_id', NEW.change_request_id,
                'version', NEW.version,
                'project_id', NEW.project_id,
                'event_type', NEW.event_type,
                'prior_status', NEW.prior_status,
                'current_status', NEW.current_status,
                'actor_id', NEW.actor_id,
                'occurred_at', to_char(
                    NEW.occurred_at AT TIME ZONE 'UTC',
                    'YYYY-MM-DD"T"HH24:MI:SS'
                ) || '+00:00'
            ));
            IF NEW.event_hash::text !~ '^[a-f0-9]{64}$'
                OR NEW.event_hash::text IS DISTINCT FROM expected_hash
            THEN
                RAISE EXCEPTION 'change_claim_event_hash_mismatch'
                    USING ERRCODE = '23514';
            END IF;

        WHEN 'change_claim_links' THEN
            SELECT
                version.organization_id,
                version.change_request_id,
                version.project_id,
                version.currency,
                claim.organization_id,
                claim.project_id,
                claim.change_request_id,
                project.organization_id
            INTO
                version_organization_id,
                version_change_request_id,
                version_project_id,
                version_currency,
                claim_organization_id,
                claim_project_id,
                claim_change_request_id,
                project_organization_id
            FROM change_request_versions AS version
            LEFT JOIN change_management_claims AS claim ON claim.id = NEW.change_claim_id
            LEFT JOIN projects AS project ON project.id = version.project_id
            WHERE version.id = NEW.change_request_version_id;

            IF NOT FOUND
                OR version_organization_id IS DISTINCT FROM NEW.organization_id
                OR claim_organization_id IS DISTINCT FROM NEW.organization_id
                OR claim_project_id IS DISTINCT FROM version_project_id
                OR (
                    claim_change_request_id IS NOT NULL
                    AND claim_change_request_id IS DISTINCT FROM version_change_request_id
                )
                OR project_organization_id IS DISTINCT FROM NEW.organization_id
                OR NEW.currency IS DISTINCT FROM version_currency
            THEN
                RAISE EXCEPTION 'change_claim_link_scope_mismatch'
                    USING ERRCODE = '23514';
            END IF;

            expected_hash := most_change_claim_canonical_hash_v1(jsonb_build_object(
                'organization_id', NEW.organization_id,
                'change_request_version_id', NEW.change_request_version_id,
                'change_claim_id', NEW.change_claim_id,
                'claim_version', NEW.claim_version,
                'claim_amount_minor', NEW.claim_amount_minor,
                'currency', NEW.currency,
                'relationship_type', NEW.relationship_type
            ));
            IF NEW.source_hash::text !~ '^[a-f0-9]{64}$'
                OR NEW.source_hash::text IS DISTINCT FROM expected_hash
            THEN
                RAISE EXCEPTION 'change_claim_link_hash_mismatch'
                    USING ERRCODE = '23514';
            END IF;

        WHEN 'contingency_ledger_entries' THEN
            SELECT
                project.organization_id,
                allocation.project_id,
                allocation.contract_id,
                contract.organization_id
            INTO
                project_organization_id,
                allocation_project_id,
                allocation_contract_id,
                contract_organization_id
            FROM projects AS project
            LEFT JOIN contract_project_allocations AS allocation
              ON allocation.id = NEW.contract_project_allocation_id
            LEFT JOIN contracts AS contract ON contract.id = allocation.contract_id
            WHERE project.id = NEW.project_id;

            IF NOT FOUND
                OR project_organization_id IS DISTINCT FROM NEW.organization_id
                OR allocation_project_id IS DISTINCT FROM NEW.project_id
                OR allocation_contract_id IS NULL
                OR contract_organization_id IS DISTINCT FROM NEW.organization_id
            THEN
                RAISE EXCEPTION 'change_claim_ledger_scope_mismatch'
                    USING ERRCODE = '23514';
            END IF;

            expected_hash := most_change_claim_canonical_hash_v1(jsonb_build_object(
                'organization_id', NEW.organization_id,
                'project_id', NEW.project_id,
                'contract_project_allocation_id', NEW.contract_project_allocation_id,
                'currency', NEW.currency,
                'currency_source', NEW.currency_source,
                'movement_type', NEW.movement_type,
                'signed_amount_minor', NEW.signed_amount_minor,
                'effective_on', to_char(NEW.effective_on, 'YYYY-MM-DD'),
                'effective_at', to_char(
                    NEW.effective_at AT TIME ZONE 'UTC',
                    'YYYY-MM-DD"T"HH24:MI:SS'
                ) || '+00:00',
                'source_type', NEW.source_type,
                'source_id', NEW.source_id,
                'source_version', NEW.source_version,
                'idempotency_key', NEW.idempotency_key
            ));
            IF NEW.entry_hash::text !~ '^[a-f0-9]{64}$'
                OR NEW.entry_hash::text IS DISTINCT FROM expected_hash
            THEN
                RAISE EXCEPTION 'change_claim_ledger_hash_mismatch'
                    USING ERRCODE = '23514';
            END IF;

        ELSE
            RAISE EXCEPTION 'change_claim_source_guard_table_invalid'
                USING ERRCODE = '23514';
    END CASE;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS change_request_versions_scope_hash_guard ON change_request_versions;
CREATE TRIGGER change_request_versions_scope_hash_guard
BEFORE INSERT ON change_request_versions
FOR EACH ROW EXECUTE FUNCTION most_change_claim_source_insert_guard_v1();

DROP TRIGGER IF EXISTS change_workflow_events_scope_hash_guard ON change_workflow_events;
CREATE TRIGGER change_workflow_events_scope_hash_guard
BEFORE INSERT ON change_workflow_events
FOR EACH ROW EXECUTE FUNCTION most_change_claim_source_insert_guard_v1();

DROP TRIGGER IF EXISTS change_claim_links_scope_hash_guard ON change_claim_links;
CREATE TRIGGER change_claim_links_scope_hash_guard
BEFORE INSERT ON change_claim_links
FOR EACH ROW EXECUTE FUNCTION most_change_claim_source_insert_guard_v1();

DROP TRIGGER IF EXISTS contingency_ledger_entries_scope_hash_guard ON contingency_ledger_entries;
CREATE TRIGGER contingency_ledger_entries_scope_hash_guard
BEFORE INSERT ON contingency_ledger_entries
FOR EACH ROW EXECUTE FUNCTION most_change_claim_source_insert_guard_v1();

CREATE OR REPLACE FUNCTION most_seed_change_claim_history_checkpoint_v1()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    checkpoint_at timestamptz(6) := clock_timestamp()::timestamptz(6);
    empty_set_hash char(64) := encode(sha256(convert_to('', 'UTF8')), 'hex');
    checkpoint_hash char(64);
BEGIN
    checkpoint_hash := encode(sha256(convert_to(jsonb_build_object(
        'organization_id', NEW.id,
        'completed_at', to_char(checkpoint_at AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'),
        'change_request_count', 0,
        'change_request_watermark_id', 0,
        'change_request_set_hash', empty_set_hash,
        'version_count', 0,
        'version_watermark_id', 0,
        'version_set_hash', empty_set_hash,
        'workflow_event_count', 0,
        'workflow_event_watermark_id', 0,
        'workflow_event_set_hash', empty_set_hash,
        'claim_link_count', 0,
        'claim_link_watermark_id', 0,
        'claim_link_set_hash', empty_set_hash,
        'ledger_count', 0,
        'ledger_watermark_id', 0,
        'ledger_set_hash', empty_set_hash,
        'unprojectable_legacy_count', 0,
        'unprojectable_legacy_set_hash', empty_set_hash
    )::text, 'UTF8')), 'hex');

    INSERT INTO change_claim_history_checkpoints (
        organization_id,
        completed_at,
        change_request_count,
        change_request_watermark_id,
        change_request_set_hash,
        version_count,
        version_watermark_id,
        version_set_hash,
        workflow_event_count,
        workflow_event_watermark_id,
        workflow_event_set_hash,
        claim_link_count,
        claim_link_watermark_id,
        claim_link_set_hash,
        ledger_count,
        ledger_watermark_id,
        ledger_set_hash,
        unprojectable_legacy_count,
        unprojectable_legacy_set_hash,
        source_hash,
        created_at,
        updated_at
    ) VALUES (
        NEW.id,
        checkpoint_at,
        0,
        0,
        empty_set_hash,
        0,
        0,
        empty_set_hash,
        0,
        0,
        empty_set_hash,
        0,
        0,
        empty_set_hash,
        0,
        0,
        empty_set_hash,
        0,
        empty_set_hash,
        checkpoint_hash,
        checkpoint_at,
        checkpoint_at
    )
    ON CONFLICT (organization_id) DO NOTHING;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS most_seed_change_claim_history_checkpoint_v1 ON organizations;
CREATE TRIGGER most_seed_change_claim_history_checkpoint_v1
AFTER INSERT ON organizations
FOR EACH ROW EXECUTE FUNCTION most_seed_change_claim_history_checkpoint_v1();

CREATE TRIGGER change_claim_history_checkpoints_append_only
BEFORE UPDATE OR DELETE ON change_claim_history_checkpoints
FOR EACH ROW EXECUTE FUNCTION reports_change_claim_append_only();
SQL);

            $organizationCount = DB::table('organizations')->count();
            $checkpoints = DB::table('change_claim_history_checkpoints')
                ->orderBy('organization_id')
                ->get();
            if ($checkpoints->count() !== $organizationCount) {
                throw new RuntimeException('Change claim checkpoint organization coverage mismatch.');
            }

            $identities = [];
            foreach ($checkpoints as $checkpoint) {
                foreach ([
                    'change_request_set_hash',
                    'version_set_hash',
                    'workflow_event_set_hash',
                    'claim_link_set_hash',
                    'ledger_set_hash',
                    'unprojectable_legacy_set_hash',
                    'source_hash',
                ] as $hashField) {
                    if (! is_string($checkpoint->{$hashField})
                        || preg_match('/^[a-f0-9]{64}$/D', $checkpoint->{$hashField}) !== 1) {
                        throw new RuntimeException('Change claim checkpoint hash is invalid.');
                    }
                }
                $identities[] = (int) $checkpoint->organization_id.':'.$checkpoint->source_hash;
            }

            return [
                'checkpoint_count' => $checkpoints->count(),
                'content_hash' => hash('sha256', implode('|', $identities)),
                'change_request_count' => (int) $checkpoints->sum('change_request_count'),
                'version_count' => (int) $checkpoints->sum('version_count'),
                'workflow_event_count' => (int) $checkpoints->sum('workflow_event_count'),
                'claim_link_count' => (int) $checkpoints->sum('claim_link_count'),
                'ledger_count' => (int) $checkpoints->sum('ledger_count'),
                'unprojectable_legacy_count' => (int) $checkpoints->sum('unprojectable_legacy_count'),
            ];
        });

        DB::afterCommit(static function () use ($evidence): void {
            Log::info('report_change_claim_history_boundary_completed', $evidence);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Change claim history checkpoints are irreversible reporting evidence.');
    }
};
