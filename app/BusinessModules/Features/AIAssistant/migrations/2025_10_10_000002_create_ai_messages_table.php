<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->onDelete('cascade');
            $table->enum('role', ['user', 'assistant', 'system'])->default('user');
            $table->text('content');
            $table->integer('tokens_used')->default(0);
            $table->string('model', 100)->default('gpt-4o-mini');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};

