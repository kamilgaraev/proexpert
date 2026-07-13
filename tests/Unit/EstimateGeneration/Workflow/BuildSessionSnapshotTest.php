<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class BuildSessionSnapshotTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
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
        self::assertSame(['apply', 'review'], array_column($snapshot->availableActions, 'action'));
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
    public function ready_session_exposes_export_only_from_the_server_permission_contract(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::ReadyToApply),
            permissions: ['estimate_generation.view', 'estimate_generation.apply', 'estimate_generation.export'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame(['apply', 'review', 'export'], array_column($snapshot->availableActions, 'action'));
        self::assertSame('GET', $snapshot->availableActions[2]['method']);
        self::assertSame('/api/v1/admin/projects/17/estimate-generation/sessions/41/export', $snapshot->availableActions[2]['endpoint']);
        self::assertFalse($snapshot->availableActions[2]['requires_confirmation']);
    }

    #[Test]
    public function next_action_is_null_when_permission_is_missing(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::ReadyToApply),
            permissions: [],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame([], $snapshot->availableActions);
        self::assertNull($snapshot->nextAction);
    }

    #[Test]
    public function unevaluated_readiness_never_exposes_apply(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::ReadyToApply),
            permissions: ['estimate_generation.apply', 'estimate_generation.view'],
            readinessEvaluated: false,
        );

        self::assertFalse($snapshot->readinessEvaluated);
        self::assertSame(['review'], array_column($snapshot->availableActions, 'action'));
        self::assertSame('review', $snapshot->nextAction);
    }

    #[Test]
    public function review_action_uses_the_same_view_permission_as_its_get_route(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::EstimateReviewRequired),
            permissions: ['estimate_generation.view'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame(['review'], array_column($snapshot->availableActions, 'action'));
        self::assertSame('GET', $snapshot->availableActions[0]['method']);
        self::assertSame('review', $snapshot->nextAction);
    }

    #[Test]
    public function input_review_exposes_confirm_and_cancel_with_their_route_contracts(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::InputReviewRequired),
            permissions: ['estimate_generation.review', 'estimate_generation.generate'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame(['confirm_input', 'retry', 'cancel'], array_column($snapshot->availableActions, 'action'));
        self::assertSame(['POST', 'POST', 'POST'], array_column($snapshot->availableActions, 'method'));
        self::assertSame([
            '/api/v1/admin/projects/17/estimate-generation/sessions/41/confirm-input',
            '/api/v1/admin/projects/17/estimate-generation/sessions/41/retry',
            '/api/v1/admin/projects/17/estimate-generation/sessions/41/cancel',
        ], array_column($snapshot->availableActions, 'endpoint'));
    }

    #[Test]
    public function failed_session_exposes_retry_cancel_and_archive(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::Failed),
            permissions: ['estimate_generation.generate'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame(['retry', 'cancel', 'archive'], array_column($snapshot->availableActions, 'action'));
    }

    #[Test]
    public function applied_and_cancelled_sessions_expose_only_archive(): void
    {
        foreach ([EstimateGenerationStatus::Applied, EstimateGenerationStatus::Cancelled] as $status) {
            $snapshot = app(BuildSessionSnapshot::class)->handle(
                session: $this->makeSession($status),
                permissions: ['estimate_generation.generate'],
                readinessSummary: ['blockers' => [], 'warnings' => []],
            );

            self::assertSame(['archive'], array_column($snapshot->availableActions, 'action'));
        }
    }

    #[Test]
    public function archived_session_never_exposes_actions(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::Archived),
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
            permissions: ['estimate_generation.view'],
            readinessSummary: [
                'blockers' => [['code' => 'prices_require_review', 'message' => 'Проверьте цены']],
                'warnings' => [['code' => 'quantity', 'message' => 'Проверьте объёмы']],
                'metrics' => ['review_items_total' => 3, 'review_items_blocking' => 1],
            ],
            documentsSummary: ['total_count' => 2, 'ready_count' => 2],
        );

        self::assertSame([
            'id', 'status', 'processing_stage', 'processing_progress', 'state_version',
            'object_input',
            'available_actions', 'blocking_issues', 'warnings', 'next_action',
            'readiness_evaluated',
            'documents_summary', 'estimate_summary', 'review_summary',
            'applied_estimate_id', 'updated_at',
        ], array_keys($snapshot->toArray()));
        self::assertSame(['total_count' => 2, 'ready_count' => 2], $snapshot->documentsSummary);
        self::assertSame(['review_items_total' => 3, 'review_items_blocking' => 1], $snapshot->reviewSummary);
        self::assertSame('review', $snapshot->nextAction);
        self::assertSame('capital_repair', $snapshot->objectInput['construction_type']);
    }

    private function makeSession(EstimateGenerationStatus $status): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => 41,
            'project_id' => 17,
            'status' => $status,
            'processing_stage' => 'ready',
            'processing_progress' => 100,
            'state_version' => 9,
            'input_payload' => [
                'schema_version' => 1,
                'construction_type' => 'capital_repair',
                'floors' => 2,
                'height' => 3.1,
            ],
            'draft_payload' => ['quality_summary' => ['total_work_items' => 12]],
            'problem_flags' => [],
            'updated_at' => CarbonImmutable::parse('2026-07-11 12:00:00'),
        ]);
        $session->setRelation('documents', collect());

        return $session;
    }
}
