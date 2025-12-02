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
        Schema::table('payment_schedules', function (Blueprint $table) {
            // Удаляем старую связь с Invoice (dropConstrainedForeignId удаляет и FK, и колонку)
            $table->dropConstrainedForeignId('invoice_id');
            
            // Делаем payment_document_id обязательным
            $table->foreignId('payment_document_id')->nullable(false)->change();
            
            // Добавляем индекс для новой связи
            $table->index(['payment_document_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_schedules', function (Blueprint $table) {
            // Восстанавливаем invoice_id
            $table->foreignId('invoice_id')
                ->nullable()
                ->after('id')
                ->constrained('invoices')
                ->onDelete('cascade');
            
            // Делаем payment_document_id nullable
            $table->foreignId('payment_document_id')->nullable()->change();
        });
    }
};

