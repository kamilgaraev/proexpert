<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('change_management_rfis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('rfi_number', 80);
            $table->string('subject');
            $table->text('question');
            $table->string('addressee_type', 80);
            $table->string('status', 40)->default('draft');
            $table->date('response_due_date')->nullable();
            $table->text('answer')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->jsonb('attachments')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'rfi_number']);
            $table->index(['organization_id', 'project_id', 'status']);
        });

        Schema::create('change_management_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('related_rfi_id')->nullable()->constrained('change_management_rfis')->nullOnDelete();
            $table->string('change_number', 80);
            $table->string('title');
            $table->string('reason', 120);
            $table->text('description');
            $table->string('initiator_type', 80);
            $table->string('status', 40)->default('draft');
            $table->jsonb('affected_schedule_task_ids')->nullable();
            $table->jsonb('affected_estimate_item_ids')->nullable();
            $table->jsonb('linked_entities')->nullable();
            $table->text('implementation_comment')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('implemented_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'change_number']);
            $table->index(['organization_id', 'project_id', 'status']);
        });

        Schema::create('change_management_impacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('change_request_id')->constrained('change_management_change_requests')->cascadeOnDelete();
            $table->decimal('cost_delta', 18, 2)->default(0);
            $table->integer('schedule_delta_days')->default(0);
            $table->boolean('requires_contract_change')->default(false);
            $table->boolean('requires_estimate_revision')->default(false);
            $table->boolean('requires_procurement_update')->default(false);
            $table->boolean('requires_customer_approval')->default(false);
            $table->jsonb('affected_schedule_task_ids')->nullable();
            $table->jsonb('affected_estimate_item_ids')->nullable();
            $table->jsonb('affected_contract_ids')->nullable();
            $table->text('summary')->nullable();
            $table->timestampsTz();

            $table->unique('change_request_id');
        });

        Schema::create('change_management_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('change_request_id')->constrained('change_management_change_requests')->cascadeOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_type', 40);
            $table->string('status', 40);
            $table->text('comment')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'change_request_id', 'approval_type']);
        });

        Schema::create('change_management_variation_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('change_request_id')->constrained('change_management_change_requests')->cascadeOnDelete();
            $table->string('variation_number', 80);
            $table->decimal('amount', 18, 2)->default(0);
            $table->integer('schedule_delta_days')->default(0);
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'variation_number']);
        });

        Schema::create('change_management_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('change_request_id')->nullable()->constrained('change_management_change_requests')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('claim_number', 80);
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('status', 40)->default('submitted');
            $table->jsonb('evidence')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['organization_id', 'claim_number']);
            $table->index(['organization_id', 'project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_management_claims');
        Schema::dropIfExists('change_management_variation_orders');
        Schema::dropIfExists('change_management_approvals');
        Schema::dropIfExists('change_management_impacts');
        Schema::dropIfExists('change_management_change_requests');
        Schema::dropIfExists('change_management_rfis');
    }
};
