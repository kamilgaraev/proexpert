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
        Schema::create('estimate_finance_cash_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('estimate_id')->constrained()->restrictOnDelete();
            $table->foreignId('allocation_id')->constrained('estimate_finance_allocations')->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions')->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('amount', 20, 2);
            $table->unsignedInteger('version')->default(1);
            $table->string('source_hash', 64);
            $table->jsonb('source_snapshot');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['payment_transaction_id', 'allocation_id'], 'finance_cash_transaction_allocation_unique');
            $table->index(['organization_id', 'project_id', 'estimate_id'], 'finance_cash_scope_index');
        });
        DB::statement("ALTER TABLE estimate_finance_cash_allocations ADD CONSTRAINT finance_cash_version_positive CHECK (version > 0), ADD CONSTRAINT finance_cash_currency_valid CHECK (currency ~ '^[A-Z]{3}$')");
        Schema::create('estimate_finance_cash_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_allocation_id')->constrained('estimate_finance_cash_allocations')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->uuid('mutation_id');
            $table->unsignedBigInteger('finance_revision');
            $table->jsonb('before')->nullable();
            $table->jsonb('after');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['cash_allocation_id', 'version'], 'finance_cash_history_version_unique');
        });
        DB::statement('ALTER TABLE estimate_finance_cash_versions ADD CONSTRAINT finance_cash_history_version_positive CHECK (version > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_finance_cash_versions');
        Schema::dropIfExists('estimate_finance_cash_allocations');
    }
};
