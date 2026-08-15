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
        Schema::table('estimate_generation_ai_estimate_quota_reservations', function (Blueprint $table): void {
            $table->timestampTz('reserved_at')->nullable();
        });

        Schema::table('estimate_generation_documents', function (Blueprint $table): void {
            $table->string('processing_control_status', 16)->default('active');
            $table->string('processing_control_source_version', 80)->nullable();
            $table->uuid('processing_control_attempt_id')->nullable();
            $table->string('processing_control_reason', 80)->nullable();
            $table->timestampTz('processing_control_at')->nullable();
            $table->decimal('processing_cost_limit', 18, 8)->nullable();
            $table->timestampTz('processing_cost_confirmed_at')->nullable();
            $table->unsignedInteger('processing_cost_confirmation_version')->default(0);
            $table->index(
                ['organization_id', 'project_id', 'session_id', 'processing_control_status'],
                'eg_documents_processing_control_idx',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_generation_ai_estimate_quota_reservations DROP CONSTRAINT eg_ai_estimate_quota_state_ck');
            DB::statement('ALTER TABLE estimate_generation_ai_estimate_quota_reservations DROP CONSTRAINT eg_ai_estimate_quota_status_ck');
            DB::statement('ALTER TABLE estimate_generation_ai_estimate_quota_reservations ALTER COLUMN confirmed_at DROP NOT NULL');
            DB::statement('UPDATE estimate_generation_ai_estimate_quota_reservations SET reserved_at = confirmed_at WHERE reserved_at IS NULL');
            DB::statement("ALTER TABLE estimate_generation_ai_estimate_quota_reservations ADD CONSTRAINT eg_ai_estimate_quota_status_ck CHECK (status IN ('reserved', 'confirmed', 'released'))");
            DB::statement("ALTER TABLE estimate_generation_ai_estimate_quota_reservations ADD CONSTRAINT eg_ai_estimate_quota_state_ck CHECK (reserved_at IS NOT NULL AND ((status = 'reserved' AND confirmed_at IS NULL AND released_at IS NULL) OR (status = 'confirmed' AND confirmed_at IS NOT NULL AND released_at IS NULL) OR (status = 'released' AND released_at IS NOT NULL)))");
            DB::statement("ALTER TABLE estimate_generation_documents ADD CONSTRAINT eg_documents_processing_control_status_ck CHECK (processing_control_status IN ('active','paused','cancelled'))");
            DB::statement('ALTER TABLE estimate_generation_documents ADD CONSTRAINT eg_documents_processing_cost_limit_ck CHECK (processing_cost_limit IS NULL OR processing_cost_limit > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_generation_documents DROP CONSTRAINT IF EXISTS eg_documents_processing_cost_limit_ck');
            DB::statement('ALTER TABLE estimate_generation_documents DROP CONSTRAINT IF EXISTS eg_documents_processing_control_status_ck');
            DB::statement('ALTER TABLE estimate_generation_ai_estimate_quota_reservations DROP CONSTRAINT IF EXISTS eg_ai_estimate_quota_state_ck');
            DB::statement('ALTER TABLE estimate_generation_ai_estimate_quota_reservations DROP CONSTRAINT IF EXISTS eg_ai_estimate_quota_status_ck');
            DB::statement('UPDATE estimate_generation_ai_estimate_quota_reservations SET confirmed_at = COALESCE(confirmed_at, reserved_at, NOW()), status = CASE WHEN status = \'reserved\' THEN \'confirmed\' ELSE status END');
            DB::statement('ALTER TABLE estimate_generation_ai_estimate_quota_reservations ALTER COLUMN confirmed_at SET NOT NULL');
            DB::statement("ALTER TABLE estimate_generation_ai_estimate_quota_reservations ADD CONSTRAINT eg_ai_estimate_quota_status_ck CHECK (status IN ('confirmed', 'released'))");
            DB::statement("ALTER TABLE estimate_generation_ai_estimate_quota_reservations ADD CONSTRAINT eg_ai_estimate_quota_state_ck CHECK ((status = 'confirmed' AND released_at IS NULL) OR (status = 'released' AND released_at IS NOT NULL))");
        }

        Schema::table('estimate_generation_documents', function (Blueprint $table): void {
            $table->dropIndex('eg_documents_processing_control_idx');
            $table->dropColumn([
                'processing_control_status',
                'processing_control_source_version',
                'processing_control_attempt_id',
                'processing_control_reason',
                'processing_control_at',
                'processing_cost_limit',
                'processing_cost_confirmed_at',
                'processing_cost_confirmation_version',
            ]);
        });
        Schema::table('estimate_generation_ai_estimate_quota_reservations', function (Blueprint $table): void {
            $table->dropColumn('reserved_at');
        });
    }
};
