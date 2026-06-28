<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_generation_training_datasets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_system_admin_id')->nullable()->constrained('system_admins')->nullOnDelete();
            $table->text('title');
            $table->string('source_system', 50)->default('grandsmeta');
            $table->string('status', 50)->default('uploaded');
            $table->string('quality_status', 50)->default('pending');
            $table->decimal('source_quality_score', 5, 4)->default(0.8500);
            $table->text('region_name')->nullable();
            $table->text('period_name')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('stats')->nullable();
            $table->jsonb('processing_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'estimate_generation_training_dataset_org_status_idx');
            $table->index(['source_system', 'status'], 'estimate_generation_training_dataset_source_status_idx');
            $table->index('created_by_system_admin_id', 'estimate_generation_training_dataset_admin_idx');
        });

        Schema::create('estimate_generation_training_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_dataset_id')->constrained('estimate_generation_training_datasets')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('file_role', 50);
            $table->string('storage_disk', 50)->default('s3');
            $table->text('storage_path');
            $table->text('original_name');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_hash', 128)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['training_dataset_id', 'file_role'], 'estimate_generation_training_file_role_idx');
            $table->index(['organization_id', 'file_role'], 'estimate_generation_training_file_org_role_idx');
        });

        Schema::create('estimate_generation_training_examples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_dataset_id')->constrained('estimate_generation_training_datasets')->cascadeOnDelete();
            $table->foreignId('estimate_file_id')->nullable()->constrained('estimate_generation_training_files')->nullOnDelete();
            $table->foreignId('learning_example_id')->nullable()->constrained('estimate_generation_learning_examples')->nullOnDelete();
            $table->string('source_row_hash', 128);
            $table->unsignedInteger('row_number')->nullable();
            $table->text('section_name')->nullable();
            $table->text('section_path')->nullable();
            $table->text('work_name');
            $table->string('work_unit', 50)->nullable();
            $table->decimal('work_quantity', 18, 6)->nullable();
            $table->string('norm_code', 100)->nullable();
            $table->text('normative_name')->nullable();
            $table->string('normative_unit', 50)->nullable();
            $table->string('status', 50)->default('pending');
            $table->decimal('quality_score', 5, 4)->nullable();
            $table->jsonb('quality_flags')->nullable();
            $table->jsonb('work_intent')->nullable();
            $table->jsonb('source_refs')->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('indexed_at')->nullable();
            $table->timestamps();

            $table->index(['training_dataset_id', 'status'], 'estimate_generation_training_example_dataset_status_idx');
            $table->index(['training_dataset_id', 'norm_code'], 'estimate_generation_training_example_norm_idx');
            $table->index('learning_example_id', 'estimate_generation_training_example_learning_idx');
            $table->unique(['training_dataset_id', 'source_row_hash'], 'estimate_generation_training_example_row_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_training_examples');
        Schema::dropIfExists('estimate_generation_training_files');
        Schema::dropIfExists('estimate_generation_training_datasets');
    }
};
