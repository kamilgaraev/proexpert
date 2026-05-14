<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_work_permits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rejected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('suspended_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('permit_number', 80)->unique();
            $table->string('title');
            $table->string('permit_type', 80);
            $table->string('location_name')->nullable();
            $table->string('risk_level', 30)->default('medium');
            $table->dateTime('valid_from');
            $table->dateTime('valid_until');
            $table->jsonb('required_controls')->nullable();
            $table->string('status', 40)->default('draft');
            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->text('approval_comment')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->text('close_comment')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['valid_until', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_work_permits');
    }
};
