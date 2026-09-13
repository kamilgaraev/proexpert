<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignReviewCommentResource;
use App\BusinessModules\Features\DesignManagement\Models\DesignReviewCommentIssueMapping;
use App\BusinessModules\Features\DesignManagement\Services\DesignReviewService;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Models\Project;
use Illuminate\Http\Request;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignReviewLegacyIdCompatibilityTest extends TestCase
{
    public function test_legacy_updates_preserve_blocking_and_require_explicit_acceptance(): void
    {
        $actor = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $project->users()->attach($actor->user->id, ['role' => 'project_manager', 'is_active' => true]);
        $this->mock(\App\Domain\Authorization\Services\AuthorizationService::class)->shouldReceive('can')->andReturnTrue();
        $this->mock(\App\Modules\Core\AccessController::class)->shouldReceive('hasModuleAccess')->andReturnTrue();
        $package = \App\BusinessModules\Features\DesignManagement\Models\DesignPackage::query()->create([
            'organization_id' => $actor->organization->id, 'project_id' => $project->id,
            'created_by' => $actor->user->id, 'updated_by' => $actor->user->id,
            'title' => 'Комплект', 'stage' => 'pd', 'status' => 'draft', 'metadata' => [],
        ]);
        $service = app(DesignReviewService::class);
        $issue = $service->createComment($package, $actor->user->id, ['body' => 'Необходимо исправление', 'severity' => 'blocking']);
        self::assertTrue($issue->metadata['blocking']['active']);
        self::assertSame([$issue->id], $service->commentsForPackage($package, ['severity' => 'blocking'])->modelKeys());
        self::assertSame([], $service->commentsForPackage($package, ['severity' => 'warning'])->modelKeys());
        self::assertSame('blocking', (new DesignReviewCommentResource($issue))->toArray(Request::create('/'))['severity']);
        $stale = $issue->fresh();
        try {
            $service->updateComment($issue, $actor->user->id, ['status' => 'accepted', 'body' => 'Не должно сохраниться']);
            self::fail('Acceptance requires submitted correction');
        } catch (\DomainException) {
            self::assertSame('Необходимо исправление', $issue->fresh()->description);
        }
        $review = $service->updateComment($issue->fresh(), $actor->user->id, ['status' => 'resolved', 'response' => 'Исправлено']);
        self::assertSame('ready_for_review', $review->status->value);
        self::assertTrue($review->metadata['blocking']['active']);
        try {
            $service->updateComment($stale, $actor->user->id, ['body' => 'Устаревший текст']);
            self::fail('A stale legacy write must fail');
        } catch (\DomainException $exception) {
            self::assertSame(trans_message('design_issues.errors.stale_revision'), $exception->getMessage());
        }
        $accepted = $service->updateComment($review, $actor->user->id, ['status' => 'accepted']);
        self::assertSame('resolved', $accepted->status->value);
        self::assertSame($issue->id, $accepted->id);
        self::assertSame('Исправлено', $accepted->metadata['legacy_response']);
    }

    public function test_old_comment_id_resolves_mapped_issue_instead_of_quality_defect_primary_key(): void
    {
        $actor = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $other = $this->issue($actor->organization->id, $project->id, $actor->user->id, 'QC-1');
        $mapped = $this->issue($actor->organization->id, $project->id, $actor->user->id, 'PIR-2');
        DesignReviewCommentIssueMapping::query()->create([
            'organization_id' => $actor->organization->id,
            'project_id' => $project->id,
            'legacy_api_id' => 1,
            'legacy_design_review_comment_id' => 1,
            'quality_defect_id' => $mapped->id,
        ]);

        $resolved = app(DesignReviewService::class)->findComment($actor->organization->id, 1);

        self::assertNotNull($resolved);
        self::assertSame($mapped->id, $resolved->id);
        self::assertNotSame($other->id, $resolved->id);
        self::assertSame(1, (new DesignReviewCommentResource($resolved))->toArray(Request::create('/'))['id']);
    }

    private function issue(int $organizationId, int $projectId, int $userId, string $number): QualityDefect
    {
        return QualityDefect::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'kind' => 'project',
            'created_by' => $userId,
            'defect_number' => $number,
            'title' => $number,
            'severity' => 'major',
            'status' => 'open',
            'inspection_required' => false,
            'metadata' => ['design_issue_context' => []],
        ]);
    }
}
