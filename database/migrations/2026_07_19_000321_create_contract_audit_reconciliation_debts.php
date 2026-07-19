<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_audit_reconciliation_debts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('contract_id')->index();
            $table->string('source_type');
            $table->string('source_id');
            $table->string('change_fingerprint', 64);
            $table->decimal('expected_total_amount', 20, 4)->nullable();
            $table->jsonb('entity_context');
            $table->text('last_error');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->uuid('claim_token')->nullable()->index();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('dead_lettered_at')->nullable();
            $table->timestampsTz();
            $table->unique(['source_type', 'source_id', 'change_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_audit_reconciliation_debts');
    }
};
