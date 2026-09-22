<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('executive_document_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_set_id')->constrained('executive_document_sets')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('operation_key', 80);
            $table->string('manifest_hash', 64);
            $table->timestamps();
            $table->unique(['document_set_id', 'created_by', 'operation_key'], 'executive_import_operation_unique');
        });
        Schema::create('executive_document_import_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('executive_document_imports')->restrictOnDelete();
            $table->string('client_key', 80);
            $table->string('original_name');
            $table->unsignedBigInteger('size');
            $table->string('content_hash', 64);
            $table->string('status', 32)->default('awaiting_upload');
            $table->text('staged_path')->nullable();
            $table->jsonb('mapping')->nullable();
            $table->jsonb('errors')->nullable();
            $table->unsignedInteger('attempt')->default(0);
            $table->foreignId('document_id')->nullable()->constrained('executive_documents')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['import_id', 'client_key']);
            $table->index(['import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_document_import_items');
        Schema::dropIfExists('executive_document_imports');
    }
};
