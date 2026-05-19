<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_document_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('executive_documents')->cascadeOnDelete();
            $table->string('relation_type', 80);
            $table->string('target_type', 80);
            $table->unsignedBigInteger('target_id');
            $table->string('label')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'relation_type']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_document_relations');
    }
};
