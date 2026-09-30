<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_pending_actions', function (Blueprint $table): void {
            $table->uuid('origin_request_id')->nullable();
            $table->jsonb('origin_request_payload')->nullable();
            $table->string('origin_request_hash', 64)->nullable();
            $table->string('binding_hash', 64)->nullable()->unique();
            $table->unsignedBigInteger('conversation_context_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_pending_actions', function (Blueprint $table): void {
            $table->dropUnique(['binding_hash']);
            $table->dropColumn(['origin_request_id', 'origin_request_payload', 'origin_request_hash', 'binding_hash', 'conversation_context_version']);
        });
    }
};
