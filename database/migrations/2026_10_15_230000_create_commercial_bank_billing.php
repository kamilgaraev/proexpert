<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_commercial_accounts', function (Blueprint $table): void {
            $table->boolean('bank_auto_invoice')->default(true);
        });
        Schema::table('commercial_payments', fn (Blueprint $table) => $table->unique(['id', 'commercial_order_id'], 'bank_payment_order_unique'));
        Schema::create('commercial_bank_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('commercial_payment_id')->unique()->constrained()->restrictOnDelete();
            $table->string('number', 40)->unique();
            $table->string('status', 30)->default('awaiting_payment')->index();
            $table->bigInteger('amount_minor');
            $table->bigInteger('paid_minor')->default(0);
            $table->bigInteger('refunded_minor')->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->jsonb('snapshot');
            $table->timestampTz('expires_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('last_reminded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['id', 'organization_id']);
            $table->foreign(['commercial_order_id', 'organization_id'], 'bank_invoice_order_tenant')
                ->references(['id', 'organization_id'])->on('commercial_orders')->restrictOnDelete();
            $table->foreign(['commercial_payment_id', 'commercial_order_id'], 'bank_invoice_payment_order')
                ->references(['id', 'commercial_order_id'])->on('commercial_payments')->restrictOnDelete();
        });
        Schema::create('commercial_bank_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('account_id', 64);
            $table->string('transaction_id', 180);
            $table->string('payment_id', 180)->nullable()->index();
            $table->string('direction', 6);
            $table->bigInteger('amount_minor');
            $table->bigInteger('allocated_minor')->default(0);
            $table->string('currency', 3);
            $table->date('booked_on')->index();
            $table->text('purpose');
            $table->string('payer_inn', 12)->nullable();
            $table->string('payer_account', 32)->nullable();
            $table->string('recipient_inn', 12)->nullable();
            $table->string('recipient_account', 32)->nullable();
            $table->string('review_reason', 80)->nullable()->index();
            $table->foreignId('matched_invoice_id')->nullable()->constrained('commercial_bank_invoices')->restrictOnDelete();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();
            $table->unique(['account_id', 'transaction_id'], 'commercial_bank_operation_identity');
            $table->unique(['account_id', 'payment_id', 'direction'], 'commercial_bank_payment_identity');
        });
        Schema::create('commercial_bank_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_bank_operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_bank_invoice_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();
            $table->unique(['commercial_bank_operation_id', 'commercial_bank_invoice_id'], 'commercial_bank_allocation_identity');
        });
        Schema::create('commercial_bank_statements', function (Blueprint $table): void {
            $table->id();
            $table->string('account_id', 64);
            $table->string('statement_id', 180)->nullable();
            $table->date('statement_date');
            $table->boolean('is_periodic')->default(false);
            $table->string('status', 20)->default('new');
            $table->string('failure_code', 80)->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['account_id', 'statement_date'], 'commercial_bank_statement_date');
        });
        Schema::create('commercial_bank_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('event_type', 40);
            $table->string('payment_id', 180)->nullable();
            $table->date('operation_date');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('commercial_bank_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('commercial_bank_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_bank_operation_id')->constrained()->restrictOnDelete();
            $table->foreignId('outgoing_operation_id')->nullable()->unique()->constrained('commercial_bank_operations')->restrictOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('kind', 20);
            $table->string('status', 30)->default('requested')->index();
            $table->bigInteger('amount_minor');
            $table->string('client_idempotency_key', 100);
            $table->text('reason');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['commercial_bank_invoice_id', 'commercial_bank_operation_id', 'client_idempotency_key'], 'commercial_bank_refund_idempotency');
        });
        Schema::create('commercial_bank_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_bank_invoice_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->string('identity', 100)->unique();
            $table->string('status', 30)->default('pending');
            $table->jsonb('snapshot');
            $table->string('storage_path', 512)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestampTz('issued_at');
            $table->timestampTz('notified_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['commercial_bank_invoice_id', 'organization_id'], 'bank_document_invoice_tenant')
                ->references(['id', 'organization_id'])->on('commercial_bank_invoices')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE commercial_bank_invoices ADD CONSTRAINT bank_invoice_amounts CHECK (amount_minor > 0 AND paid_minor >= 0 AND paid_minor <= amount_minor AND refunded_minor >= 0)');
        DB::statement("ALTER TABLE commercial_bank_operations ADD CONSTRAINT bank_operation_amounts CHECK (amount_minor > 0 AND allocated_minor >= 0 AND allocated_minor <= amount_minor AND direction IN ('credit', 'debit'))");
        DB::statement('ALTER TABLE commercial_bank_allocations ADD CONSTRAINT bank_allocation_amount CHECK (amount_minor > 0)');
        DB::statement('ALTER TABLE commercial_bank_refunds ADD CONSTRAINT bank_refund_amount CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        foreach (['commercial_bank_documents', 'commercial_bank_refunds', 'commercial_bank_webhook_events', 'commercial_bank_statements', 'commercial_bank_allocations', 'commercial_bank_operations', 'commercial_bank_invoices'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('organization_commercial_accounts', fn (Blueprint $table) => $table->dropColumn('bank_auto_invoice'));
        Schema::table('commercial_payments', fn (Blueprint $table) => $table->dropUnique('bank_payment_order_unique'));
    }
};
