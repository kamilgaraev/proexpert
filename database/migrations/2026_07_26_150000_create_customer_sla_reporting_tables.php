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
        Schema::create('customer_membership_history', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('membership_kind', 32);
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('role', 64)->nullable();
            $table->boolean('is_active');
            $table->timestampTz('valid_from', 6);
            $table->timestampTz('valid_to', 6);
            $table->char('evidence_hash', 64);

            $table->unique('evidence_hash', 'customer_membership_history_evidence_unique');
            $table->index(
                ['membership_kind', 'membership_id', 'valid_from', 'valid_to'],
                'customer_membership_history_interval_idx',
            );
            $table->index(
                ['organization_id', 'user_id', 'project_id', 'valid_from', 'valid_to'],
                'customer_membership_history_scope_idx',
            );
        });
        Schema::create('customer_workflow_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('event_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('customer_organization_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('workflow_type', 16);
            $table->unsignedBigInteger('workflow_id');
            $table->unsignedBigInteger('source_version');
            $table->string('event_type', 32);
            $table->string('prior_status', 40)->nullable();
            $table->string('current_status', 40);
            $table->string('actor_side', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('priority', 32)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestampTz('occurred_at', 6);
            $table->char('idempotency_key_hash', 64);
            $table->char('evidence_hash', 64);
            $table->jsonb('evidence');
            $table->timestampTz('created_at', 6);

            $table->unique(
                ['organization_id', 'workflow_type', 'workflow_id', 'source_version'],
                'customer_workflow_event_source_version_unique',
            );
            $table->unique(['organization_id', 'idempotency_key_hash'], 'customer_workflow_event_idempotency_unique');
            $table->index(
                ['organization_id', 'project_id', 'customer_organization_id', 'occurred_at', 'workflow_type', 'workflow_id', 'id'],
                'customer_workflow_event_timeline_idx',
            );
        });

        Schema::create('customer_sla_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('customer_organization_id')->nullable();
            $table->string('workflow_type', 16)->nullable();
            $table->string('priority', 32)->nullable();
            $table->string('timezone', 64);
            $table->jsonb('weekday_intervals');
            $table->jsonb('holidays');
            $table->jsonb('pause_statuses');
            $table->unsignedInteger('first_response_target_seconds');
            $table->unsignedInteger('resolution_target_seconds');
            $table->string('version', 64);
            $table->char('source_hash', 64);
            $table->timestampTz('effective_from', 6);
            $table->timestampTz('effective_to', 6)->nullable();
            $table->timestampsTz(6);

            $table->unique(['organization_id', 'version'], 'customer_sla_policy_org_version_unique');
            $table->index(
                ['organization_id', 'project_id', 'customer_organization_id', 'effective_from', 'version'],
                'customer_sla_policy_effective_idx',
            );
        });

        Schema::create('customer_sla_snapshots', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->char('definition_hash', 64);
            $table->char('source_hash', 64);
            $table->string('formula_version', 64);
            $table->jsonb('scope_identity');
            $table->jsonb('filters');
            $table->timestampTz('as_of', 6);
            $table->timestampTz('generated_at', 6);
            $table->timestampTz('stale_at', 6)->nullable();
            $table->jsonb('watermarks');
            $table->unsignedBigInteger('row_count')->default(0);

            $table->unique(['organization_id', 'source_hash', 'definition_hash'], 'customer_sla_snapshot_identity_unique');
            $table->index(['organization_id', 'generated_at', 'id'], 'customer_sla_snapshot_generated_idx');
        });

        Schema::create('customer_sla_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->char('snapshot_id', 26);
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('customer_organization_id')->nullable();
            $table->string('workflow_type', 16);
            $table->unsignedBigInteger('workflow_id');
            $table->string('priority', 32)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('status', 40);
            $table->unsignedBigInteger('policy_version_id');
            $table->timestampTz('opened_at', 6);
            $table->unsignedInteger('first_response_target_seconds');
            $table->unsignedInteger('resolution_target_seconds');
            $table->unsignedBigInteger('first_response_seconds')->nullable();
            $table->unsignedBigInteger('resolution_seconds')->nullable();
            $table->unsignedBigInteger('open_aging_seconds')->nullable();
            $table->boolean('first_response_breached')->nullable();
            $table->boolean('resolution_breached')->nullable();
            $table->boolean('actor_side_complete');
            $table->jsonb('event_refs');
            $table->string('row_key', 256);

            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'customer_sla_row_key_unique');
            $table->index(
                ['organization_id', 'snapshot_id', 'opened_at', 'project_id', 'customer_organization_id', 'workflow_type', 'workflow_id', 'row_key'],
                'customer_sla_row_keyset_idx',
            );
            $table->index(
                ['organization_id', 'snapshot_id', 'project_id', 'customer_organization_id', 'workflow_type', 'priority', 'row_key'],
                'customer_sla_row_filter_idx',
            );
        });

        foreach ([
            "ALTER TABLE customer_membership_history ADD CONSTRAINT customer_membership_history_kind_check CHECK (membership_kind IN ('organization_user','project_organization'))",
            'ALTER TABLE customer_membership_history ADD CONSTRAINT customer_membership_history_interval_check CHECK (valid_to > valid_from)',
            "ALTER TABLE customer_membership_history ADD CONSTRAINT customer_membership_history_hash_check CHECK (evidence_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE customer_workflow_events ADD CONSTRAINT customer_workflow_event_type_check CHECK (workflow_type IN ('issue','request'))",
            "ALTER TABLE customer_workflow_events ADD CONSTRAINT customer_workflow_actor_side_check CHECK (actor_side IN ('customer','delivery_team','system','unknown'))",
            "ALTER TABLE customer_workflow_events ADD CONSTRAINT customer_workflow_event_hash_check CHECK (idempotency_key_hash ~ '^[a-f0-9]{64}$' AND evidence_hash ~ '^[a-f0-9]{64}$')",
            "CREATE UNIQUE INDEX customer_workflow_single_opened_unique ON customer_workflow_events (organization_id, workflow_type, workflow_id) WHERE event_type = 'opened'",
            'ALTER TABLE customer_sla_policy_versions ADD CONSTRAINT customer_sla_policy_interval_check CHECK (effective_to IS NULL OR effective_to > effective_from)',
            'ALTER TABLE customer_sla_policy_versions ADD CONSTRAINT customer_sla_policy_targets_check CHECK (first_response_target_seconds > 0 AND resolution_target_seconds > 0)',
            "ALTER TABLE customer_sla_policy_versions ADD CONSTRAINT customer_sla_policy_hash_check CHECK (source_hash ~ '^[a-f0-9]{64}$')",
            "ALTER TABLE customer_sla_snapshots ADD CONSTRAINT customer_sla_snapshot_hash_check CHECK (definition_hash ~ '^[a-f0-9]{64}$' AND source_hash ~ '^[a-f0-9]{64}$')",
            'ALTER TABLE customer_sla_snapshots ADD CONSTRAINT customer_sla_snapshot_time_check CHECK (stale_at IS NULL OR stale_at >= generated_at)',
            'ALTER TABLE customer_sla_rows ADD CONSTRAINT customer_sla_row_terminal_check CHECK ((NOT actor_side_complete AND first_response_seconds IS NULL AND resolution_seconds IS NULL AND open_aging_seconds IS NULL AND first_response_breached IS NULL AND resolution_breached IS NULL) OR (resolution_seconds IS NULL AND open_aging_seconds IS NOT NULL) OR (resolution_seconds IS NOT NULL AND open_aging_seconds IS NULL))',
            'ALTER TABLE customer_sla_rows ADD CONSTRAINT customer_sla_row_targets_check CHECK (first_response_target_seconds > 0 AND resolution_target_seconds > 0)',
        ] as $statement) {
            DB::statement($statement);
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION most_capture_customer_membership_history_v1()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                valid_from_value timestamptz;
                valid_to_value timestamptz;
                payload text;
            BEGIN
                valid_from_value := COALESCE(OLD.updated_at, OLD.created_at);
                valid_to_value := CASE
                    WHEN TG_OP = 'DELETE' THEN clock_timestamp()
                    ELSE COALESCE(NEW.updated_at, clock_timestamp())
                END;
                IF valid_to_value <= valid_from_value THEN
                    valid_to_value := valid_from_value + interval '1 microsecond';
                END IF;

                IF TG_TABLE_NAME = 'organization_user' THEN
                    payload := concat_ws('|', TG_TABLE_NAME, OLD.id, OLD.organization_id, OLD.user_id,
                        OLD.is_active, valid_from_value, valid_to_value);
                    INSERT INTO customer_membership_history (
                        membership_kind, membership_id, organization_id, user_id, project_id, role,
                        is_active, valid_from, valid_to, evidence_hash
                    ) VALUES (
                        'organization_user', OLD.id, OLD.organization_id, OLD.user_id, NULL, NULL,
                        OLD.is_active, valid_from_value, valid_to_value,
                        encode(sha256(convert_to(payload, 'UTF8')), 'hex')
                    ) ON CONFLICT (evidence_hash) DO NOTHING;
                ELSE
                    payload := concat_ws('|', TG_TABLE_NAME, OLD.id, OLD.organization_id, OLD.project_id,
                        COALESCE(OLD.role_new, OLD.role::text), OLD.is_active, valid_from_value, valid_to_value);
                    INSERT INTO customer_membership_history (
                        membership_kind, membership_id, organization_id, user_id, project_id, role,
                        is_active, valid_from, valid_to, evidence_hash
                    ) VALUES (
                        'project_organization', OLD.id, OLD.organization_id, NULL, OLD.project_id,
                        COALESCE(OLD.role_new, OLD.role::text), OLD.is_active,
                        valid_from_value, valid_to_value,
                        encode(sha256(convert_to(payload, 'UTF8')), 'hex')
                    ) ON CONFLICT (evidence_hash) DO NOTHING;
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$
            SQL);
        DB::statement(
            'CREATE TRIGGER organization_user_customer_history '
            .'BEFORE UPDATE OR DELETE ON organization_user FOR EACH ROW '
            .'EXECUTE FUNCTION most_capture_customer_membership_history_v1()',
        );
        DB::statement(
            'CREATE TRIGGER project_organization_customer_history '
            .'BEFORE UPDATE OR DELETE ON project_organization FOR EACH ROW '
            .'EXECUTE FUNCTION most_capture_customer_membership_history_v1()',
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION most_validate_customer_workflow_causation_v1()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.event_type IN ('resolved', 'reopened')
                   AND NOT EXISTS (
                       SELECT 1
                       FROM customer_workflow_events opened
                       WHERE opened.organization_id = NEW.organization_id
                         AND opened.workflow_type = NEW.workflow_type
                         AND opened.workflow_id = NEW.workflow_id
                         AND opened.event_type = 'opened'
                         AND opened.occurred_at <= NEW.occurred_at
                   )
                THEN
                    RAISE EXCEPTION 'customer_workflow_causation_invalid';
                END IF;
                RETURN NEW;
            END;
            $$
            SQL);
        DB::statement(
            'CREATE TRIGGER customer_workflow_event_causation '
            .'BEFORE INSERT ON customer_workflow_events FOR EACH ROW '
            .'EXECUTE FUNCTION most_validate_customer_workflow_causation_v1()',
        );

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION most_prevent_reporting_mutation_v1()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'reporting_fact_is_immutable';
            END;
            $$
            SQL);

        foreach ([
            'customer_membership_history',
            'customer_workflow_events',
            'customer_sla_policy_versions',
            'customer_sla_snapshots',
            'customer_sla_rows',
        ] as $table) {
            DB::statement(
                "CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} "
                .'FOR EACH ROW EXECUTE FUNCTION most_prevent_reporting_mutation_v1()',
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS organization_user_customer_history ON organization_user');
        DB::statement('DROP TRIGGER IF EXISTS project_organization_customer_history ON project_organization');
        DB::statement('DROP FUNCTION IF EXISTS most_capture_customer_membership_history_v1()');
        Schema::dropIfExists('customer_sla_rows');
        Schema::dropIfExists('customer_sla_snapshots');
        Schema::dropIfExists('customer_sla_policy_versions');
        Schema::dropIfExists('customer_workflow_events');
        DB::statement('DROP FUNCTION IF EXISTS most_validate_customer_workflow_causation_v1()');
        Schema::dropIfExists('customer_membership_history');
    }
};
