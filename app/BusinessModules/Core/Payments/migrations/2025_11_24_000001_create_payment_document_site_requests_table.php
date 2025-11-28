<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_document_site_requests', function (Blueprint $table) {
            $table->id();
            
            // Foreign keys
            $table->foreignId('payment_document_id')
                ->constrained('payment_documents')
                ->onDelete('cascade');
            
            $table->foreignId('site_request_id')
                ->constrained('site_requests')
                ->onDelete('cascade');
            
            // Сумма из этой заявки в общем платеже (опционально)
            $table->decimal('amount', 15, 2)->nullable()
                ->comment('Сумма из этой заявки в общем платеже');
            
            $table->timestamps();
            
            // Уникальный индекс для предотвращения дублей
            $table->unique(['payment_document_id', 'site_request_id'], 'payment_doc_site_req_unique');
            
            // Индексы для быстрого поиска
            $table->index('payment_document_id');
            $table->index('site_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_document_site_requests');
    }
};

