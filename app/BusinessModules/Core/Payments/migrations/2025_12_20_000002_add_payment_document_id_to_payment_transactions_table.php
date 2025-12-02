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
        Schema::table('payment_transactions', function (Blueprint $table) {
            // Добавляем связь с PaymentDocument
            $table->foreignId('payment_document_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('payment_documents')
                ->onDelete('cascade');
            
            // Делаем invoice_id nullable для миграции
            $table->foreignId('invoice_id')->nullable()->change();
            
            // Индекс для новой связи
            $table->index('payment_document_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex(['payment_document_id']);
            $table->dropForeign(['payment_document_id']);
            $table->dropColumn('payment_document_id');
            
            // Восстанавливаем invoice_id как обязательное поле
            $table->foreignId('invoice_id')->nullable(false)->change();
        });
    }
};

