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
        Schema::create('design_review_comment_issue_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->unsignedBigInteger('legacy_api_id')->unique();
            $table->unsignedBigInteger('legacy_design_review_comment_id')->nullable()->unique();
            $table->unsignedBigInteger('quality_defect_id')->unique();
            $table->timestamps();
        });

        if (! Schema::hasTable('quality_defects')) {
            return;
        }

        DB::table('quality_defects')->whereNotNull('legacy_design_review_comment_id')->orderBy('id')->each(function (object $issue): void {
            DB::table('design_review_comment_issue_mappings')->updateOrInsert(
                ['quality_defect_id' => $issue->id],
                [
                    'organization_id' => $issue->organization_id,
                    'project_id' => $issue->project_id,
                    'legacy_api_id' => $issue->legacy_design_review_comment_id,
                    'legacy_design_review_comment_id' => $issue->legacy_design_review_comment_id,
                    'created_at' => $issue->created_at,
                    'updated_at' => $issue->updated_at,
                ],
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_review_comment_issue_mappings');
    }
};
