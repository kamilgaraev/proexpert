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
        Schema::create('asset_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('schedule_task_id')->nullable()->constrained('schedule_tasks')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_asset_id')->nullable()->constrained('organization_assets')->nullOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('priority', 24)->default('normal');
            $table->timestampTz('planned_start_at');
            $table->timestampTz('planned_end_at')->nullable();
            $table->jsonb('required_profile')->nullable();
            $table->text('purpose');
            $table->text('decision_comment')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'status', 'planned_start_at']);
        });

        Schema::create('asset_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_request_id')->constrained('asset_requests')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->jsonb('payload')->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['asset_request_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_asset_request_event_mutation() RETURNS trigger
                LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'asset request audit events are immutable' USING ERRCODE = '55000';
                END
                $$;
                CREATE TRIGGER asset_request_events_immutable
                BEFORE UPDATE OR DELETE ON asset_request_events
                FOR EACH ROW EXECUTE FUNCTION prevent_asset_request_event_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS asset_request_events_immutable ON asset_request_events; DROP FUNCTION IF EXISTS prevent_asset_request_event_mutation();');
        }
        Schema::dropIfExists('asset_request_events');
        Schema::dropIfExists('asset_requests');
    }
};
