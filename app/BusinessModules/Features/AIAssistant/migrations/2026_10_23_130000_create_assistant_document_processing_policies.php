<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_document_processing_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id')->unique();
            $table->unsignedBigInteger('file_id')->index();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('policy_version', 40);
            $table->uuid('generation');
            $table->string('state', 20);
            $table->string('source_identity', 64);
            $table->timestampsTz();
            $table->index(['organization_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_document_processing_policies');
    }
};
