<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_limit_checks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_document_id')->nullable()->constrained('payment_documents')->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->string('operation_type', 64);
            $table->string('operation_id', 128)->nullable();
            $table->foreignId('budget_period_id')->nullable()->constrained('budget_periods')->nullOnDelete();
            $table->foreignId('budget_article_id')->nullable()->constrained('budget_articles')->nullOnDelete();
            $table->foreignId('responsibility_center_id')->nullable()->constrained('responsibility_centers')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained('contractors')->nullOnDelete();
            $table->date('period_month');
            $table->string('currency', 3)->default('RUB');
            $table->decimal('requested_amount', 18, 2);
            $table->string('status', 32);
            $table->string('decision', 48);
            $table->text('message');
            $table->string('required_permission')->nullable();
            $table->boolean('accepted')->default(false);
            $table->foreignId('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('overridden_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable();
            $table->jsonb('sources')->nullable();
            $table->jsonb('summary')->nullable();
            $table->jsonb('dimensions')->nullable();
            $table->jsonb('audit_trail')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'payment_document_id'], 'budget_limit_checks_document_idx');
            $table->index(['organization_id', 'period_month', 'status'], 'budget_limit_checks_period_status_idx');
            $table->index(['budget_article_id', 'responsibility_center_id'], 'budget_limit_checks_dimensions_idx');
        });

        Schema::create('budget_limit_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_document_id')->constrained('payment_documents')->cascadeOnDelete();
            $table->foreignId('budget_limit_check_id')->nullable()->constrained('budget_limit_checks')->nullOnDelete();
            $table->foreignId('budget_period_id')->nullable()->constrained('budget_periods')->nullOnDelete();
            $table->foreignId('budget_article_id')->constrained('budget_articles')->restrictOnDelete();
            $table->foreignId('responsibility_center_id')->constrained('responsibility_centers')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained('contractors')->nullOnDelete();
            $table->date('period_month');
            $table->string('currency', 3)->default('RUB');
            $table->decimal('amount', 18, 2);
            $table->string('status', 32);
            $table->timestamp('reserved_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'period_month'], 'budget_limit_reservations_active_idx');
            $table->index(['payment_document_id', 'status'], 'budget_limit_reservations_document_idx');
            $table->index(['budget_article_id', 'responsibility_center_id'], 'budget_limit_reservations_dimensions_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_limit_reservations');
        Schema::dropIfExists('budget_limit_checks');
    }
};
