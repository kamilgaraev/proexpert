<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id');
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('ai_credit_reservations')->nullOnDelete();
            $table->string('request_hash', 64);
            $table->string('profile', 24);
            $table->string('status', 24)->default('running');
            $table->string('stage', 24)->default('queued');
            $table->unsignedSmallInteger('calls_used')->default(0);
            $table->unsignedSmallInteger('max_calls');
            $table->unsignedInteger('approved_max_minor');
            $table->jsonb('response')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('heartbeat_at');
            $table->timestamp('lease_expires_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'request_id']);
            $table->index(['organization_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_requests');
    }
};
