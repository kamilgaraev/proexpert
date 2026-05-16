<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_duplicate_groups', function (Blueprint $table): void {
            if (!Schema::hasColumn('mdm_duplicate_groups', 'match_strategy')) {
                $table->string('match_strategy', 40)->default('exact')->after('fingerprint');
            }
        });

        Schema::create('mdm_merge_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('duplicate_group_id')->constrained('mdm_duplicate_groups')->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('master_entity_id');
            $table->jsonb('duplicate_entity_ids');
            $table->jsonb('dry_run_plan');
            $table->string('status', 40)->default('planned');
            $table->foreignId('applied_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'entity_type', 'status'], 'mdm_merge_runs_status_idx');
        });

        Schema::create('mdm_quality_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('entity_type', 80);
            $table->jsonb('required_fields');
            $table->jsonb('field_weights');
            $table->jsonb('validation_rules')->nullable();
            $table->unsignedTinyInteger('min_acceptable_score')->default(70);
            $table->timestamps();

            $table->unique(['organization_id', 'entity_type'], 'mdm_quality_policies_org_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mdm_quality_policies');
        Schema::dropIfExists('mdm_merge_runs');

        Schema::table('mdm_duplicate_groups', function (Blueprint $table): void {
            if (Schema::hasColumn('mdm_duplicate_groups', 'match_strategy')) {
                $table->dropColumn('match_strategy');
            }
        });
    }
};
