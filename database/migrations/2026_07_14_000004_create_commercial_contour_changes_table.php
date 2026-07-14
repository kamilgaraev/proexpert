<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_contour_changes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('commercial_account_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['scheduled', 'applied'])->default('scheduled');
            $table->enum('offer_type', ['packages', 'full_suite']);
            $table->unsignedInteger('quote_version');
            $table->jsonb('target_package_slugs');
            $table->jsonb('current_package_slugs');
            $table->timestampTz('apply_at');
            $table->string('client_idempotency_key', 100);
            $table->foreignId('commercial_order_id')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['commercial_account_id', 'organization_id'], 'commercial_contour_account_tenant_fk')
                ->references(['id', 'organization_id'])
                ->on('organization_commercial_accounts')
                ->restrictOnDelete();
            $table->foreign(['commercial_order_id', 'organization_id'], 'commercial_contour_order_tenant_fk')
                ->references(['id', 'organization_id'])
                ->on('commercial_orders')
                ->restrictOnDelete();
            $table->unique(
                ['organization_id', 'client_idempotency_key'],
                'commercial_contour_org_client_key_unique',
            );
            $table->unique(
                ['commercial_account_id', 'apply_at'],
                'commercial_contour_account_anchor_unique',
            );
            $table->index(['status', 'apply_at', 'id'], 'commercial_contour_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_contour_changes');
    }
};
