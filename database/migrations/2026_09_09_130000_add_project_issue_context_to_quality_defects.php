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
        Schema::table('quality_defects', function (Blueprint $table): void {
            $table->string('kind', 32)->default('construction')->index();
            $table->unsignedBigInteger('legacy_design_review_comment_id')->nullable()->unique();
        });

        if (! Schema::hasTable('design_review_comments')) {
            return;
        }

        DB::table('design_review_comments')->orderBy('id')->each(function (object $comment): void {
            if (DB::table('quality_defects')->where('legacy_design_review_comment_id', $comment->id)->exists()) {
                return;
            }

            $status = match ($comment->status) {
                'answered' => 'ready_for_review',
                'rejected' => 'rejected',
                'resolved', 'accepted' => 'resolved',
                default => 'open',
            };
            $severity = match ($comment->severity) {
                'blocking' => 'critical',
                'warning' => 'major',
                default => 'minor',
            };
            $context = array_filter([
                'package_id' => $comment->package_id,
                'section_id' => $comment->section_id,
                'artifact_id' => $comment->artifact_id,
                'version_id' => $comment->version_id,
                'sheet_id' => $comment->sheet_id,
                'bim_element_id' => $comment->bim_element_id,
                'round_id' => $comment->round_id,
            ], static fn (mixed $value): bool => $value !== null);
            $metadata = json_decode((string) ($comment->metadata ?? '{}'), true) ?: [];
            $metadata['design_issue_context'] = $context;
            $metadata['legacy'] = ['design_review_comment_id' => $comment->id, 'status' => $comment->status];
            if ($comment->severity === 'blocking') {
                $metadata['blocking'] = ['active' => ! in_array($status, ['resolved'], true), 'reason' => null];
            }

            $id = DB::table('quality_defects')->insertGetId([
                'organization_id' => $comment->organization_id,
                'project_id' => $comment->project_id,
                'kind' => 'project',
                'legacy_design_review_comment_id' => $comment->id,
                'created_by' => $comment->author_id,
                'assigned_to' => $comment->assignee_id,
                'defect_number' => 'PIR-'.$comment->id,
                'title' => mb_strimwidth((string) $comment->body, 0, 255, ''),
                'description' => $comment->body,
                'severity' => $severity,
                'status' => $status,
                'due_date' => $comment->due_date,
                'inspection_required' => false,
                'resolved_at' => in_array($status, ['resolved'], true) ? $comment->resolved_at : null,
                'verified_at' => $comment->status === 'accepted' ? $comment->resolved_at : null,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => $comment->created_at,
                'updated_at' => $comment->updated_at,
            ]);
            DB::table('quality_defect_status_history')->insert([
                'quality_defect_id' => $id,
                'organization_id' => $comment->organization_id,
                'from_status' => null,
                'to_status' => $status,
                'comment' => $comment->response,
                'changed_by' => $comment->resolved_by ?? $comment->author_id,
                'changed_at' => $comment->resolved_at ?? $comment->created_at,
                'reporting_dimensions' => json_encode(['project_id' => $comment->project_id], JSON_THROW_ON_ERROR),
                'reporting_evidence_refs' => '[]',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('quality_defects', function (Blueprint $table): void {
            $table->dropUnique(['legacy_design_review_comment_id']);
            $table->dropColumn(['legacy_design_review_comment_id', 'kind']);
        });
    }
};
