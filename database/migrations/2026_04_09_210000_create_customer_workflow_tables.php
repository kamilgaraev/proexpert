<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performance_act_id')->nullable()->constrained('contract_performance_acts')->nullOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('title');
            $table->string('issue_reason', 120);
            $table->text('body');
            $table->jsonb('attachments')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 40)->default('new');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['project_id', 'contract_id']);
        });

        Schema::create('customer_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('request_type', 120);
            $table->text('body');
            $table->jsonb('attachments')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 40)->default('new');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['project_id', 'contract_id']);
        });

        Schema::create('customer_portal_comments', function (Blueprint $table): void {
            $table->id();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->jsonb('attachments')->nullable();
            $table->timestamps();

            $table->index(['commentable_type', 'commentable_id'], 'customer_portal_comments_commentable_idx');
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_portal_comments');
        Schema::dropIfExists('customer_requests');
        Schema::dropIfExists('customer_issues');
    }
};
