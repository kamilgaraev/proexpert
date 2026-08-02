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
        Schema::create('estimate_generation_ai_estimate_quota_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('session_id');
            $table->date('monthly_period');
            $table->string('status', 16);
            $table->timestampTz('confirmed_at');
            $table->timestampTz('released_at')->nullable();
            $table->unique(['organization_id', 'session_id'], 'eg_ai_estimate_quota_session_uq');
            $table->index(['organization_id', 'monthly_period', 'status'], 'eg_ai_estimate_quota_month_idx');
            $table->foreign(['session_id', 'organization_id'], 'eg_ai_estimate_quota_session_fk')
                ->references(['id', 'organization_id'])
                ->on('estimate_generation_sessions')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_generation_ai_estimate_quota_reservations ADD CONSTRAINT eg_ai_estimate_quota_status_ck CHECK (status IN ('confirmed', 'released'))");
            DB::statement("ALTER TABLE estimate_generation_ai_estimate_quota_reservations ADD CONSTRAINT eg_ai_estimate_quota_state_ck CHECK ((status = 'confirmed' AND released_at IS NULL) OR (status = 'released' AND released_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_ai_estimate_quota_reservations');
    }
};
