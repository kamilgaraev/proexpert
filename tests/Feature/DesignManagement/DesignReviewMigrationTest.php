<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewComment;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignReviewMigrationTest extends TestCase
{
    public function test_data_migration_preserves_legacy_states_and_ids_without_overwriting_later_changes(): void
    {
        foreach (['design_review_comments', 'quality_defects', 'quality_defect_status_history', 'design_review_comment_issue_mappings'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id, 'project_id' => $project->id,
            'created_by' => $context->user->id, 'updated_by' => $context->user->id,
            'title' => 'Старый комплект', 'project_stage' => 'pd', 'status' => 'issued',
        ]);
        $states = ['open' => 'open', 'answered' => 'ready_for_review', 'rejected' => 'rejected', 'resolved' => 'resolved', 'accepted' => 'resolved'];
        $comments = [];
        foreach ($states as $old => $new) {
            $closed = in_array($old, ['resolved', 'accepted'], true);
            $comments[$old] = DesignReviewComment::query()->create([
                'organization_id' => $context->organization->id, 'project_id' => $project->id,
                'package_id' => $package->id, 'author_id' => $context->user->id,
                'assignee_id' => $context->user->id, 'status' => $old, 'severity' => 'blocking',
                'body' => 'Прежнее замечание '.$old, 'response' => 'Исходный ответ '.$old,
                'due_date' => '2026-01-20', 'resolved_at' => $closed ? '2026-01-10 12:00:00' : null,
                'resolved_by' => $closed ? $context->user->id : null,
                'metadata' => ['original_detail' => $old],
            ])->fresh();
        }
        $originalComments = array_map(static fn (DesignReviewComment $comment): array => $comment->getAttributes(), $comments);
        Schema::shouldReceive('table')->with('quality_defects', Mockery::type(\Closure::class))->andReturnNull();
        Schema::shouldReceive('create')->with('design_review_comment_issue_mappings', Mockery::type(\Closure::class))->andReturnNull();
        Schema::shouldReceive('hasTable')->with('design_review_comments')->andReturnTrue();
        Schema::shouldReceive('hasTable')->with('quality_defects')->andReturnTrue();
        $migration = require database_path('migrations/2026_09_09_130000_add_project_issue_context_to_quality_defects.php');
        $mappingMigration = require database_path('migrations/2026_09_09_130100_create_design_review_comment_issue_mappings.php');
        $migration->up();
        $mappingMigration->up();

        $ids = [];
        foreach ($states as $old => $new) {
            $comment = $comments[$old];
            $issue = DB::table('quality_defects')->where('legacy_design_review_comment_id', $comment->id)->sole();
            $ids[$old] = $issue->id;
            $metadata = json_decode($issue->metadata, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($new, $issue->status);
            $this->assertSame('project', $issue->kind);
            $this->assertSame($comment->body, $issue->description);
            $this->assertSame($context->user->id, $issue->assigned_to);
            $this->assertSame($old, $metadata['legacy']['status']);
            $this->assertSame($old, $metadata['original_detail']);
            $this->assertSame($package->id, $metadata['design_issue_context']['package_id']);
            $this->assertSame(! in_array($old, ['resolved', 'accepted'], true), $metadata['blocking']['active']);
            $this->assertSame($old === 'accepted', $issue->verified_at !== null);
            $history = DB::table('quality_defect_status_history')->where('quality_defect_id', $issue->id)->sole();
            $this->assertSame($new, $history->to_status);
            $this->assertSame($comment->response, $history->comment);
            $this->assertDatabaseHas('design_review_comment_issue_mappings', [
                'legacy_api_id' => $comment->id, 'legacy_design_review_comment_id' => $comment->id,
                'quality_defect_id' => $issue->id, 'organization_id' => $context->organization->id,
                'project_id' => $project->id,
            ]);
        }
        DB::table('quality_defects')->where('id', $ids['open'])->update(['description' => 'Изменено после переноса', 'status' => 'ready_for_review']);
        $migration->up();
        $mappingMigration->up();
        $this->assertSame(5, DB::table('quality_defects')->whereIn('id', $ids)->count());
        $this->assertSame(5, DB::table('quality_defect_status_history')->whereIn('quality_defect_id', $ids)->count());
        $this->assertSame(5, DB::table('design_review_comment_issue_mappings')->whereIn('quality_defect_id', $ids)->count());
        $this->assertSame('Изменено после переноса', DB::table('quality_defects')->where('id', $ids['open'])->value('description'));
        $this->assertSame('ready_for_review', DB::table('quality_defects')->where('id', $ids['open'])->value('status'));
        $this->assertSame($originalComments, array_map(static fn (DesignReviewComment $comment): array => $comment->fresh()->getAttributes(), $comments));
    }
}
