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
        Schema::create('material_consumption_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('work_type_id')->constrained('work_types')->restrictOnDelete();
            $table->foreignId('estimate_item_id')->nullable()->constrained('estimate_items')->nullOnDelete();
            $table->foreignId('work_unit_id')->constrained('measurement_units')->restrictOnDelete();
            $table->foreignId('material_unit_id')->constrained('measurement_units')->restrictOnDelete();
            $table->decimal('quantity_per_work_unit', 18, 6);
            $table->unsignedInteger('version_number')->default(1);
            $table->string('status', 32)->default('draft');
            $table->string('basis_kind', 32);
            $table->text('basis_text');
            $table->unsignedBigInteger('basis_document_id')->nullable();
            $table->unsignedBigInteger('work_type_material_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('idempotency_key', 128);
            $table->char('payload_hash', 64);
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'idempotency_key'], 'mcr_idempotency');
            $table->index(['organization_id', 'material_id', 'work_type_id'], 'mcr_lookup_idx');
        });

        Schema::table('material_consumption_rates', function (Blueprint $table): void {
            $table->foreign('basis_document_id')->references('id')->on('executive_documents')->nullOnDelete();
            $table->foreign('work_type_material_id')->references('id')->on('work_type_materials')->nullOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX mcr_identity_estimate
             ON material_consumption_rates (organization_id, material_id, work_type_id, estimate_item_id, version_number)
             WHERE estimate_item_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX mcr_identity_work_type
             ON material_consumption_rates (organization_id, material_id, work_type_id, version_number)
             WHERE estimate_item_id IS NULL'
        );
        DB::statement(
            'ALTER TABLE material_consumption_rates
             ADD CONSTRAINT mcr_quantity_positive CHECK (quantity_per_work_unit > 0)'
        );
        DB::statement(
            "ALTER TABLE material_consumption_rates
             ADD CONSTRAINT mcr_status_check CHECK (status IN ('draft', 'approved'))"
        );
        DB::statement(
            "ALTER TABLE material_consumption_rates
             ADD CONSTRAINT mcr_basis_kind_check CHECK (basis_kind IN ('gesn', 'project', 'tech_card'))"
        );

        Schema::create('material_consumption_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('completed_work_id')->constrained('completed_works')->restrictOnDelete();
            $table->foreignId('material_id')->constrained('materials')->restrictOnDelete();
            $table->foreignId('rate_id')->nullable()->constrained('material_consumption_rates')->nullOnDelete();
            $table->string('kind', 32);
            $table->decimal('quantity', 18, 6);
            $table->foreignId('unit_id')->constrained('measurement_units')->restrictOnDelete();
            $table->decimal('converted_quantity', 18, 6);
            $table->jsonb('conversion_basis')->nullable();
            $table->unsignedBigInteger('warehouse_movement_id')->nullable();
            $table->string('batch_number', 100)->nullable();
            $table->unsignedBigInteger('quality_document_id')->nullable();
            $table->decimal('site_remainder_quantity', 18, 6)->nullable();
            $table->date('occurred_on');
            $table->text('deviation_reason')->nullable();
            $table->foreignId('agreed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('agreed_at')->nullable();
            $table->string('operation_key', 128);
            $table->char('payload_hash', 64);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'operation_key'], 'mcf_operation_key');
            $table->index(['completed_work_id', 'material_id'], 'mcf_work_material_idx');
        });

        Schema::table('material_consumption_facts', function (Blueprint $table): void {
            $table->foreign('warehouse_movement_id')->references('id')->on('warehouse_movements')->nullOnDelete();
            $table->foreign('quality_document_id')->references('id')->on('executive_documents')->nullOnDelete();
        });

        DB::statement(
            "ALTER TABLE material_consumption_facts
             ADD CONSTRAINT mcf_kind_check CHECK (kind IN ('consumption', 'return'))"
        );
        DB::statement(
            'ALTER TABLE material_consumption_facts
             ADD CONSTRAINT mcf_quantity_positive CHECK (quantity > 0 AND converted_quantity > 0)'
        );

        Schema::create('material_consumption_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('number', 64);
            $table->unsignedInteger('version_number')->default(1);
            $table->string('status', 32)->default('draft');
            $table->string('calculation_version', 64);
            $table->string('idempotency_key', 128);
            $table->char('payload_hash', 64);
            $table->jsonb('snapshot');
            $table->jsonb('totals');
            $table->jsonb('blockers');
            $table->boolean('is_ready')->default(false);
            $table->string('foreman_name', 255)->nullable();
            $table->date('document_date');
            $table->date('performed_at');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('signed_at')->nullable();
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('signed_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['organization_id', 'idempotency_key'], 'mcs_idempotency');
            $table->index(['project_id', 'period_start', 'period_end'], 'mcs_period_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX mcs_identity_with_contract
             ON material_consumption_statements (project_id, contract_id, period_start, period_end, version_number)
             WHERE contract_id IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX mcs_identity_without_contract
             ON material_consumption_statements (project_id, period_start, period_end, version_number)
             WHERE contract_id IS NULL'
        );
        DB::statement(
            "ALTER TABLE material_consumption_statements
             ADD CONSTRAINT mcs_status_check CHECK (status IN ('draft', 'approved', 'signed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('material_consumption_statements');
        Schema::dropIfExists('material_consumption_facts');
        Schema::dropIfExists('material_consumption_rates');
    }
};
