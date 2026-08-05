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
        Schema::table('holding_allocation_context_events', function (Blueprint $table): void {
            $table->decimal('allocated_amount', 20, 2)->nullable()->after('allocation_type');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_capture_holding_allocation_context_for_contract_v1(
    contract_key bigint,
    observed_value timestamptz
)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    allocation_row record;
    project_count bigint;
    total_accepted numeric;
    project_accepted numeric;
    resolved_amount numeric;
    resolved_percentage numeric;
    resolvable_value boolean;
    evidence_payload jsonb;
BEGIN
    SELECT COUNT(DISTINCT project_id)
    INTO project_count
    FROM contract_project
    WHERE contract_id = contract_key;

    SELECT COALESCE(SUM(amount) FILTER (WHERE is_approved), 0)
    INTO total_accepted
    FROM contract_performance_acts
    WHERE contract_id = contract_key;

    FOR allocation_row IN
        SELECT
            allocations.id,
            allocations.contract_id,
            contracts.organization_id,
            allocations.project_id,
            allocations.allocation_type,
            allocations.allocated_amount,
            allocations.allocated_percentage,
            allocations.custom_formula,
            allocations.is_active,
            allocations.deleted_at,
            contracts.total_amount,
            contracts.is_multi_project,
            contracts.deleted_at AS contract_deleted_at
        FROM contract_project_allocations AS allocations
        JOIN contracts ON contracts.id = allocations.contract_id
        WHERE allocations.contract_id = contract_key
    LOOP
        resolved_amount := NULL;
        resolved_percentage := NULL;

        IF allocation_row.deleted_at IS NULL
            AND allocation_row.contract_deleted_at IS NULL
            AND allocation_row.is_active THEN
            CASE allocation_row.allocation_type
                WHEN 'fixed' THEN
                    resolved_amount := allocation_row.allocated_amount;
                    IF allocation_row.total_amount > 0 AND allocation_row.allocated_amount >= 0 THEN
                        resolved_percentage := allocation_row.allocated_amount * 100 / allocation_row.total_amount;
                    END IF;
                WHEN 'percentage' THEN
                    resolved_percentage := allocation_row.allocated_percentage;
                WHEN 'auto' THEN
                    IF NOT allocation_row.is_multi_project THEN
                        resolved_percentage := 100;
                    ELSIF total_accepted > 0 THEN
                        SELECT COALESCE(SUM(amount) FILTER (WHERE is_approved), 0)
                        INTO project_accepted
                        FROM contract_performance_acts
                        WHERE contract_id = contract_key
                            AND project_id = allocation_row.project_id;
                        resolved_percentage := project_accepted * 100 / total_accepted;
                    ELSIF project_count > 0 THEN
                        resolved_percentage := 100::numeric / project_count;
                    END IF;
                WHEN 'custom' THEN
                    IF jsonb_typeof(allocation_row.custom_formula::jsonb) = 'object'
                        AND allocation_row.custom_formula::jsonb->>'type' = 'coefficient' THEN
                        IF NOT allocation_row.custom_formula::jsonb ? 'coefficient' THEN
                            resolved_percentage := 100;
                        ELSIF (allocation_row.custom_formula::jsonb->>'coefficient') ~ '^-?[0-9]+([.][0-9]+)?$' THEN
                            resolved_percentage := (allocation_row.custom_formula::jsonb->>'coefficient')::numeric * 100;
                        END IF;
                    END IF;
            END CASE;
        ELSE
            resolved_amount := CASE WHEN allocation_row.allocation_type = 'fixed' THEN 0 ELSE NULL END;
            resolved_percentage := 0;
        END IF;

        IF resolved_percentage IS NOT NULL THEN
            resolved_percentage := round(resolved_percentage, 8);
        END IF;
        resolvable_value := CASE allocation_row.allocation_type
            WHEN 'fixed' THEN resolved_amount IS NOT NULL AND resolved_amount >= 0
            ELSE resolved_percentage IS NOT NULL
                AND resolved_percentage >= 0
                AND resolved_percentage <= 100
        END;
        evidence_payload := jsonb_build_object(
            'allocation_id', allocation_row.id,
            'contract_id', allocation_row.contract_id,
            'organization_id', allocation_row.organization_id,
            'project_id', allocation_row.project_id,
            'allocation_type', allocation_row.allocation_type,
            'allocated_amount', resolved_amount,
            'allocated_percentage', resolved_percentage,
            'is_resolvable', resolvable_value,
            'is_active', allocation_row.is_active,
            'is_deleted', allocation_row.deleted_at IS NOT NULL
                OR allocation_row.contract_deleted_at IS NOT NULL
        );

        INSERT INTO holding_allocation_context_events (
            allocation_id,
            contract_id,
            organization_id,
            project_id,
            allocation_type,
            allocated_amount,
            allocated_percentage,
            is_resolvable,
            is_active,
            observed_at,
            is_deleted,
            evidence_hash
        ) VALUES (
            allocation_row.id,
            allocation_row.contract_id,
            allocation_row.organization_id,
            allocation_row.project_id,
            allocation_row.allocation_type,
            resolved_amount,
            resolved_percentage,
            resolvable_value,
            allocation_row.is_active,
            observed_value,
            allocation_row.deleted_at IS NOT NULL OR allocation_row.contract_deleted_at IS NOT NULL,
            encode(sha256(convert_to(evidence_payload::text || '|' || observed_value::text, 'UTF8')), 'hex')
        );
    END LOOP;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_capture_holding_allocation_context_v1()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_contract_id bigint;
    new_contract_id bigint;
    organization_value bigint;
    observed_value timestamptz;
    evidence_payload jsonb;
BEGIN
    observed_value := clock_timestamp();
    old_contract_id := CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE OLD.contract_id END;
    new_contract_id := CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE NEW.contract_id END;

    IF TG_OP = 'DELETE' THEN
        SELECT organization_id
        INTO organization_value
        FROM contracts
        WHERE id = OLD.contract_id;
        IF organization_value IS NULL THEN
            SELECT organization_id
            INTO organization_value
            FROM holding_allocation_context_events
            WHERE allocation_id = OLD.id
            ORDER BY observed_at DESC, id DESC
            LIMIT 1;
        END IF;
        evidence_payload := jsonb_build_object(
            'allocation_id', OLD.id,
            'contract_id', OLD.contract_id,
            'organization_id', organization_value,
            'project_id', OLD.project_id,
            'allocation_type', OLD.allocation_type,
            'allocated_amount', CASE WHEN OLD.allocation_type = 'fixed' THEN 0 ELSE NULL END,
            'allocated_percentage', 0,
            'is_resolvable', true,
            'is_active', false,
            'is_deleted', true
        );
        INSERT INTO holding_allocation_context_events (
            allocation_id,
            contract_id,
            organization_id,
            project_id,
            allocation_type,
            allocated_amount,
            allocated_percentage,
            is_resolvable,
            is_active,
            observed_at,
            is_deleted,
            evidence_hash
        ) VALUES (
            OLD.id,
            OLD.contract_id,
            organization_value,
            OLD.project_id,
            OLD.allocation_type,
            CASE WHEN OLD.allocation_type = 'fixed' THEN 0 ELSE NULL END,
            0,
            true,
            false,
            observed_value,
            true,
            encode(sha256(convert_to(evidence_payload::text || '|' || observed_value::text, 'UTF8')), 'hex')
        );
    END IF;

    IF old_contract_id IS NOT NULL THEN
        PERFORM most_capture_holding_allocation_context_for_contract_v1(old_contract_id, observed_value);
    END IF;
    IF new_contract_id IS NOT NULL AND new_contract_id IS DISTINCT FROM old_contract_id THEN
        PERFORM most_capture_holding_allocation_context_for_contract_v1(new_contract_id, observed_value);
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_capture_holding_fixed_allocation_checkpoint_v1(
    observed_value timestamptz
)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    contract_key bigint;
BEGIN
    FOR contract_key IN SELECT id FROM contracts LOOP
        PERFORM most_capture_holding_allocation_context_for_contract_v1(contract_key, observed_value);
    END LOOP;
END;
$$
SQL);

        $checkpoint = DB::selectOne('SELECT clock_timestamp() AS value');
        $checkpointAt = is_object($checkpoint) ? (string) ($checkpoint->value ?? '') : '';
        if ($checkpointAt === '') {
            throw new RuntimeException('holding_fixed_allocation_checkpoint_unavailable');
        }

        DB::selectOne(
            'SELECT most_capture_holding_fixed_allocation_checkpoint_v1(?::timestamptz)',
            [$checkpointAt],
        );
        DB::statement('DROP FUNCTION most_capture_holding_fixed_allocation_checkpoint_v1(timestamptz)');
        DB::table('holding_reporting_context_coverage')->insert([
            'source_code' => 'allocation_amount_dimensions',
            'coverage_started_at' => $checkpointAt,
            'evidence_hash' => hash(
                'sha256',
                'holding-reporting-context.v1:allocation_amount_dimensions:'.$checkpointAt,
            ),
        ]);
    }

    public function down(): void
    {
    }
};
