<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_bank_qr_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('commercial_bank_invoice_id')->unique()->constrained('commercial_bank_invoices')->restrictOnDelete();
            $table->string('status', 30)->default('pending');
            $table->string('qrc_id', 100)->nullable()->unique();
            $table->string('customer_code', 100);
            $table->string('merchant_id', 12);
            $table->string('account_id', 40);
            $table->unsignedBigInteger('amount_minor');
            $table->jsonb('request');
            $table->text('payload')->nullable();
            $table->text('image_base64')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_bank_qr_codes');
    }
};
