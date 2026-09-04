<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receipt_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('purchase_receipt_id')->nullable()->unique()->constrained('purchase_receipts')->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_type', 32)->default('upd_xml');
            $table->string('status', 32)->default('validated');
            $table->string('original_name', 255);
            $table->string('storage_key', 1024)->unique();
            $table->string('storage_etag', 255);
            $table->string('mime_type', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('format_version', 10);
            $table->string('document_function', 16);
            $table->string('document_number', 1000);
            $table->date('document_date');
            $table->string('seller_inn', 12);
            $table->string('buyer_inn', 12);
            $table->string('currency_code', 3);
            $table->jsonb('validated_snapshot');
            $table->jsonb('validation_warnings')->nullable();
            $table->timestampTz('validated_at');
            $table->timestampTz('attached_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'purchase_order_id', 'sha256'],
                'purchase_receipt_documents_order_sha_unique',
            );
            $table->index(['organization_id', 'purchase_order_id', 'status'], 'purchase_receipt_documents_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_documents');
    }
};
