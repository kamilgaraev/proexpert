<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_document_transmittals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_set_id')->constrained('executive_document_sets')->cascadeOnDelete();
            $table->foreignId('transmitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transmittal_number', 80);
            $table->text('comment')->nullable();
            $table->text('acknowledgement_comment')->nullable();
            $table->timestamp('transmitted_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'transmittal_number']);
            $table->index(['organization_id', 'document_set_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_document_transmittals');
    }
};
