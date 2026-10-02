<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_assistant_documents', function (Blueprint $table): void {
            $table->id(); $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->string('parent_entity_type', 80); $table->string('parent_entity_id', 80);
            $table->string('storage_path', 1024); $table->string('filename', 255); $table->string('mime_type', 160);
            $table->string('checksum', 64); $table->unsignedBigInteger('size_bytes');
            $table->string('status', 32); $table->string('coverage_status', 32)->default('pending');
            $table->jsonb('metadata')->nullable(); $table->longText('extracted_text')->nullable();
            $table->unsignedBigInteger('ocr_quote_id')->nullable(); $table->unsignedBigInteger('ocr_reservation_id')->nullable();
            $table->foreignId('ocr_approved_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestampTz('ocr_approved_at')->nullable();
            $table->timestampTz('processed_at')->nullable(); $table->string('last_error', 160)->nullable(); $table->timestampsTz();
            $table->unique(['file_id', 'checksum'], 'ai_assistant_documents_file_checksum_unique');
            $table->index(['organization_id', 'parent_entity_type', 'parent_entity_id'], 'ai_assistant_documents_parent_idx');
        });
        Schema::create('ai_assistant_document_units', function (Blueprint $table): void {
            $table->id(); $table->foreignId('document_id')->constrained('ai_assistant_documents')->cascadeOnDelete();
            $table->string('unit_type', 32); $table->unsignedInteger('unit_index'); $table->longText('text');
            $table->jsonb('provenance')->nullable(); $table->string('checksum', 64); $table->decimal('confidence', 5, 4)->nullable(); $table->timestampsTz();
            $table->unique(['document_id', 'unit_type', 'unit_index'], 'ai_assistant_document_units_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('ai_assistant_document_units'); Schema::dropIfExists('ai_assistant_documents'); }
};
