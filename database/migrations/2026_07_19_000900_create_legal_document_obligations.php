<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_document_obligations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('legal_archive_documents')->cascadeOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained('legal_archive_document_versions')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('responsible_party', 191)->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('volume', 18, 3)->nullable();
            $table->string('unit', 64)->nullable();
            $table->string('status', 32)->default('open');
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['document_id', 'title'], 'legal_document_obligations_document_title_unique');
            $table->index(['organization_id', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document_obligations');
    }
};
