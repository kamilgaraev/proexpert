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
        Schema::create('holding_reporting_context_coverage', function (Blueprint $table): void {
            $table->string('source_code', 64)->primary();
            $table->timestampTz('coverage_started_at', 6);
            $table->char('evidence_hash', 64);
        });

        Schema::create('holding_contract_dimension_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('contractor_id')->nullable();
            $table->unsignedBigInteger('counterparty_organization_id')->nullable();
            $table->string('contract_status', 32)->nullable();
            $table->string('work_type_category', 64)->nullable();
            $table->decimal('total_amount', 20, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestampTz('observed_at', 6);
            $table->boolean('is_deleted');
            $table->char('evidence_hash', 64);
            $table->index(
                ['contract_id', 'observed_at', 'id'],
                'holding_contract_dimension_timeline',
            );
            $table->index(
                ['organization_id', 'observed_at', 'id'],
                'holding_contract_dimension_scope',
            );
        });

        Schema::create('holding_organization_hierarchy_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('parent_organization_id')->nullable();
            $table->boolean('is_active');
            $table->integer('hierarchy_level')->nullable();
            $table->string('hierarchy_path')->nullable();
            $table->timestampTz('observed_at', 6);
            $table->boolean('is_deleted');
            $table->char('evidence_hash', 64);
            $table->index(
                ['organization_id', 'observed_at', 'id'],
                'holding_hierarchy_event_timeline',
            );
            $table->index(
                ['parent_organization_id', 'observed_at', 'id'],
                'holding_hierarchy_event_parent',
            );
        });

        Schema::create('holding_allocation_context_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->string('allocation_type', 32);
            $table->decimal('allocated_percentage', 20, 8)->nullable();
            $table->boolean('is_resolvable');
            $table->boolean('is_active');
            $table->timestampTz('observed_at', 6);
            $table->boolean('is_deleted');
            $table->char('evidence_hash', 64);
            $table->index(
                ['allocation_id', 'observed_at', 'id'],
                'holding_allocation_context_timeline',
            );
            $table->index(
                ['contract_id', 'project_id', 'observed_at'],
                'holding_allocation_context_scope',
            );
        });

        Schema::table('holding_allocation_fact_versions', function (Blueprint $table): void {
            $table->unsignedBigInteger('contractor_id')->nullable()->after('contract_id');
            $table->string('contract_status', 32)->nullable()->after('contractor_id');
            $table->string('work_type_category', 64)->nullable()->after('contract_status');
            $table->char('contract_dimension_hash', 64)->nullable()->after('work_type_category');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_capture_holding_contract_dimension_v2()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    source_row jsonb;
    observed_value timestamptz;
    deleted_value boolean;
    counterparty_value bigint;
    work_type_value text;
    evidence_payload jsonb;
BEGIN
    source_row := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
    observed_value := clock_timestamp();
    deleted_value := TG_OP = 'DELETE'
        OR COALESCE((source_row->>'deleted_at')::timestamptz, NULL) IS NOT NULL;
    counterparty_value := NULL;
    work_type_value := CASE source_row->>'work_type_category'
        WHEN 'construction' THEN 'general_construction'
        ELSE source_row->>'work_type_category'
    END;

    IF NULLIF(source_row->>'contractor_id', '') IS NOT NULL THEN
        SELECT source_organization_id
        INTO counterparty_value
        FROM contractors
        WHERE id = (source_row->>'contractor_id')::bigint;
    END IF;

    evidence_payload := jsonb_build_object(
        'contract_id', (source_row->>'id')::bigint,
        'organization_id', (source_row->>'organization_id')::bigint,
        'contractor_id', NULLIF(source_row->>'contractor_id', '')::bigint,
        'counterparty_organization_id', counterparty_value,
        'contract_status', source_row->>'status',
        'work_type_category', work_type_value,
        'total_amount', NULLIF(source_row->>'total_amount', '')::numeric,
        'currency', upper(source_row->>'currency'),
        'is_deleted', deleted_value
    );

    INSERT INTO holding_contract_dimension_events (
        contract_id,
        organization_id,
        contractor_id,
        counterparty_organization_id,
        contract_status,
        work_type_category,
        total_amount,
        currency,
        observed_at,
        is_deleted,
        evidence_hash
    ) VALUES (
        (source_row->>'id')::bigint,
        (source_row->>'organization_id')::bigint,
        NULLIF(source_row->>'contractor_id', '')::bigint,
        counterparty_value,
        source_row->>'status',
        work_type_value,
        NULLIF(source_row->>'total_amount', '')::numeric,
        upper(source_row->>'currency'),
        observed_value,
        deleted_value,
        encode(sha256(convert_to(evidence_payload::text || '|' || observed_value::text, 'UTF8')), 'hex')
    );

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_capture_holding_contract_dimension_for_contractor_v2()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    source_row jsonb;
    observed_value timestamptz;
    contract_row record;
    evidence_payload jsonb;
BEGIN
    source_row := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
    observed_value := clock_timestamp();

    FOR contract_row IN
        SELECT
            id,
            organization_id,
            contractor_id,
            status,
            work_type_category,
            total_amount,
            currency,
            deleted_at
        FROM contracts
        WHERE contractor_id = (source_row->>'id')::bigint
    LOOP
        evidence_payload := jsonb_build_object(
            'contract_id', contract_row.id,
            'organization_id', contract_row.organization_id,
            'contractor_id', contract_row.contractor_id,
            'counterparty_organization_id', NULLIF(source_row->>'source_organization_id', '')::bigint,
            'contract_status', contract_row.status,
            'work_type_category', CASE contract_row.work_type_category
                WHEN 'construction' THEN 'general_construction'
                ELSE contract_row.work_type_category
            END,
            'total_amount', contract_row.total_amount,
            'currency', upper(contract_row.currency),
            'is_deleted', contract_row.deleted_at IS NOT NULL
        );

        INSERT INTO holding_contract_dimension_events (
            contract_id,
            organization_id,
            contractor_id,
            counterparty_organization_id,
            contract_status,
            work_type_category,
            total_amount,
            currency,
            observed_at,
            is_deleted,
            evidence_hash
        ) VALUES (
            contract_row.id,
            contract_row.organization_id,
            contract_row.contractor_id,
            NULLIF(source_row->>'source_organization_id', '')::bigint,
            contract_row.status,
            CASE contract_row.work_type_category
                WHEN 'construction' THEN 'general_construction'
                ELSE contract_row.work_type_category
            END,
            contract_row.total_amount,
            upper(contract_row.currency),
            observed_value,
            contract_row.deleted_at IS NOT NULL,
            encode(sha256(convert_to(evidence_payload::text || '|' || observed_value::text, 'UTF8')), 'hex')
        );
    END LOOP;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_capture_holding_hierarchy_v2()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    source_row jsonb;
    observed_value timestamptz;
    deleted_value boolean;
    evidence_payload jsonb;
BEGIN
    source_row := CASE WHEN TG_OP = 'DELETE' THEN to_jsonb(OLD) ELSE to_jsonb(NEW) END;
    observed_value := clock_timestamp();
    deleted_value := TG_OP = 'DELETE'
        OR COALESCE((source_row->>'deleted_at')::timestamptz, NULL) IS NOT NULL;
    evidence_payload := jsonb_build_object(
        'organization_id', (source_row->>'id')::bigint,
        'parent_organization_id', NULLIF(source_row->>'parent_organization_id', '')::bigint,
        'is_active', COALESCE((source_row->>'is_active')::boolean, false),
        'hierarchy_level', NULLIF(source_row->>'hierarchy_level', '')::integer,
        'hierarchy_path', source_row->>'hierarchy_path',
        'is_deleted', deleted_value
    );

    INSERT INTO holding_organization_hierarchy_events (
        organization_id,
        parent_organization_id,
        is_active,
        hierarchy_level,
        hierarchy_path,
        observed_at,
        is_deleted,
        evidence_hash
    ) VALUES (
        (source_row->>'id')::bigint,
        NULLIF(source_row->>'parent_organization_id', '')::bigint,
        COALESCE((source_row->>'is_active')::boolean, false),
        NULLIF(source_row->>'hierarchy_level', '')::integer,
        source_row->>'hierarchy_path',
        observed_value,
        deleted_value,
        encode(sha256(convert_to(evidence_payload::text || '|' || observed_value::text, 'UTF8')), 'hex')
    );

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$
SQL);

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
        resolved_percentage := NULL;

        IF allocation_row.deleted_at IS NULL
            AND allocation_row.contract_deleted_at IS NULL
            AND allocation_row.is_active THEN
            CASE allocation_row.allocation_type
                WHEN 'fixed' THEN
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
        END IF;

        IF resolved_percentage IS NULL AND (
            allocation_row.deleted_at IS NOT NULL
            OR allocation_row.contract_deleted_at IS NOT NULL
            OR NOT allocation_row.is_active
        ) THEN
            resolved_percentage := 0;
        END IF;
        IF resolved_percentage IS NOT NULL THEN
            resolved_percentage := round(resolved_percentage, 8);
        END IF;
        resolvable_value := resolved_percentage IS NOT NULL
            AND resolved_percentage >= 0
            AND resolved_percentage <= 100;
        evidence_payload := jsonb_build_object(
            'allocation_id', allocation_row.id,
            'contract_id', allocation_row.contract_id,
            'organization_id', allocation_row.organization_id,
            'project_id', allocation_row.project_id,
            'allocation_type', allocation_row.allocation_type,
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
CREATE OR REPLACE FUNCTION most_capture_holding_allocation_context_checkpoint_v1(
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
CREATE OR REPLACE FUNCTION most_recapture_holding_allocation_context_v1()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    old_contract_id bigint;
    new_contract_id bigint;
    observed_value timestamptz;
BEGIN
    observed_value := clock_timestamp();
    old_contract_id := CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE OLD.contract_id END;
    new_contract_id := CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE NEW.contract_id END;

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
CREATE OR REPLACE FUNCTION most_recapture_holding_allocation_context_from_contract_v1()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    observed_value timestamptz;
BEGIN
    observed_value := clock_timestamp();
    IF TG_OP <> 'INSERT' THEN
        PERFORM most_capture_holding_allocation_context_for_contract_v1(OLD.id, observed_value);
    END IF;
    IF TG_OP <> 'DELETE' AND (TG_OP = 'INSERT' OR NEW.id IS DISTINCT FROM OLD.id) THEN
        PERFORM most_capture_holding_allocation_context_for_contract_v1(NEW.id, observed_value);
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END;
$$
SQL);

        DB::statement(
            'CREATE TRIGGER contracts_holding_dimension_evidence '
            .'AFTER INSERT OR DELETE OR UPDATE OF organization_id, contractor_id, status, '
            .'work_type_category, total_amount, currency, deleted_at '
            .'ON contracts FOR EACH ROW '
            .'EXECUTE FUNCTION most_capture_holding_contract_dimension_v2()',
        );
        DB::statement(
            'CREATE TRIGGER contractors_holding_dimension_evidence '
            .'AFTER INSERT OR DELETE OR UPDATE OF source_organization_id, deleted_at ON contractors '
            .'FOR EACH ROW EXECUTE FUNCTION most_capture_holding_contract_dimension_for_contractor_v2()',
        );
        DB::statement(
            'CREATE TRIGGER organizations_holding_hierarchy_evidence '
            .'AFTER INSERT OR DELETE OR UPDATE OF parent_organization_id, is_active, hierarchy_level, '
            .'hierarchy_path, deleted_at ON organizations FOR EACH ROW '
            .'EXECUTE FUNCTION most_capture_holding_hierarchy_v2()',
        );
        DB::statement(
            'CREATE TRIGGER allocations_holding_context_evidence '
            .'AFTER INSERT OR DELETE OR UPDATE OF contract_id, project_id, allocation_type, allocated_amount, '
            .'allocated_percentage, custom_formula, is_active, deleted_at ON contract_project_allocations '
            .'FOR EACH ROW EXECUTE FUNCTION most_capture_holding_allocation_context_v1()',
        );
        DB::statement(
            'CREATE TRIGGER contract_project_holding_context_evidence '
            .'AFTER INSERT OR DELETE OR UPDATE OF contract_id, project_id ON contract_project '
            .'FOR EACH ROW EXECUTE FUNCTION most_recapture_holding_allocation_context_v1()',
        );
        DB::statement(
            'CREATE TRIGGER performance_acts_holding_context_evidence '
            .'AFTER INSERT OR DELETE OR UPDATE OF contract_id, project_id, amount, is_approved '
            .'ON contract_performance_acts FOR EACH ROW '
            .'EXECUTE FUNCTION most_recapture_holding_allocation_context_v1()',
        );
        DB::statement(
            'CREATE TRIGGER contracts_holding_allocation_context_evidence '
            .'AFTER INSERT OR DELETE OR UPDATE OF total_amount, is_multi_project, deleted_at ON contracts '
            .'FOR EACH ROW EXECUTE FUNCTION most_recapture_holding_allocation_context_from_contract_v1()',
        );

        $checkpoint = DB::selectOne('SELECT clock_timestamp() AS value');
        $checkpointAt = is_object($checkpoint) ? (string) ($checkpoint->value ?? '') : '';
        if ($checkpointAt === '') {
            throw new RuntimeException('holding_reporting_context_checkpoint_unavailable');
        }

        DB::statement(<<<'SQL'
WITH checkpoint AS (SELECT ?::timestamptz AS observed_at)
INSERT INTO holding_contract_dimension_events (
    contract_id,
    organization_id,
    contractor_id,
    counterparty_organization_id,
    contract_status,
    work_type_category,
    total_amount,
    currency,
    observed_at,
    is_deleted,
    evidence_hash
)
SELECT
    contracts.id,
    contracts.organization_id,
    contracts.contractor_id,
    contractors.source_organization_id,
    contracts.status,
    CASE contracts.work_type_category
        WHEN 'construction' THEN 'general_construction'
        ELSE contracts.work_type_category
    END,
    contracts.total_amount,
    upper(contracts.currency),
    checkpoint.observed_at,
    contracts.deleted_at IS NOT NULL,
    encode(sha256(convert_to(
        jsonb_build_object(
            'contract_id', contracts.id,
            'organization_id', contracts.organization_id,
            'contractor_id', contracts.contractor_id,
            'counterparty_organization_id', contractors.source_organization_id,
            'contract_status', contracts.status,
            'work_type_category', CASE contracts.work_type_category
                WHEN 'construction' THEN 'general_construction'
                ELSE contracts.work_type_category
            END,
            'total_amount', contracts.total_amount,
            'currency', upper(contracts.currency),
            'is_deleted', contracts.deleted_at IS NOT NULL
        )::text || '|' || checkpoint.observed_at::text,
        'UTF8'
    )), 'hex')
FROM contracts
LEFT JOIN contractors ON contractors.id = contracts.contractor_id
CROSS JOIN checkpoint
SQL, [$checkpointAt]);

        DB::statement(<<<'SQL'
WITH checkpoint AS (SELECT ?::timestamptz AS observed_at)
INSERT INTO holding_organization_hierarchy_events (
    organization_id,
    parent_organization_id,
    is_active,
    hierarchy_level,
    hierarchy_path,
    observed_at,
    is_deleted,
    evidence_hash
)
SELECT
    organizations.id,
    organizations.parent_organization_id,
    organizations.is_active,
    organizations.hierarchy_level,
    organizations.hierarchy_path,
    checkpoint.observed_at,
    organizations.deleted_at IS NOT NULL,
    encode(sha256(convert_to(
        jsonb_build_object(
            'organization_id', organizations.id,
            'parent_organization_id', organizations.parent_organization_id,
            'is_active', organizations.is_active,
            'hierarchy_level', organizations.hierarchy_level,
            'hierarchy_path', organizations.hierarchy_path,
            'is_deleted', organizations.deleted_at IS NOT NULL
        )::text || '|' || checkpoint.observed_at::text,
        'UTF8'
    )), 'hex')
FROM organizations
CROSS JOIN checkpoint
SQL, [$checkpointAt]);

        DB::selectOne(
            'SELECT most_capture_holding_allocation_context_checkpoint_v1(?::timestamptz)',
            [$checkpointAt],
        );
        DB::statement('DROP FUNCTION most_capture_holding_allocation_context_checkpoint_v1(timestamptz)');

        foreach ([
            'contract_dimensions',
            'organization_hierarchy',
            'allocation_dimensions',
        ] as $sourceCode) {
            DB::table('holding_reporting_context_coverage')->insert([
                'source_code' => $sourceCode,
                'coverage_started_at' => $checkpointAt,
                'evidence_hash' => hash(
                    'sha256',
                    'holding-reporting-context.v1:'.$sourceCode.':'.$checkpointAt,
                ),
            ]);
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_prevent_holding_reporting_context_mutation_v1()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'holding_reporting_context_evidence_is_immutable';
END;
$$
SQL);

        foreach ([
            'holding_reporting_context_coverage',
            'holding_contract_dimension_events',
            'holding_organization_hierarchy_events',
            'holding_allocation_context_events',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION most_prevent_holding_reporting_context_mutation_v1()',
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS contracts_holding_dimension_evidence ON contracts');
        DB::statement('DROP TRIGGER IF EXISTS contractors_holding_dimension_evidence ON contractors');
        DB::statement('DROP TRIGGER IF EXISTS organizations_holding_hierarchy_evidence ON organizations');
        DB::statement('DROP TRIGGER IF EXISTS allocations_holding_context_evidence ON contract_project_allocations');
        DB::statement('DROP TRIGGER IF EXISTS contract_project_holding_context_evidence ON contract_project');
        DB::statement('DROP TRIGGER IF EXISTS performance_acts_holding_context_evidence ON contract_performance_acts');
        DB::statement('DROP TRIGGER IF EXISTS contracts_holding_allocation_context_evidence ON contracts');
        DB::statement('DROP FUNCTION IF EXISTS most_capture_holding_contract_dimension_v2()');
        DB::statement('DROP FUNCTION IF EXISTS most_capture_holding_contract_dimension_for_contractor_v2()');
        DB::statement('DROP FUNCTION IF EXISTS most_capture_holding_hierarchy_v2()');
        DB::statement('DROP FUNCTION IF EXISTS most_capture_holding_allocation_context_v1()');
        DB::statement('DROP FUNCTION IF EXISTS most_recapture_holding_allocation_context_v1()');
        DB::statement('DROP FUNCTION IF EXISTS most_recapture_holding_allocation_context_from_contract_v1()');
        DB::statement('DROP FUNCTION IF EXISTS most_capture_holding_allocation_context_for_contract_v1(bigint, timestamptz)');

        foreach ([
            'holding_reporting_context_coverage',
            'holding_contract_dimension_events',
            'holding_organization_hierarchy_events',
            'holding_allocation_context_events',
        ] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS most_prevent_holding_reporting_context_mutation_v1()');

        Schema::table('holding_allocation_fact_versions', function (Blueprint $table): void {
            $table->dropColumn([
                'contractor_id',
                'contract_status',
                'work_type_category',
                'contract_dimension_hash',
            ]);
        });
        Schema::dropIfExists('holding_allocation_context_events');
        Schema::dropIfExists('holding_organization_hierarchy_events');
        Schema::dropIfExists('holding_contract_dimension_events');
        Schema::dropIfExists('holding_reporting_context_coverage');
    }
};
