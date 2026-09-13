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
        Schema::create('estimate_finance_execution_allocations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('estimate_id')->constrained()->restrictOnDelete();
            $table->foreignId('allocation_id')->constrained('estimate_finance_allocations')->restrictOnDelete();
            $table->foreignId('performance_act_id')->constrained('contract_performance_acts')->restrictOnDelete();
            $table->string('currency', 3);
            $table->decimal('quantity', 20, 8)->nullable();
            $table->decimal('amount_with_vat', 20, 2);
            $table->decimal('amount_without_vat', 20, 2)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('condition_version');
            $table->string('source_hash', 64);
            $table->jsonb('source_snapshot');
            $table->jsonb('condition_snapshot');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['performance_act_id', 'allocation_id'], 'finance_execution_act_allocation_unique');
            $table->index(['organization_id', 'project_id', 'estimate_id'], 'finance_execution_scope_index');
        });
        DB::statement("ALTER TABLE estimate_finance_execution_allocations
            ADD CONSTRAINT finance_execution_versions_positive CHECK (version > 0 AND condition_version > 0),
            ADD CONSTRAINT finance_execution_currency_valid CHECK (currency ~ '^[A-Z]{3}$'),
            ADD CONSTRAINT finance_execution_amounts_valid CHECK (amount_with_vat >= 0 AND (amount_without_vat IS NULL OR (amount_without_vat >= 0 AND amount_without_vat <= amount_with_vat))),
            ADD CONSTRAINT finance_execution_quantity_valid CHECK (quantity IS NULL OR quantity >= 0),
            ADD CONSTRAINT finance_execution_hash_valid CHECK (source_hash ~ '^[a-f0-9]{64}$')");
        Schema::create('estimate_finance_execution_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_allocation_id')->constrained('estimate_finance_execution_allocations')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->uuid('mutation_id');
            $table->unsignedBigInteger('finance_revision');
            $table->jsonb('before')->nullable();
            $table->jsonb('after');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['execution_allocation_id', 'version'], 'finance_execution_history_version_unique');
        });
        DB::statement('ALTER TABLE estimate_finance_execution_versions ADD CONSTRAINT finance_execution_history_version_positive CHECK (version > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_finance_execution_versions');
        Schema::dropIfExists('estimate_finance_execution_allocations');
    }
};
