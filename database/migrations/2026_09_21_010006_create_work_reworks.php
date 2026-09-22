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
        Schema::create('work_reworks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('acceptance_scope_id')->constrained('acceptance_scopes')->restrictOnDelete();
            $table->foreignId('quantity_line_id')->constrained('acceptance_scope_work_quantities')->restrictOnDelete();
            $table->decimal('quantity', 24, 6);
            $table->foreignId('unit_id')->constrained('measurement_units')->restrictOnDelete();
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 24)->default('open');
            $table->unsignedInteger('revision')->default(1);
            $table->text('correction_description')->nullable();
            $table->jsonb('evidence_snapshot')->nullable();
            $table->jsonb('financial_impact');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->index(['organization_id', 'project_id', 'status']);
        });
        DB::statement('ALTER TABLE work_reworks ADD CONSTRAINT work_rework_positive_quantity CHECK (quantity > 0)');
        DB::statement("ALTER TABLE work_reworks ADD CONSTRAINT work_rework_status CHECK (status IN ('open', 'submitted', 'accepted', 'rejected'))");
        Schema::table('acceptance_findings', function (Blueprint $table): void {
            $table->foreignId('work_rework_id')->nullable()->constrained('work_reworks')->restrictOnDelete();
        });
        Schema::create('work_rework_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('acceptance_scope_id')->constrained('acceptance_scopes')->restrictOnDelete();
            $table->foreignId('work_rework_id')->constrained('work_reworks')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->string('operation_key', 160);
            $table->string('payload_hash', 64);
            $table->jsonb('payload');
            $table->jsonb('result_snapshot');
            $table->timestampTz('created_at');
            $table->unique(['acceptance_scope_id', 'operation_key']);
        });
        DB::unprepared("CREATE FUNCTION prevent_work_rework_event_change() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'work_rework_event_immutable'; END; \$\$");
        DB::unprepared('CREATE TRIGGER work_rework_event_immutable BEFORE UPDATE OR DELETE ON work_rework_events FOR EACH ROW EXECUTE FUNCTION prevent_work_rework_event_change()');
    }

    public function down(): void
    {
        Schema::dropIfExists('work_rework_events');
        DB::statement('DROP FUNCTION IF EXISTS prevent_work_rework_event_change()');
        Schema::table('acceptance_findings', fn (Blueprint $table) => $table->dropConstrainedForeignId('work_rework_id'));
        Schema::dropIfExists('work_reworks');
    }
};
