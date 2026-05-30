<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_generation_learning_examples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 50);
            $table->string('source_entity_type', 100)->nullable();
            $table->unsignedBigInteger('source_entity_id')->nullable();
            $table->foreignId('estimate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('estimate_item_id')->nullable()->constrained('estimate_items')->nullOnDelete();
            $table->foreignId('generation_session_id')->nullable()->constrained('estimate_generation_sessions')->nullOnDelete();
            $table->foreignId('generation_package_item_id')->nullable()->constrained('estimate_generation_package_items')->nullOnDelete();
            $table->text('work_name');
            $table->string('work_unit', 50)->nullable();
            $table->decimal('work_quantity', 18, 6)->nullable();
            $table->jsonb('work_intent')->nullable();
            $table->foreignId('normative_dataset_version_id')->nullable()->constrained('estimate_dataset_versions')->nullOnDelete();
            $table->foreignId('estimate_norm_id')->nullable()->constrained('estimate_norms')->nullOnDelete();
            $table->string('norm_code', 100);
            $table->text('normative_name')->nullable();
            $table->string('normative_unit', 50)->nullable();
            $table->string('decision_status', 60);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->boolean('is_positive')->default(true);
            $table->decimal('source_quality_score', 5, 4)->nullable();
            $table->jsonb('context_payload')->nullable();
            $table->jsonb('source_refs')->nullable();
            $table->jsonb('quality_flags')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('indexed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'source_type', 'created_at'], 'estimate_generation_learning_source_idx');
            $table->index(['organization_id', 'norm_code'], 'estimate_generation_learning_norm_code_idx');
            $table->index(['organization_id', 'is_positive', 'created_at'], 'estimate_generation_learning_positive_idx');
            $table->index(['organization_id', 'estimate_norm_id'], 'estimate_generation_learning_norm_idx');
            $table->index('generation_session_id', 'estimate_generation_learning_session_idx');
            $table->unique(
                ['source_type', 'source_entity_type', 'source_entity_id', 'norm_code'],
                'estimate_generation_learning_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_learning_examples');
    }
};
