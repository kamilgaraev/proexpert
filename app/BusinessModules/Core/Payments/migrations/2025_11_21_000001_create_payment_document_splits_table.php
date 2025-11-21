<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_document_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_document_id')->constrained('payment_documents')->onDelete('cascade');
            $table->foreignId('project_id')->nullable()->constrained('projects')->onDelete('set null');
            $table->unsignedBigInteger('cost_item_id')->nullable(); // Foreign key to cost items table
            $table->decimal('amount', 15, 2);
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payment_document_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_document_splits');
    }
};

