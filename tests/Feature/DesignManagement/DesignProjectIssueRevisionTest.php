<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Services\QualityDefectService;
use App\Models\Project;
use DomainException;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignProjectIssueRevisionTest extends TestCase
{
    public function test_stale_decisions_cannot_overwrite_acceptance_or_a_later_review_cycle(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $service = app(QualityDefectService::class);
        $issue = $service->create($context->organization->id, $context->user->id, [
            'project_id' => $project->id, 'kind' => 'project', 'title' => 'Параллельная проверка',
            'severity' => 'major', 'inspection_required' => false,
        ]);
        $review = $service->resolve($issue, $context->user->id, []);
        self::assertSame(2, $review->getAttribute('row_version'));
        $stale = $review->fresh();
        $rejected = $service->verify($review, $context->user->id, false, 'Требуется исправление');
        $nextReview = $service->resolve($rejected, $context->user->id, []);
        self::assertSame(4, $nextReview->getAttribute('row_version'));
        $historyCount = $nextReview->statusHistory()->count();

        try {
            $service->verify($stale, $context->user->id, true);
            self::fail('A previous review cycle must be rejected');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('design_issues.errors.stale_revision'), $exception->getMessage());
        }
        self::assertSame(QualityDefectStatusEnum::READY_FOR_REVIEW, $nextReview->fresh()->status);
        self::assertSame($historyCount, $nextReview->statusHistory()->count());

        $stale = $nextReview->fresh();
        $accepted = $service->verify($nextReview, $context->user->id, true);
        self::assertSame(5, $accepted->getAttribute('row_version'));
        try {
            $service->verify($stale, $context->user->id, false, 'Устаревший возврат');
            self::fail('Acceptance must survive a stale rejection');
        } catch (DomainException $exception) {
            self::assertSame(trans_message('design_issues.errors.stale_revision'), $exception->getMessage());
        }
        self::assertSame(QualityDefectStatusEnum::RESOLVED, $accepted->fresh()->status);
        self::assertSame($historyCount + 1, $accepted->statusHistory()->count());
    }
}
