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
        Schema::create('estimate_revision_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users');
            $table->foreignId('source_version_id')->constrained('estimate_versions');
            $table->string('idempotency_key', 128);
            $table->text('reason');
            $table->string('status', 20)->default('queued');
            $table->string('error_code')->nullable();
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['estimate_id', 'idempotency_key'], 'estimate_revision_idempotency');
            $table->index(['status', 'updated_at']);
        });
        DB::statement("CREATE UNIQUE INDEX estimate_revision_active ON estimate_revision_operations (estimate_id) WHERE status IN ('queued', 'processing')");
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_revision_operations');
    }
};
