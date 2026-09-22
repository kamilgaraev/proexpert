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
        Schema::create('contract_period_certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('number', 64);
            $table->unsignedInteger('version_number')->default(1);
            $table->string('status', 32)->default('draft');
            $table->string('calculation_version', 64);
            $table->string('idempotency_key', 128);
            $table->char('payload_hash', 64);
            $table->jsonb('source_act_ids');
            $table->jsonb('composition');
            $table->jsonb('snapshot');
            $table->jsonb('totals');
            $table->date('document_date');
            $table->date('performed_at');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('signed_at')->nullable();
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->boolean('has_annulled_acts')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['contract_id', 'idempotency_key'], 'contract_period_certificates_idempotency');
            $table->index(['contract_id', 'period_start', 'period_end'], 'contract_period_certificates_period_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX contract_period_certificates_identity_with_project
             ON contract_period_certificates (contract_id, project_id, period_start, period_end, version_number)
             WHERE project_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX contract_period_certificates_identity_without_project
             ON contract_period_certificates (contract_id, period_start, period_end, version_number)
             WHERE project_id IS NULL'
        );

        Schema::create('contract_period_certificate_acts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('certificate_id')->constrained('contract_period_certificates')->cascadeOnDelete();
            $table->foreignId('act_id')->constrained('contract_performance_acts')->restrictOnDelete();
            $table->string('act_status', 32);
            $table->decimal('amount', 18, 2);
            $table->decimal('vat_amount', 18, 2);
            $table->decimal('vat_rate', 8, 2)->nullable();
            $table->decimal('amount_without_vat', 18, 2)->nullable();
            $table->date('execution_date')->nullable();
            $table->date('document_date')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->boolean('is_binding')->default(false);
            $table->timestampsTz();

            $table->unique(['certificate_id', 'act_id'], 'contract_period_certificate_acts_unique');
        });

        DB::statement(
            'CREATE UNIQUE INDEX contract_period_certificate_acts_binding_unique
             ON contract_period_certificate_acts (act_id)
             WHERE is_binding'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_period_certificate_acts');
        Schema::dropIfExists('contract_period_certificates');
    }
};
