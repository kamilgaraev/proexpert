<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_pending_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->string('tool_name', 120);
            $table->jsonb('arguments');
            $table->string('action_class', 60);
            $table->jsonb('entity_state')->nullable();
            $table->string('entity_state_hash', 64)->nullable();
            $table->string('token_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->jsonb('result')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'actor_user_id', 'conversation_id']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pending_actions');
    }
};
