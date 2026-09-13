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
        Schema::create('estimate_finance_own_costs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 24);
            $table->foreignId('advance_transaction_id')->nullable()->constrained('advance_account_transactions')->restrictOnDelete();
            $table->unique('advance_transaction_id', 'finance_own_cost_source_unique');
            $table->foreignId('cost_category_id')->constrained('cost_categories')->restrictOnDelete();
            $table->date('expense_date');
            $table->text('basis');
            $table->string('currency', 3);
            $table->decimal('amount', 20, 2);
            $table->decimal('amount_without_vat', 20, 2)->nullable();
            $table->string('vat_mode', 16);
            $table->decimal('vat_rate', 8, 4)->nullable();
            $table->string('status', 16)->default('confirmed');
            $table->unsignedInteger('version')->default(1);
            $table->string('source_hash', 64);
            $table->jsonb('source_snapshot');
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'project_id', 'expense_date'], 'finance_own_cost_scope_index');
        });
        DB::statement("ALTER TABLE estimate_finance_own_costs
            ADD CONSTRAINT finance_own_cost_source_valid CHECK ((source_type = 'manual' AND advance_transaction_id IS NULL) OR (source_type = 'advance_expense' AND advance_transaction_id IS NOT NULL)),
            ADD CONSTRAINT finance_own_cost_amount_valid CHECK (amount > 0 AND (amount_without_vat IS NULL OR (amount_without_vat >= 0 AND amount_without_vat <= amount))),
            ADD CONSTRAINT finance_own_cost_currency_valid CHECK (currency ~ '^[A-Z]{3}$'),
            ADD CONSTRAINT finance_own_cost_state_valid CHECK (version > 0 AND status IN ('confirmed', 'voided') AND length(trim(basis)) > 0),
            ADD CONSTRAINT finance_own_cost_tax_valid CHECK ((vat_mode = 'unknown' AND vat_rate IS NULL AND amount_without_vat IS NULL) OR (vat_mode = 'none' AND vat_rate IS NULL AND amount_without_vat IS NOT NULL AND amount_without_vat = amount) OR (vat_mode IN ('exclusive', 'included') AND vat_rate IS NOT NULL AND vat_rate >= 0 AND vat_rate <= 100 AND amount_without_vat IS NOT NULL))");
        Schema::create('estimate_finance_own_cost_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('own_cost_id')->constrained('estimate_finance_own_costs')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->uuid('mutation_id');
            $table->jsonb('before')->nullable();
            $table->jsonb('after');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['own_cost_id', 'version'], 'finance_own_cost_history_unique');
        });
        Schema::create('estimate_finance_own_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('key')->unique();
            $table->foreignId('own_cost_id')->constrained('estimate_finance_own_costs')->restrictOnDelete();
            $table->foreignId('estimate_id')->constrained()->restrictOnDelete();
            $table->foreignId('allocation_id')->constrained('estimate_finance_allocations')->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->decimal('amount_without_vat', 20, 2)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('source_version');
            $table->unsignedInteger('condition_version');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['own_cost_id', 'allocation_id'], 'finance_own_cost_allocation_unique');
            $table->index('estimate_id', 'finance_own_cost_estimate_index');
        });
        DB::statement('ALTER TABLE estimate_finance_own_cost_allocations ADD CONSTRAINT finance_own_cost_allocation_valid CHECK (version > 0 AND source_version > 0 AND condition_version > 0 AND amount >= 0 AND (amount_without_vat IS NULL OR (amount_without_vat >= 0 AND amount_without_vat <= amount)))');
        Schema::create('estimate_finance_own_cost_allocation_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('own_cost_allocation_id')->constrained('estimate_finance_own_cost_allocations')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->uuid('mutation_id');
            $table->unsignedBigInteger('finance_revision');
            $table->jsonb('before')->nullable();
            $table->jsonb('after');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['own_cost_allocation_id', 'version'], 'finance_own_cost_allocation_history_unique');
        });
        DB::statement('ALTER TABLE estimate_finance_own_cost_versions ADD CONSTRAINT finance_own_cost_history_positive CHECK (version > 0)');
        DB::statement('ALTER TABLE estimate_finance_own_cost_allocation_versions ADD CONSTRAINT finance_own_cost_allocation_history_positive CHECK (version > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_finance_own_cost_allocation_versions');
        Schema::dropIfExists('estimate_finance_own_cost_allocations');
        Schema::dropIfExists('estimate_finance_own_cost_versions');
        Schema::dropIfExists('estimate_finance_own_costs');
    }
};
