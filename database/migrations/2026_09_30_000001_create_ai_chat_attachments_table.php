<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_attachments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('ai_messages')->cascadeOnDelete();
            $table->uuid('request_id')->nullable()->index();
            $table->string('name', 255);
            $table->string('mime', 32);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->char('checksum', 64);
            $table->string('storage_path', 512)->unique();
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_attachments');
    }
};
