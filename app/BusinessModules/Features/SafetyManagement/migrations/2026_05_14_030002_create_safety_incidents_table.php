<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('triaged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('incident_number', 80)->unique();
            $table->string('title');
            $table->string('incident_type', 80);
            $table->string('severity', 30)->default('minor');
            $table->string('status', 40)->default('reported');
            $table->dateTime('occurred_at');
            $table->string('location_name')->nullable();
            $table->text('description')->nullable();
            $table->text('immediate_actions')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('corrective_actions')->nullable();
            $table->text('triage_comment')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('triaged_at')->nullable();
            $table->dateTime('investigation_started_at')->nullable();
            $table->dateTime('corrective_actions_started_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['severity', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_incidents');
    }
};
