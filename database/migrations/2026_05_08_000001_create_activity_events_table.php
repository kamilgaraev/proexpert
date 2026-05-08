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
        Schema::create('activity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 40)->default('user');
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('interface', 40)->nullable();
            $table->string('module', 80);
            $table->string('event_type', 120);
            $table->string('action', 40);
            $table->string('result', 40)->default('success');
            $table->string('severity', 40)->default('info');
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->jsonb('changes')->nullable()->default('{}');
            $table->jsonb('context')->nullable()->default('{}');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('correlation_id', 120)->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['organization_id', 'occurred_at'], 'activity_events_org_time_idx');
            $table->index(['organization_id', 'actor_user_id', 'occurred_at'], 'activity_events_actor_idx');
            $table->index(['organization_id', 'module', 'occurred_at'], 'activity_events_module_idx');
            $table->index(['organization_id', 'event_type', 'occurred_at'], 'activity_events_type_idx');
            $table->index(['organization_id', 'subject_type', 'subject_id'], 'activity_events_subject_idx');
            $table->index(['organization_id', 'project_id', 'occurred_at'], 'activity_events_project_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX activity_events_context_gin_idx ON activity_events USING GIN (context)');
            DB::statement('CREATE INDEX activity_events_changes_gin_idx ON activity_events USING GIN (changes)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
