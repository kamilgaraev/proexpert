<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mdm_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->string('display_name')->nullable();
            $table->string('normalized_key', 255)->nullable();
            $table->unsignedTinyInteger('quality_score')->default(0);
            $table->jsonb('quality_issues')->nullable();
            $table->jsonb('normalized_values')->nullable();
            $table->string('status', 40)->default('active');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('archive_reason')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'entity_type', 'entity_id'], 'mdm_records_entity_unique');
            $table->index(['organization_id', 'entity_type', 'status'], 'mdm_records_status_idx');
            $table->index(['organization_id', 'entity_type', 'normalized_key'], 'mdm_records_normalized_idx');
            $table->index(['organization_id', 'quality_score'], 'mdm_records_quality_idx');
        });

        Schema::create('mdm_duplicate_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->string('fingerprint', 255);
            $table->string('status', 40)->default('open');
            $table->decimal('confidence', 5, 2)->default(0);
            $table->unsignedBigInteger('suggested_master_entity_id')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'entity_type', 'fingerprint'], 'mdm_duplicate_groups_unique');
            $table->index(['organization_id', 'entity_type', 'status'], 'mdm_duplicate_groups_status_idx');
        });

        Schema::create('mdm_duplicate_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('duplicate_group_id')->constrained('mdm_duplicate_groups')->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->string('role', 40)->default('candidate');
            $table->decimal('score', 5, 2)->default(0);
            $table->jsonb('evidence')->nullable();
            $table->timestamps();

            $table->unique(['duplicate_group_id', 'entity_type', 'entity_id'], 'mdm_duplicate_members_unique');
            $table->index(['entity_type', 'entity_id'], 'mdm_duplicate_members_entity_idx');
        });

        Schema::create('mdm_relationships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('target_type', 80);
            $table->unsignedBigInteger('target_id');
            $table->string('relationship_type', 80);
            $table->decimal('strength', 5, 2)->default(1);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'source_type', 'source_id', 'target_type', 'target_id', 'relationship_type'],
                'mdm_relationships_unique'
            );
            $table->index(['organization_id', 'source_type', 'source_id'], 'mdm_relationships_source_idx');
            $table->index(['organization_id', 'target_type', 'target_id'], 'mdm_relationships_target_idx');
        });

        Schema::create('mdm_change_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('mdm_record_id')->nullable()->constrained('mdm_records')->nullOnDelete();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 80);
            $table->jsonb('before_values')->nullable();
            $table->jsonb('after_values')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'entity_type', 'entity_id'], 'mdm_change_logs_entity_idx');
            $table->index(['organization_id', 'action', 'created_at'], 'mdm_change_logs_action_idx');
        });

        Schema::create('mdm_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->string('source', 120)->default('manual');
            $table->string('status', 40)->default('draft');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('accepted_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->jsonb('issues')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'entity_type', 'status'], 'mdm_import_batches_status_idx');
        });

        Schema::create('mdm_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 80);
            $table->string('status', 40)->default('pending');
            $table->jsonb('current_values')->nullable();
            $table->jsonb('proposed_values')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'entity_type', 'status'], 'mdm_change_requests_status_idx');
            $table->index(['entity_type', 'entity_id'], 'mdm_change_requests_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_change_requests');
        Schema::dropIfExists('mdm_import_batches');
        Schema::dropIfExists('mdm_change_logs');
        Schema::dropIfExists('mdm_relationships');
        Schema::dropIfExists('mdm_duplicate_members');
        Schema::dropIfExists('mdm_duplicate_groups');
        Schema::dropIfExists('mdm_records');
    }
};
