<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_set_id')->constrained('executive_document_sets')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_type', 80);
            $table->string('title');
            $table->string('status', 40)->default('draft');
            $table->string('work_type_name')->nullable();
            $table->string('section_name')->nullable();
            $table->unsignedBigInteger('completed_work_id')->nullable();
            $table->date('inspection_date')->nullable();
            $table->jsonb('participants')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'status']);
            $table->index(['organization_id', 'document_set_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_documents');
    }
};
