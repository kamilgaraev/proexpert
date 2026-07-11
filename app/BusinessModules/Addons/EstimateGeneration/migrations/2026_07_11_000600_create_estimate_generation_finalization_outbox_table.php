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
        Schema::create('estimate_generation_finalization_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->foreignId('session_id');
            $table->uuid('generation_attempt_id');
            $table->string('event_type', 80);
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 20);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('available_at');
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique(['session_id', 'generation_attempt_id', 'event_type'], 'eg_finalization_event_uq');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_finalization_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->index(['status', 'available_at', 'lease_expires_at'], 'eg_finalization_delivery_idx');
        });
        DB::statement("ALTER TABLE estimate_generation_finalization_outbox ADD CONSTRAINT eg_finalization_status_ck CHECK (status IN ('pending','delivering','delivered'))");
        DB::statement('ALTER TABLE estimate_generation_finalization_outbox ADD CONSTRAINT eg_finalization_attempt_ck CHECK (attempt_count >= 0)');
        DB::statement("ALTER TABLE estimate_generation_finalization_outbox ADD CONSTRAINT eg_finalization_key_ck CHECK (idempotency_key ~ '^[0-9a-f]{64}$')");
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_finalization_outbox
            ADD CONSTRAINT eg_finalization_state_ck CHECK (
                (status = 'pending' AND claim_token IS NULL AND lease_expires_at IS NULL AND delivered_at IS NULL)
                OR (status = 'delivering' AND claim_token IS NOT NULL AND lease_expires_at IS NOT NULL AND delivered_at IS NULL)
                OR (status = 'delivered' AND claim_token IS NULL AND lease_expires_at IS NULL AND delivered_at IS NOT NULL)
            )
            SQL);
        Schema::create('estimate_generation_recovery_cursors', function (Blueprint $table): void {
            $table->string('consumer', 80)->primary();
            $table->unsignedBigInteger('last_session_id')->default(0);
            $table->timestampsTz();
        });
        Schema::create('estimate_generation_finalization_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->foreignId('session_id');
            $table->uuid('generation_attempt_id');
            $table->string('event_type', 80);
            $table->unsignedBigInteger('recipient_id');
            $table->char('business_key', 64);
            $table->uuid('notification_id')->nullable();
            $table->string('status', 20);
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique('business_key', 'eg_finalization_delivery_business_uq');
            $table->unique(['organization_id', 'session_id', 'generation_attempt_id', 'event_type', 'recipient_id'], 'eg_finalization_delivery_scope_uq');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_finalization_delivery_session_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE estimate_generation_finalization_deliveries ADD CONSTRAINT eg_finalization_delivery_status_ck CHECK (status IN ('pending','delivered'))");
        DB::statement("ALTER TABLE estimate_generation_finalization_deliveries ADD CONSTRAINT eg_finalization_delivery_key_ck CHECK (business_key ~ '^[0-9a-f]{64}$')");
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_finalization_deliveries
            ADD CONSTRAINT eg_finalization_delivery_state_ck CHECK (
                (status = 'pending' AND notification_id IS NULL AND delivered_at IS NULL)
                OR (status = 'delivered' AND delivered_at IS NOT NULL)
            )
            SQL);
        DB::statement(<<<'SQL'
            CREATE FUNCTION eg_finalization_delivery_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF pg_trigger_depth() <= 1 THEN
                        RAISE EXCEPTION 'estimate_generation.finalization_delivery_delete_forbidden';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'pending' AND NEW.status = 'delivered'
                    AND (to_jsonb(OLD) - ARRAY['status','notification_id','delivered_at','updated_at'])
                      IS NOT DISTINCT FROM
                        (to_jsonb(NEW) - ARRAY['status','notification_id','delivered_at','updated_at'])
                    AND NEW.delivered_at IS NOT NULL THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'estimate_generation.finalization_delivery_is_immutable';
            END; $$
            SQL);
        DB::statement('CREATE TRIGGER eg_finalization_delivery_immutable BEFORE UPDATE OR DELETE ON estimate_generation_finalization_deliveries FOR EACH ROW EXECUTE FUNCTION eg_finalization_delivery_immutable_guard()');
    }

    public function down(): void
    {
        if (Schema::hasTable('estimate_generation_finalization_deliveries')) {
            DB::statement('DROP TRIGGER IF EXISTS eg_finalization_delivery_immutable ON estimate_generation_finalization_deliveries');
        }
        DB::statement('DROP FUNCTION IF EXISTS eg_finalization_delivery_immutable_guard()');
        Schema::dropIfExists('estimate_generation_finalization_deliveries');
        Schema::dropIfExists('estimate_generation_recovery_cursors');
        Schema::dropIfExists('estimate_generation_finalization_outbox');
    }
};
