<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_resource_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('commercial_account_id')->nullable();
            $table->string('resource_slug', 100);
            $table->string('limit_key', 100);
            $table->decimal('quantity', 14, 2)->nullable();
            $table->enum('source', ['paid_addon', 'corporate_override', 'manual_grant']);
            $table->enum('status', ['active', 'scheduled_for_removal', 'expired', 'canceled'])->default('active');
            $table->timestampTz('period_start_at')->nullable();
            $table->timestampTz('period_end_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign(['commercial_account_id', 'organization_id'], 'org_resource_account_tenant_fk')
                ->references(['id', 'organization_id'])
                ->on('organization_commercial_accounts')
                ->cascadeOnDelete();
            $table->index(['organization_id', 'limit_key', 'source', 'status'], 'org_resource_limit_source_idx');
            $table->index(['organization_id', 'resource_slug', 'status'], 'org_resource_slug_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_resource_allocations');
    }
};
