<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_packages', function (Blueprint $table): void {
            $table->text('project_stage')->default('rd')->after('stage');
            $table->text('object_type')->default('non_linear_non_production')->after('project_stage');
            $table->text('normative_profile_code')->default('rf_rd_gost_21_101_2026')->after('object_type');
            $table->timestampTz('issued_at')->nullable()->after('planned_issue_date');
            $table->foreignId('issued_by')->nullable()->after('issued_at')->constrained('users')->nullOnDelete();

            $table->index(['organization_id', 'project_stage']);
            $table->index(['organization_id', 'normative_profile_code']);
            $table->index(['project_id', 'project_stage']);
        });
    }

    public function down(): void
    {
        Schema::table('design_packages', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'project_stage']);
            $table->dropIndex(['organization_id', 'normative_profile_code']);
            $table->dropIndex(['project_id', 'project_stage']);
            $table->dropConstrainedForeignId('issued_by');
            $table->dropColumn([
                'project_stage',
                'object_type',
                'normative_profile_code',
                'issued_at',
            ]);
        });
    }
};
