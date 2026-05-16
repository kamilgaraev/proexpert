<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workforce_payroll_periods', function (Blueprint $table): void {
            $table->timestampTz('locked_at')->nullable();
            $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_hash', 128)->nullable();
        });

        Schema::create('workforce_accounting_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 40);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('cost_category_id')->nullable()->constrained('cost_categories')->nullOnDelete();
            $table->string('accounting_account', 80);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['organization_id', 'scope_type', 'scope_id'], 'workforce_accounting_mapping_scope_unique');
            $table->index(['organization_id', 'is_active', 'priority']);
        });

        Schema::create('workforce_export_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('workforce_payroll_periods')->cascadeOnDelete();
            $table->foreignId('supersedes_package_id')->nullable()->constrained('workforce_export_packages')->nullOnDelete();
            $table->string('package_number', 120);
            $table->string('status', 40)->default('created');
            $table->string('source_hash', 128);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'package_number']);
            $table->index(['organization_id', 'payroll_period_id', 'status']);
        });

        Schema::create('workforce_export_package_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('export_package_id')->constrained('workforce_export_packages')->cascadeOnDelete();
            $table->string('file_type', 40);
            $table->string('file_name');
            $table->string('storage_disk', 40)->default('s3');
            $table->text('storage_path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestampsTz();
            $table->unique(['export_package_id', 'file_type'], 'workforce_export_package_file_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workforce_export_package_files');
        Schema::dropIfExists('workforce_export_packages');
        Schema::dropIfExists('workforce_accounting_mappings');

        Schema::table('workforce_payroll_periods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('locked_by_user_id');
            $table->dropColumn(['locked_at', 'source_hash']);
        });
    }
};
