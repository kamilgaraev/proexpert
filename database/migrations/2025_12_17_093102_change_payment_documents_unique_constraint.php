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
        Schema::table('payment_documents', function (Blueprint $table) {
            // Drop the global unique index on document_number
            $table->dropUnique('payment_documents_document_number_unique');
            
            // Add a composite unique index scoped to organization_id
            $table->unique(['organization_id', 'document_number'], 'payment_documents_org_id_doc_num_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_documents', function (Blueprint $table) {
            // Drop the composite unique index
            $table->dropUnique('payment_documents_org_id_doc_num_unique');
            
            // Restore the global unique index
            $table->unique('document_number', 'payment_documents_document_number_unique');
        });
    }
};
