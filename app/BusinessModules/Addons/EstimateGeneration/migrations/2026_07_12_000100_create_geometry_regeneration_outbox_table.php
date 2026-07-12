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
        Schema::create('estimate_generation_geometry_regeneration_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('state_version');
            $table->char('previous_input_version', 71);
            $table->char('input_version', 71);
            $table->char('model_version', 71);
            $table->uuid('generation_attempt_id');
            $table->char('idempotency_key', 64)->unique();
            $table->string('status', 16);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->timestampTz('available_at');
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'available_at']);
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_geometry_outbox_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_generation_geometry_regeneration_outbox ADD CONSTRAINT eg_geometry_outbox_status_ck CHECK (status IN ('pending','delivering','delivered','failed'))");
            DB::statement("ALTER TABLE estimate_generation_geometry_regeneration_outbox ADD CONSTRAINT eg_geometry_outbox_versions_ck CHECK (previous_input_version ~ '^sha256:[a-f0-9]{64}$' AND input_version ~ '^sha256:[a-f0-9]{64}$' AND model_version ~ '^sha256:[a-f0-9]{64}$')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_geometry_regeneration_outbox');
    }
};
