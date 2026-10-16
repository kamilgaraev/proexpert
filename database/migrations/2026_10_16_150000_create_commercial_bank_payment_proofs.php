<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_bank_payment_proofs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_account_id')->constrained('organization_commercial_accounts')->restrictOnDelete();
            $table->foreignId('commercial_bank_invoice_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('storage_path', 512);
            $table->string('mime', 80);
            $table->unsignedInteger('size_bytes');
            $table->string('sha256', 64);
            $table->jsonb('package_slugs');
            $table->jsonb('resource_addons');
            $table->timestampTz('submitted_at');
            $table->timestampTz('temporary_expires_at')->index();
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'sha256'], 'bank_proof_file_identity');
            $table->foreign(['commercial_bank_invoice_id', 'organization_id'], 'bank_proof_invoice_tenant')
                ->references(['id', 'organization_id'])->on('commercial_bank_invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_bank_payment_proofs');
    }
};
