<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_rag_expected_sources', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->uuid('generation');
            $table->unsignedBigInteger('identity_project_id')->default(0);
            $table->string('identity_part_key', 64)->default('');
            $table->string('source_type', 80);
            $table->string('entity_type', 80);
            $table->string('entity_id', 80);
            $table->string('checksum', 64);
            $table->timestampTz('pending_since');
            $table->timestampsTz();
            $table->unique(['organization_id', 'generation', 'identity_project_id', 'source_type', 'entity_type', 'entity_id', 'identity_part_key'], 'ai_rag_expected_identity_unique');
            $table->index(['organization_id', 'generation', 'source_type', 'project_id'], 'ai_rag_expected_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rag_expected_sources');
    }
};
