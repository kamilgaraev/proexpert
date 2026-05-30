<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_hiring_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('hiring_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('contractor_organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('contractor_profile_id')->constrained('marketplace_contractor_profiles')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('responded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('sent');
            $table->string('role', 64);
            $table->string('title');
            $table->text('message')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->decimal('budget_min', 15, 2)->nullable();
            $table->decimal('budget_max', 15, 2)->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('viewed_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('declined_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->string('status_reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['hiring_organization_id', 'status']);
            $table->index(['contractor_organization_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_hiring_offers');
    }
};
