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
        Schema::create('production_acceptance_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('performance_act_id');
            $table->string('source_line_type', 64);
            $table->unsignedBigInteger('source_line_id');
            $table->unsignedBigInteger('work_id')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->string('wbs_code')->nullable();
            $table->string('zone')->nullable();
            $table->unsignedBigInteger('contractor_id')->nullable();
            $table->unsignedInteger('transition_version');
            $table->string('event_type', 16);
            $table->unsignedBigInteger('reverses_event_id')->nullable();
            $table->decimal('accepted_quantity_delta', 20, 3);
            $table->decimal('planned_quantity', 20, 3);
            $table->decimal('reported_quantity', 20, 3);
            $table->string('unit_dimension', 64);
            $table->string('unit_code', 64);
            $table->string('conversion_version', 64);
            $table->bigInteger('approved_rate_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('currency_source')->nullable();
            $table->timestampTz('recognized_at');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->char('source_hash', 64);
            $table->jsonb('evidence_refs');
            $table->unique(
                [
                    'organization_id',
                    'performance_act_id',
                    'source_line_type',
                    'source_line_id',
                    'transition_version',
                ],
                'production_acceptance_event_unique',
            );
            $table->index(['organization_id', 'project_id', 'recognized_at', 'performance_act_id', 'source_line_id', 'id'], 'production_acceptance_event_order');
            $table->foreign('reverses_event_id')
                ->references('id')
                ->on('production_acceptance_events')
                ->restrictOnDelete();
        });
        DB::statement(
            "ALTER TABLE production_acceptance_events ADD CONSTRAINT production_acceptance_event_sign_check
            CHECK (
                (event_type = 'accepted' AND accepted_quantity_delta > 0 AND reverses_event_id IS NULL)
                OR
                (event_type = 'reversed' AND accepted_quantity_delta < 0 AND reverses_event_id IS NOT NULL)
            )"
        );
        DB::statement(
            'ALTER TABLE production_acceptance_events ADD CONSTRAINT production_acceptance_money_identity_check
            CHECK (
                (approved_rate_minor IS NULL AND currency IS NULL AND currency_source IS NULL)
                OR
                (approved_rate_minor IS NOT NULL AND currency IS NOT NULL AND currency_source IS NOT NULL)
            )'
        );
        DB::unprepared(<<<'SQL'
CREATE FUNCTION production_acceptance_event_immutable_guard() RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'production_acceptance_events_are_append_only' USING ERRCODE = '55000';
END;
$$;
CREATE TRIGGER production_acceptance_events_append_only
BEFORE UPDATE OR DELETE ON production_acceptance_events
FOR EACH ROW
EXECUTE FUNCTION production_acceptance_event_immutable_guard();
SQL);

        Schema::create('accepted_production_snapshots', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->date('as_of');
            $table->unsignedBigInteger('event_watermark');
            $table->string('formula_version', 64);
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at')->nullable();
            $table->jsonb('watermarks');
            $table->jsonb('totals');
            $table->jsonb('source_refs');
            $table->jsonb('row_schema');
            $table->unsignedInteger('row_count');
            $table->timestampsTz();
            $table->unique(['organization_id', 'query_hash', 'source_hash'], 'accepted_production_snapshot_unique');
        });

        Schema::create('accepted_production_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->string('snapshot_id', 26);
            $table->string('row_key', 160);
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('performance_act_id');
            $table->string('source_line_type', 64);
            $table->unsignedBigInteger('source_line_id');
            $table->unsignedBigInteger('work_id')->nullable();
            $table->unsignedBigInteger('contractor_id')->nullable();
            $table->string('zone')->nullable();
            $table->string('event_status', 16);
            $table->date('recognized_on');
            $table->string('unit_dimension', 64);
            $table->string('unit_code', 64);
            $table->char('currency', 3)->nullable();
            $table->decimal('accepted_quantity', 20, 3);
            $table->bigInteger('accepted_amount_minor')->nullable();
            $table->jsonb('payload');
            $table->jsonb('source_refs');
            $table->unique(['organization_id', 'snapshot_id', 'row_key'], 'accepted_production_row_unique');
            $table->index(['organization_id', 'snapshot_id', 'project_id', 'recognized_on', 'unit_dimension', 'unit_code', 'work_id', 'row_key'], 'accepted_production_row_keyset');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accepted_production_rows');
        Schema::dropIfExists('accepted_production_snapshots');
        Schema::dropIfExists('production_acceptance_events');
        DB::statement('DROP FUNCTION IF EXISTS production_acceptance_event_immutable_guard()');
    }
};
