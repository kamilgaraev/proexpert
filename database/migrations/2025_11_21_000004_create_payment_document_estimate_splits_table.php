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
        Schema::create('payment_document_estimate_splits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_document_id')
                ->constrained('payment_documents')
                ->cascadeOnDelete()
                ->comment('Платежный документ');
            $table->foreignId('estimate_item_id')
                ->constrained('estimate_items')
                ->cascadeOnDelete()
                ->comment('Позиция сметы');
            $table->decimal('amount', 15, 2)
                ->comment('Сумма для позиции');
            $table->decimal('percentage', 5, 2)
                ->comment('Процент от общей суммы');
            $table->timestamps();

            // Индексы
            $table->index('payment_document_id');
            $table->index('estimate_item_id');
            $table->index(['payment_document_id', 'estimate_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_document_estimate_splits');
    }
};

