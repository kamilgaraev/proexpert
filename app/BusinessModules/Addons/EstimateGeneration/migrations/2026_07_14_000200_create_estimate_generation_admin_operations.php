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
        Schema::create('estimate_generation_admin_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->string('operation', 40);
            $table->string('idempotency_key', 80);
            $table->char('command_fingerprint', 71);
            $table->string('status', 16);
            $table->jsonb('result')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->timestampTz('completed_at')->nullable();
            $table->unique(['organization_id', 'operation', 'idempotency_key'], 'eg_admin_operations_idempotency_uq');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_generation_admin_operations ADD CONSTRAINT eg_admin_operations_status_ck CHECK (status IN ('pending','completed'))");
            DB::statement("ALTER TABLE estimate_generation_admin_operations ADD CONSTRAINT eg_admin_operations_fingerprint_ck CHECK (command_fingerprint ~ '^sha256:[0-9a-f]{64}$')");
            DB::statement("ALTER TABLE estimate_generation_admin_operations ADD CONSTRAINT eg_admin_operations_result_ck CHECK ((status = 'pending' AND result IS NULL AND completed_at IS NULL) OR (status = 'completed' AND result IS NOT NULL AND completed_at IS NOT NULL))");
            DB::statement("ALTER TABLE estimate_generation_admin_operations ADD CONSTRAINT eg_admin_operations_operation_ck CHECK (operation = 'resolve_failure')");
            DB::statement("ALTER TABLE estimate_generation_admin_operations ADD CONSTRAINT eg_admin_operations_result_shape_ck CHECK (result IS NULL OR (jsonb_typeof(result) = 'object' AND result ?& ARRAY['successful','message_key'] AND (result - ARRAY['successful','message_key']) = '{}'::jsonb AND jsonb_typeof(result->'successful') = 'boolean' AND jsonb_typeof(result->'message_key') = 'string'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_admin_operations');
    }
};
