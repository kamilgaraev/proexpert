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
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->timestamp('last_activity_at')->nullable()->after('context');
            $table->unsignedInteger('context_version')->default(1)->after('last_activity_at');
            $table->index(['organization_id', 'last_activity_at'], 'ai_conversations_retention_idx');
        });
        DB::statement('UPDATE ai_conversations SET last_activity_at = COALESCE((SELECT MAX(created_at) FROM ai_messages WHERE ai_messages.conversation_id = ai_conversations.id), created_at)');
        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->index(['conversation_id', 'created_at', 'id'], 'ai_messages_history_idx');
        });

        Schema::create('ai_conversation_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            $table->foreignId('added_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'conversation_id']);
        });
        Schema::create('ai_conversation_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('ai_conversations')->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->jsonb('summary_segments')->nullable();
            $table->jsonb('selected_entities')->nullable();
            $table->jsonb('user_decisions')->nullable();
            $table->jsonb('source_refs')->nullable();
            $table->unsignedInteger('context_version')->default(1);
            $table->timestamps();
        });
        Schema::create('ai_memories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->cascadeOnDelete();
            $table->boolean('confirmed')->default(false);
            $table->string('kind', 32);
            $table->jsonb('payload');
            $table->jsonb('source_refs')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'last_used_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memories');
        Schema::dropIfExists('ai_conversation_summaries');
        Schema::dropIfExists('ai_conversation_participants');
        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->dropIndex('ai_messages_history_idx');
        });
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->dropIndex('ai_conversations_retention_idx');
            $table->dropColumn(['last_activity_at', 'context_version']);
        });
    }
};
