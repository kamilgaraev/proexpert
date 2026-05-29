<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_generation_document_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('estimate_generation_documents')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('estimate_generation_sessions')->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->smallInteger('rotation')->nullable();
            $table->jsonb('language_codes')->nullable();
            $table->longText('text')->nullable();
            $table->string('text_hash', 64)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('raw_payload_path')->nullable();
            $table->jsonb('normalized_payload')->nullable();
            $table->jsonb('quality_flags')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'page_number'], 'estimate_generation_document_pages_document_page_unique');
            $table->index(['session_id', 'document_id'], 'estimate_generation_document_pages_session_document_idx');
            $table->index(['organization_id', 'text_hash'], 'estimate_generation_document_pages_org_text_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_document_pages');
    }
};
