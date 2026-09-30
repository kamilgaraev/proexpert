<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_rag_global_index_events', static function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 50);
            $table->string('entity_type', 100);
            $table->string('entity_id', 255);
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('after_organization_id')->default(0);
            $table->string('status', 20)->default('queued');
            $table->timestampTz('queued_at');
            $table->timestampTz('heartbeat_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestampsTz();
            $table->unique(['source_type', 'entity_type', 'entity_id'], 'ai_rag_global_events_entity_unique');
            $table->index(['status', 'queued_at'], 'ai_rag_global_events_queue_idx');
            $table->index(['status', 'lease_expires_at'], 'ai_rag_global_events_lease_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rag_global_index_events');
    }
};
