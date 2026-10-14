<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_team_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manager_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'manager_user_id', 'employee_id']);
        });
        Schema::create('workforce_employee_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('workforce_employees')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('kind', 30);
            $table->date('date_from');
            $table->date('date_to');
            $table->string('basis', 1000);
            $table->jsonb('requested_data');
            $table->string('status', 30)->default('submitted');
            $table->string('manager_decision_basis', 1000)->nullable();
            $table->string('hr_decision_basis', 1000)->nullable();
            $table->foreignId('manager_decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('hr_decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->jsonb('comments')->default('[]');
            $table->jsonb('result')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampsTz();
            $table->unique(['organization_id', 'requested_by', 'idempotency_key'], 'workforce_request_idempotency');
            $table->index(['organization_id', 'employee_id', 'status']);
            $table->index(['organization_id', 'status', 'id']);
            $table->index(['organization_id', 'employee_id', 'id']);
        });
        Schema::create('workforce_request_notification_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_id')->constrained('workforce_employee_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('status', 30);
            $table->string('interface', 20);
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampTz('discarded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['request_id', 'user_id', 'revision', 'interface'], 'workforce_request_notification_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_request_notification_outbox');
        Schema::dropIfExists('workforce_employee_requests');
        Schema::dropIfExists('workforce_team_scopes');
    }
};
