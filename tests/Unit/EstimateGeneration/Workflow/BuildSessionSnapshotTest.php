<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class BuildSessionSnapshotTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4) . '/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function ready_session_exposes_only_permitted_actions(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::ReadyToApply),
            permissions: ['estimate_generation.view', 'estimate_generation.apply'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame('ready_to_apply', $snapshot->status->value);
        self::assertSame(['apply'], array_column($snapshot->availableActions, 'action'));
        self::assertSame([], $snapshot->blockingIssues);
        self::assertSame('apply', $snapshot->nextAction);
        self::assertSame([
            'action',
            'label',
            'method',
            'endpoint',
            'requires_confirmation',
        ], array_keys($snapshot->availableActions[0]));
        self::assertSame('/api/v1/admin/projects/17/estimate-generation/sessions/41/apply', $snapshot->availableActions[0]['endpoint']);
    }

    #[Test]
    public function next_action_is_null_when_permission_is_missing(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::ReadyToApply),
            permissions: ['estimate_generation.view'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame([], $snapshot->availableActions);
        self::assertNull($snapshot->nextAction);
    }

    #[Test]
    #[DataProvider('terminalStatuses')]
    public function terminal_sessions_never_expose_mutating_actions(EstimateGenerationStatus $status): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession($status),
            permissions: [
                'estimate_generation.upload_documents',
                'estimate_generation.generate',
                'estimate_generation.review',
                'estimate_generation.apply',
            ],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame([], $snapshot->availableActions);
        self::assertNull($snapshot->nextAction);
    }

    #[Test]
    public function snapshot_has_stable_v2_shape_and_preserves_summaries(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::EstimateReviewRequired),
            permissions: ['estimate_generation.review'],
            readinessSummary: [
                'blockers' => [['code' => 'prices_require_review', 'message' => 'Проверьте цены']],
                'warnings' => [['code' => 'quantity', 'message' => 'Проверьте объёмы']],
                'metrics' => ['review_items_total' => 3, 'review_items_blocking' => 1],
            ],
            documentsSummary: ['total_count' => 2, 'ready_count' => 2],
        );

        self::assertSame([
            'id', 'status', 'processing_stage', 'processing_progress', 'state_version',
            'available_actions', 'blocking_issues', 'warnings', 'next_action',
            'documents_summary', 'estimate_summary', 'review_summary',
            'applied_estimate_id', 'updated_at',
        ], array_keys($snapshot->toArray()));
        self::assertSame(['total_count' => 2, 'ready_count' => 2], $snapshot->documentsSummary);
        self::assertSame(['review_items_total' => 3, 'review_items_blocking' => 1], $snapshot->reviewSummary);
        self::assertSame('review', $snapshot->nextAction);
    }

    /** @return array<string, array{EstimateGenerationStatus}> */
    public static function terminalStatuses(): array
    {
        return [
            'applied' => [EstimateGenerationStatus::Applied],
            'cancelled' => [EstimateGenerationStatus::Cancelled],
            'archived' => [EstimateGenerationStatus::Archived],
        ];
    }

    private function makeSession(EstimateGenerationStatus $status): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession();
        $session->forceFill([
            'id' => 41,
            'project_id' => 17,
            'status' => $status,
            'processing_stage' => 'ready',
            'processing_progress' => 100,
            'state_version' => 9,
            'draft_payload' => ['quality_summary' => ['total_work_items' => 12]],
            'problem_flags' => [],
            'updated_at' => CarbonImmutable::parse('2026-07-11 12:00:00'),
        ]);
        $session->setRelation('documents', collect());

        return $session;
    }
}
