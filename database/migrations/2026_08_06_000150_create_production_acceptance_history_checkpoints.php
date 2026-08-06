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
            Schema::create('production_acceptance_history_checkpoints', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('organization_id');
                $table->dateTimeTz('completed_at', 6);
                $table->unsignedBigInteger('excluded_legacy_act_count');
                $table->unsignedBigInteger('performance_act_watermark_id');
                $table->char('legacy_act_set_hash', 64);
                $table->unsignedBigInteger('owner_version_count');
                $table->unsignedBigInteger('owner_version_watermark_id');
                $table->char('owner_version_set_hash', 64);
                $table->unsignedBigInteger('owner_member_count');
                $table->unsignedBigInteger('owner_member_watermark_id');
                $table->char('owner_member_set_hash', 64);
                $table->unsignedBigInteger('event_count');
                $table->unsignedBigInteger('event_watermark_id');
                $table->char('event_set_hash', 64);
                $table->unsignedBigInteger('unprovable_legacy_count');
                $table->unsignedBigInteger('backfill_ledger_watermark_id');
                $table->char('backfill_ledger_set_hash', 64);
                $table->char('source_hash', 64);
                $table->dateTimeTz('created_at', 6);
                $table->dateTimeTz('updated_at', 6);

                $table->unique('organization_id', 'production_acceptance_history_checkpoint_org_unique');
            });

            DB::statement('LOCK TABLE organizations, contracts, contract_performance_acts, production_acceptance_owner_versions, production_acceptance_owner_members, production_acceptance_events, production_acceptance_backfill_ledger IN SHARE ROW EXCLUSIVE MODE');
            $ownerMemberDriftCount = (int) DB::scalar(<<<'SQL'
SELECT COUNT(*)
FROM production_acceptance_owner_members AS member
INNER JOIN production_acceptance_owner_versions AS owner
    ON owner.id = member.owner_version_id
WHERE member.organization_id IS DISTINCT FROM owner.organization_id
   OR member.project_id IS DISTINCT FROM owner.project_id
   OR member.performance_act_id IS DISTINCT FROM owner.performance_act_id
SQL);
            if ($ownerMemberDriftCount !== 0) {
                throw new RuntimeException('Production acceptance owner member scope drift detected.');
            }
            DB::statement(<<<'SQL'
WITH boundary AS MATERIALIZED (
    SELECT clock_timestamp()::timestamptz(6) AS completed_at
), legacy_sources AS MATERIALIZED (
    SELECT
        contracts.organization_id,
        COUNT(*) FILTER (
            WHERE (
                contract_performance_acts.signed_at IS NOT NULL
                AND contract_performance_acts.signed_at < boundary.completed_at
            ) OR (
                contract_performance_acts.approval_date IS NOT NULL
                AND contract_performance_acts.approval_date
                    <= (boundary.completed_at AT TIME ZONE 'UTC')::date
            )
        )::bigint AS excluded_legacy_act_count,
        COALESCE(MAX(contract_performance_acts.id), 0)::bigint AS performance_act_watermark_id,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_object(
                'id', contract_performance_acts.id,
                'approval_date', COALESCE(contract_performance_acts.approval_date::text, ''),
                'signed_at', CASE
                    WHEN contract_performance_acts.signed_at IS NULL THEN ''
                    ELSE to_char(
                        contract_performance_acts.signed_at AT TIME ZONE 'UTC',
                        'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'
                    )
                END
            )::text, 'UTF8')), 'hex'),
            '' ORDER BY contract_performance_acts.id
        ) FILTER (
            WHERE (
                contract_performance_acts.signed_at IS NOT NULL
                AND contract_performance_acts.signed_at < boundary.completed_at
            ) OR (
                contract_performance_acts.approval_date IS NOT NULL
                AND contract_performance_acts.approval_date
                    <= (boundary.completed_at AT TIME ZONE 'UTC')::date
            )
        ), ''), 'UTF8')), 'hex') AS legacy_act_set_hash
    FROM contract_performance_acts
    INNER JOIN contracts ON contracts.id = contract_performance_acts.contract_id
    CROSS JOIN boundary
    GROUP BY contracts.organization_id
), owner_sources AS MATERIALIZED (
    SELECT
        organization_id,
        COUNT(*)::bigint AS owner_version_count,
        COALESCE(MAX(id), 0)::bigint AS owner_version_watermark_id,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(
                jsonb_build_array(id, source_hash)::text,
                'UTF8'
            )), 'hex'),
            '' ORDER BY id
        ), ''), 'UTF8')), 'hex') AS owner_version_set_hash
    FROM production_acceptance_owner_versions
    GROUP BY organization_id
), owner_member_sources AS MATERIALIZED (
    SELECT
        owner.organization_id,
        COUNT(*)::bigint AS owner_member_count,
        COALESCE(MAX(member.id), 0)::bigint AS owner_member_watermark_id,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(jsonb_build_object(
                'id', member.id,
                'owner_version_id', member.owner_version_id,
                'owner_organization_id', owner.organization_id,
                'owner_project_id', owner.project_id,
                'owner_performance_act_id', owner.performance_act_id,
                'member_organization_id', member.organization_id,
                'member_project_id', member.project_id,
                'member_performance_act_id', member.performance_act_id,
                'source_line_type', member.source_line_type,
                'source_line_id', member.source_line_id,
                'work_id', member.work_id,
                'contractor_id', member.contractor_id,
                'unit_code', member.unit_code,
                'zone', member.zone
            )::text, 'UTF8')), 'hex'),
            '' ORDER BY member.id
        ), ''), 'UTF8')), 'hex') AS owner_member_set_hash
    FROM production_acceptance_owner_members AS member
    INNER JOIN production_acceptance_owner_versions AS owner
        ON owner.id = member.owner_version_id
    GROUP BY owner.organization_id
), event_sources AS MATERIALIZED (
    SELECT
        organization_id,
        COUNT(*)::bigint AS event_count,
        COALESCE(MAX(id), 0)::bigint AS event_watermark_id,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(
                jsonb_build_array(id, source_hash)::text,
                'UTF8'
            )), 'hex'),
            '' ORDER BY id
        ), ''), 'UTF8')), 'hex') AS event_set_hash
    FROM production_acceptance_events
    GROUP BY organization_id
), ledger_sources AS MATERIALIZED (
    SELECT
        organization_id,
        COUNT(*) FILTER (WHERE status = 'unprovable')::bigint AS unprovable_legacy_count,
        COALESCE(MAX(id), 0)::bigint AS backfill_ledger_watermark_id,
        encode(sha256(convert_to(COALESCE(string_agg(
            encode(sha256(convert_to(
                jsonb_build_array(id, source_hash)::text,
                'UTF8'
            )), 'hex'),
            '' ORDER BY id
        ), ''), 'UTF8')), 'hex') AS backfill_ledger_set_hash
    FROM production_acceptance_backfill_ledger
    GROUP BY organization_id
), checkpoint_counts AS (
    SELECT
        organizations.id AS organization_id,
        boundary.completed_at,
        COALESCE(legacy_sources.excluded_legacy_act_count, 0) AS excluded_legacy_act_count,
        COALESCE(legacy_sources.performance_act_watermark_id, 0) AS performance_act_watermark_id,
        COALESCE(
            legacy_sources.legacy_act_set_hash,
            encode(sha256(convert_to('', 'UTF8')), 'hex')
        ) AS legacy_act_set_hash,
        COALESCE(owner_sources.owner_version_count, 0) AS owner_version_count,
        COALESCE(owner_sources.owner_version_watermark_id, 0) AS owner_version_watermark_id,
        COALESCE(
            owner_sources.owner_version_set_hash,
            encode(sha256(convert_to('', 'UTF8')), 'hex')
        ) AS owner_version_set_hash,
        COALESCE(owner_member_sources.owner_member_count, 0) AS owner_member_count,
        COALESCE(owner_member_sources.owner_member_watermark_id, 0) AS owner_member_watermark_id,
        COALESCE(
            owner_member_sources.owner_member_set_hash,
            encode(sha256(convert_to('', 'UTF8')), 'hex')
        ) AS owner_member_set_hash,
        COALESCE(event_sources.event_count, 0) AS event_count,
        COALESCE(event_sources.event_watermark_id, 0) AS event_watermark_id,
        COALESCE(
            event_sources.event_set_hash,
            encode(sha256(convert_to('', 'UTF8')), 'hex')
        ) AS event_set_hash,
        COALESCE(ledger_sources.unprovable_legacy_count, 0) AS unprovable_legacy_count,
        COALESCE(ledger_sources.backfill_ledger_watermark_id, 0) AS backfill_ledger_watermark_id,
        COALESCE(
            ledger_sources.backfill_ledger_set_hash,
            encode(sha256(convert_to('', 'UTF8')), 'hex')
        ) AS backfill_ledger_set_hash
    FROM organizations
    CROSS JOIN boundary
    LEFT JOIN legacy_sources ON legacy_sources.organization_id = organizations.id
    LEFT JOIN owner_sources ON owner_sources.organization_id = organizations.id
    LEFT JOIN owner_member_sources ON owner_member_sources.organization_id = organizations.id
    LEFT JOIN event_sources ON event_sources.organization_id = organizations.id
    LEFT JOIN ledger_sources ON ledger_sources.organization_id = organizations.id
)
INSERT INTO production_acceptance_history_checkpoints (
    organization_id,
    completed_at,
    excluded_legacy_act_count,
    performance_act_watermark_id,
    legacy_act_set_hash,
    owner_version_count,
    owner_version_watermark_id,
    owner_version_set_hash,
    owner_member_count,
    owner_member_watermark_id,
    owner_member_set_hash,
    event_count,
    event_watermark_id,
    event_set_hash,
    unprovable_legacy_count,
    backfill_ledger_watermark_id,
    backfill_ledger_set_hash,
    source_hash,
    created_at,
    updated_at
)
SELECT
    organization_id,
    completed_at,
    excluded_legacy_act_count,
    performance_act_watermark_id,
    legacy_act_set_hash,
    owner_version_count,
    owner_version_watermark_id,
    owner_version_set_hash,
    owner_member_count,
    owner_member_watermark_id,
    owner_member_set_hash,
    event_count,
    event_watermark_id,
    event_set_hash,
    unprovable_legacy_count,
    backfill_ledger_watermark_id,
    backfill_ledger_set_hash,
    encode(sha256(convert_to(jsonb_build_object(
        'organization_id', organization_id,
        'completed_at', to_char(
            completed_at AT TIME ZONE 'UTC',
            'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'
        ),
        'excluded_legacy_act_count', excluded_legacy_act_count,
        'performance_act_watermark_id', performance_act_watermark_id,
        'legacy_act_set_hash', legacy_act_set_hash,
        'owner_version_count', owner_version_count,
        'owner_version_watermark_id', owner_version_watermark_id,
        'owner_version_set_hash', owner_version_set_hash,
        'owner_member_count', owner_member_count,
        'owner_member_watermark_id', owner_member_watermark_id,
        'owner_member_set_hash', owner_member_set_hash,
        'event_count', event_count,
        'event_watermark_id', event_watermark_id,
        'event_set_hash', event_set_hash,
        'unprovable_legacy_count', unprovable_legacy_count,
        'backfill_ledger_watermark_id', backfill_ledger_watermark_id,
        'backfill_ledger_set_hash', backfill_ledger_set_hash
    )::text, 'UTF8')), 'hex'),
    completed_at,
    completed_at
FROM checkpoint_counts
SQL);

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION production_acceptance_owner_member_scope_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    owner_organization_id bigint;
    owner_project_id bigint;
    owner_performance_act_id bigint;
BEGIN
    SELECT organization_id, project_id, performance_act_id
    INTO owner_organization_id, owner_project_id, owner_performance_act_id
    FROM production_acceptance_owner_versions
    WHERE id = NEW.owner_version_id;

    IF NOT FOUND
        OR NEW.organization_id IS DISTINCT FROM owner_organization_id
        OR NEW.project_id IS DISTINCT FROM owner_project_id
        OR NEW.performance_act_id IS DISTINCT FROM owner_performance_act_id
    THEN
        RAISE EXCEPTION 'production_acceptance_owner_member_scope_mismatch'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS production_acceptance_owner_members_scope_guard
ON production_acceptance_owner_members;
CREATE TRIGGER production_acceptance_owner_members_scope_guard
BEFORE INSERT ON production_acceptance_owner_members
FOR EACH ROW EXECUTE FUNCTION production_acceptance_owner_member_scope_guard();

CREATE OR REPLACE FUNCTION most_seed_production_acceptance_history_checkpoint_v1()
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
        'completed_at', to_char(
            checkpoint_at AT TIME ZONE 'UTC',
            'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'
        ),
        'excluded_legacy_act_count', 0,
        'performance_act_watermark_id', 0,
        'legacy_act_set_hash', empty_set_hash,
        'owner_version_count', 0,
        'owner_version_watermark_id', 0,
        'owner_version_set_hash', empty_set_hash,
        'owner_member_count', 0,
        'owner_member_watermark_id', 0,
        'owner_member_set_hash', empty_set_hash,
        'event_count', 0,
        'event_watermark_id', 0,
        'event_set_hash', empty_set_hash,
        'unprovable_legacy_count', 0,
        'backfill_ledger_watermark_id', 0,
        'backfill_ledger_set_hash', empty_set_hash
    )::text, 'UTF8')), 'hex');

    INSERT INTO production_acceptance_history_checkpoints (
        organization_id,
        completed_at,
        excluded_legacy_act_count,
        performance_act_watermark_id,
        legacy_act_set_hash,
        owner_version_count,
        owner_version_watermark_id,
        owner_version_set_hash,
        owner_member_count,
        owner_member_watermark_id,
        owner_member_set_hash,
        event_count,
        event_watermark_id,
        event_set_hash,
        unprovable_legacy_count,
        backfill_ledger_watermark_id,
        backfill_ledger_set_hash,
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
        checkpoint_hash,
        checkpoint_at,
        checkpoint_at
    )
    ON CONFLICT (organization_id) DO NOTHING;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS most_seed_production_acceptance_history_checkpoint_v1 ON organizations;
CREATE TRIGGER most_seed_production_acceptance_history_checkpoint_v1
AFTER INSERT ON organizations
FOR EACH ROW EXECUTE FUNCTION most_seed_production_acceptance_history_checkpoint_v1();

CREATE TRIGGER production_acceptance_history_checkpoints_append_only
BEFORE UPDATE OR DELETE ON production_acceptance_history_checkpoints
FOR EACH ROW EXECUTE FUNCTION production_acceptance_event_immutable_guard();
SQL);

            $organizationCount = DB::table('organizations')->count();
            $checkpoints = DB::table('production_acceptance_history_checkpoints')
                ->orderBy('organization_id')
                ->get();
            if ($checkpoints->count() !== $organizationCount) {
                throw new RuntimeException('Production acceptance checkpoint organization coverage mismatch.');
            }
            $identities = [];
            foreach ($checkpoints as $checkpoint) {
                foreach ([
                    'backfill_ledger_set_hash',
                    'event_set_hash',
                    'legacy_act_set_hash',
                    'owner_member_set_hash',
                    'owner_version_set_hash',
                    'source_hash',
                ] as $hashField) {
                    if (! is_string($checkpoint->{$hashField})
                        || preg_match('/^[a-f0-9]{64}$/D', $checkpoint->{$hashField}) !== 1) {
                        throw new RuntimeException('Production acceptance checkpoint hash is invalid.');
                    }
                }
                $identities[] = (int) $checkpoint->organization_id.':'.$checkpoint->source_hash;
            }

            return [
                'checkpoint_count' => $checkpoints->count(),
                'content_hash' => hash('sha256', implode('|', $identities)),
                'event_count' => (int) $checkpoints->sum('event_count'),
                'excluded_legacy_act_count' => (int) $checkpoints->sum('excluded_legacy_act_count'),
                'owner_version_count' => (int) $checkpoints->sum('owner_version_count'),
                'owner_member_count' => (int) $checkpoints->sum('owner_member_count'),
                'unprovable_legacy_count' => (int) $checkpoints->sum('unprovable_legacy_count'),
            ];
        });

        DB::afterCommit(static function () use ($evidence): void {
            Log::info('report_accepted_production_history_boundary_completed', $evidence);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Production acceptance history checkpoints are irreversible reporting evidence.',
        );
    }
};
