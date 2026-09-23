<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_document_approved_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('approved_by_party', 255);
            $table->date('approved_at');
            $table->string('file_url');
            $table->char('file_hash', 64);
            $table->string('original_name', 255);
            $table->jsonb('items');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['project_id', 'revision']);
            $table->index(['organization_id', 'project_id']);
        });

        Schema::table('executive_document_sets', function (Blueprint $table): void {
            $table->foreignId('approved_list_id')->nullable()->after('project_id')
                ->constrained('executive_document_approved_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('executive_document_sets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_list_id');
        });
        Schema::dropIfExists('executive_document_approved_lists');
    }
};
