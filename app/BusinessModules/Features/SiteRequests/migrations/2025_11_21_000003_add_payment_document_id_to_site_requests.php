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
        Schema::table('site_requests', function (Blueprint $table) {
            $table->foreignId('payment_document_id')
                ->nullable()
                ->after('template_id')
                ->constrained('payment_documents')
                ->onDelete('set null')
                ->comment('Быстрый доступ к платежу, если платеж создан из одной заявки');
            
            $table->index('payment_document_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('site_requests', function (Blueprint $table) {
            $table->dropForeign(['payment_document_id']);
            $table->dropIndex(['payment_document_id']);
            $table->dropColumn('payment_document_id');
        });
    }
};

