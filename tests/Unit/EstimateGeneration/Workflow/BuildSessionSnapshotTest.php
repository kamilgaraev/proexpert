<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Questions\CurrentEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationAnswerRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Questions\EstimateClarificationCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ProjectUnderstandingQuestionProjector;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(EstimateClarificationCatalog::class, new class implements EstimateClarificationCatalog
        {
            public function allCurrent(int $organizationId, int $projectId, int $sessionId): array
            {
                return [];
            }
        });
        $this->app->instance(
            EstimateClarificationAnswerRegistry::class,
            new class implements EstimateClarificationAnswerRegistry
            {
                public function answeredKeys(int $organizationId, int $projectId, int $sessionId): array
                {
                    return [];
                }
            },
        );
        $this->app->forgetInstance(BuildSessionSnapshot::class);
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
    public function processing_documents_recommends_waiting_without_hiding_cancel(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::ProcessingDocuments),
            permissions: ['estimate_generation.generate'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame(['cancel'], array_column($snapshot->availableActions, 'action'));
        self::assertSame('wait_documents', $snapshot->nextAction);
    }

    #[Test]
    public function cancelled_session_recommends_regeneration_instead_of_archive(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::Cancelled),
            permissions: ['estimate_generation.generate'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame(['generate', 'archive'], array_column($snapshot->availableActions, 'action'));
        self::assertSame('generate', $snapshot->nextAction);
    }

    #[Test]
    public function empty_draft_does_not_expose_document_processing_action(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::Draft),
            permissions: ['estimate_generation.upload_documents', 'estimate_generation.generate'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
            documentsSummary: ['total' => 0],
        );

        self::assertSame(
            ['upload_documents', 'cancel'],
            array_column($snapshot->availableActions, 'action'),
        );
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
    public function resumable_document_system_failure_recommends_documents_with_zero_ready_pages(): void
    {
        $session = $this->makeSession(EstimateGenerationStatus::Failed);
        $session->forceFill([
            'resume_status' => EstimateGenerationStatus::ProcessingDocuments,
            'failure_code' => 'document_processing_system_failed',
        ]);

        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $session,
            permissions: ['estimate_generation.generate'],
            readinessSummary: [
                'blockers' => [[
                    'code' => 'document_processing_system_failed',
                    'message_key' => 'estimate_generation.document_processing_system_failed',
                    'message' => 'Не удалось обработать документ.',
                ]],
                'warnings' => [],
            ],
            documentsSummary: [
                'total' => 22,
                'ready' => 0,
                'pending' => 0,
                'action_required' => 0,
                'ignored' => 0,
                'drawing_elements' => 0,
            ],
        );

        self::assertSame('documents', $snapshot->recommendedStep);
        self::assertSame('documents', $snapshot->toArray()['recommended_step']);
        self::assertSame([
            ['id' => 'object', 'available' => true, 'recommended' => false],
            ['id' => 'documents', 'available' => true, 'recommended' => true],
            ['id' => 'ai_questions', 'available' => false, 'recommended' => false],
            ['id' => 'draft', 'available' => false, 'recommended' => false],
            ['id' => 'review', 'available' => false, 'recommended' => false],
        ], $snapshot->workflowSteps);
        self::assertFalse($snapshot->canGenerate);
        self::assertFalse($snapshot->canApply);
    }

    #[Test]
    public function partial_document_success_keeps_ai_questions_unavailable_and_recommends_recovery(): void
    {
        $session = $this->makeSession(EstimateGenerationStatus::Failed);
        $session->forceFill([
            'resume_status' => EstimateGenerationStatus::ProcessingDocuments,
            'failure_code' => 'document_processing_system_failed',
        ]);

        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $session,
            permissions: ['estimate_generation.generate'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
            documentsSummary: [
                'total' => 22,
                'ready' => 7,
                'pending' => 0,
                'action_required' => 0,
                'ignored' => 0,
                'drawing_elements' => 12,
            ],
        );

        self::assertSame('documents', $snapshot->recommendedStep);
        self::assertFalse($snapshot->workflowSteps[2]['available']);
        self::assertFalse($snapshot->workflowSteps[3]['available']);
        self::assertFalse($snapshot->workflowSteps[4]['available']);
    }

    #[Test]
    public function successful_recovery_without_project_questions_recommends_draft(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::InputReviewRequired),
            permissions: ['estimate_generation.review'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
            documentsSummary: [
                'total' => 22,
                'ready' => 22,
                'pending' => 0,
                'action_required' => 0,
                'ignored' => 0,
                'drawing_elements' => 24,
            ],
        );

        self::assertSame('draft', $snapshot->recommendedStep);
        self::assertFalse($snapshot->workflowSteps[2]['recommended']);
        self::assertTrue($snapshot->workflowSteps[3]['available']);
    }

    #[Test]
    public function quarantined_option_keeps_one_question_blocking_draft_until_it_is_answered(): void
    {
        $projector = new ProjectUnderstandingQuestionProjector(static fn (string $key): string => match ($key) {
            'estimate_generation.ai_questions.other' => 'Другое',
            'estimate_generation.ai_questions.leave_unresolved' => 'Оставить нерешённым',
            default => $key,
        });
        $questions = $projector->project([[
            'conflict_id' => 'conflict:wall-material',
            'text' => 'Какой материал наружных стен использовать?',
            'reason' => 'В документах указаны разные материалы наружных стен.',
            'impact' => 'Выбор материала влияет на объёмы и стоимость работ.',
            'recommendation' => 'Выберите подтверждённый материал или оставьте вопрос нерешённым.',
            'fact_ids' => ['fact:wall'],
            'evidence_ids' => ['evidence:page:4'],
            'source_locator' => ['page_numbers' => [4]],
            'options' => [
                ['value' => 'select:fact:wall:valid', 'fact_id' => 'fact:wall', 'label' => 'Газобетон'],
                ['value' => 'select:fact:wall:broken', 'fact_id' => 'fact:wall', 'label' => str_repeat('Я', 161)],
            ],
        ]], 'sha256:'.str_repeat('c', 64));
        self::assertCount(1, $questions);
        $current = new CurrentEstimateClarification(
            $questions[0],
            'sha256:'.str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('d', 64),
            'fact:wall',
        );
        $documentsSummary = [
            'total' => 1,
            'ready' => 1,
            'pending' => 0,
            'action_required' => 0,
            'ignored' => 0,
            'drawing_elements' => 1,
        ];

        $this->bindClarifications([$current], []);
        $session = $this->makeSession(EstimateGenerationStatus::InputReviewRequired);
        $builder = app(BuildSessionSnapshot::class);
        $counter = new \ReflectionMethod($builder, 'unansweredQuestionCount');
        self::assertSame(1, $counter->invoke($builder, $session));
        $blocked = $builder->handle(
            session: $session,
            permissions: ['estimate_generation.review'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
            documentsSummary: $documentsSummary,
        );

        self::assertSame('ai_questions', $blocked->recommendedStep);
        self::assertTrue($blocked->workflowSteps[2]['available']);
        self::assertFalse($blocked->workflowSteps[3]['available']);
        self::assertFalse($blocked->workflowSteps[4]['available']);

        $this->bindClarifications([$current], [$questions[0]->code]);
        $continued = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::InputReviewRequired),
            permissions: ['estimate_generation.review'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
            documentsSummary: $documentsSummary,
        );

        self::assertSame('draft', $continued->recommendedStep);
        self::assertTrue($continued->workflowSteps[3]['available']);
        self::assertTrue($continued->workflowSteps[4]['available']);
    }

    #[Test]
    public function document_requiring_review_recommends_the_available_documents_step(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::InputReviewRequired),
            permissions: [
                'estimate_generation.view',
                'estimate_generation.create',
                'estimate_generation.upload_documents',
                'estimate_generation.generate',
                'estimate_generation.review',
                'estimate_generation.apply',
                'estimate_generation.export',
            ],
            readinessSummary: [
                'blockers' => [[
                    'code' => 'documents_require_review',
                    'message_key' => 'estimate_generation.documents_require_review',
                    'message' => 'Проверьте частичный результат документа.',
                ]],
                'warnings' => [],
                'next_action' => ['code' => 'review_documents'],
            ],
            documentsSummary: [
                'total' => 1,
                'ready' => 0,
                'pending' => 0,
                'action_required' => 1,
                'ignored' => 0,
                'pages' => 22,
            ],
        );
        $payload = $snapshot->toArray();

        self::assertSame('documents', $payload['recommended_step']);
        self::assertSame('review_documents', $payload['next_action']);
        self::assertSame([
            ['id' => 'object', 'available' => true, 'recommended' => false],
            ['id' => 'documents', 'available' => true, 'recommended' => true],
            ['id' => 'ai_questions', 'available' => false, 'recommended' => false],
            ['id' => 'draft', 'available' => false, 'recommended' => false],
            ['id' => 'review', 'available' => false, 'recommended' => false],
        ], $payload['workflow_steps']);
        self::assertCount(1, array_filter(
            $payload['workflow_steps'],
            static fn (array $step): bool => $step['recommended'] && $step['available'],
        ));
    }

    #[Test]
    public function ignored_documents_count_as_terminal_and_unlock_draft_after_questions(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::InputReviewRequired),
            permissions: ['estimate_generation.review'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
            documentsSummary: [
                'total' => 2,
                'ready' => 1,
                'pending' => 0,
                'action_required' => 0,
                'ignored' => 1,
                'drawing_elements' => 4,
            ],
        );

        self::assertSame('draft', $snapshot->recommendedStep);
        self::assertTrue($snapshot->workflowSteps[2]['available']);
        self::assertTrue($snapshot->workflowSteps[4]['available']);
    }

    #[Test]
    public function ready_documents_do_not_require_a_separate_geometry_step(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::InputReviewRequired),
            permissions: ['estimate_generation.review'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
            documentsSummary: [
                'total' => 2,
                'ready' => 2,
                'pending' => 0,
                'action_required' => 0,
                'ignored' => 0,
                'drawing_elements' => 0,
            ],
        );

        self::assertSame('draft', $snapshot->recommendedStep);
        self::assertTrue($snapshot->workflowSteps[2]['available']);
        self::assertTrue($snapshot->workflowSteps[3]['available']);
    }

    #[Test]
    public function generating_session_exposes_safe_restart_and_cancel(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::Generating),
            permissions: ['estimate_generation.generate'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame(['retry', 'cancel'], array_column($snapshot->availableActions, 'action'));
    }

    #[Test]
    public function applied_session_exposes_confirmed_regeneration_without_hiding_archive(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::Applied),
            permissions: ['estimate_generation.generate'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame(['generate', 'archive'], array_column($snapshot->availableActions, 'action'));
        self::assertSame('Сформировать заново', $snapshot->availableActions[0]['label']);
        self::assertTrue($snapshot->availableActions[0]['requires_confirmation']);
        self::assertSame('open_estimate', $snapshot->nextAction);
    }

    #[Test]
    public function cancelled_session_can_be_regenerated_or_archived(): void
    {
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $this->makeSession(EstimateGenerationStatus::Cancelled),
            permissions: ['estimate_generation.generate'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame(['generate', 'archive'], array_column($snapshot->availableActions, 'action'));
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
            documentsSummary: [
                'total' => 1,
                'ready' => 0,
                'pending' => 0,
                'action_required' => 1,
                'ignored' => 0,
            ],
        );

        self::assertSame([], $snapshot->availableActions);
        self::assertNull($snapshot->nextAction);
        self::assertNull($snapshot->recommendedStep);
        self::assertSame([], array_values(array_filter(
            $snapshot->workflowSteps,
            static fn (array $step): bool => $step['recommended'],
        )));
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
            'recommended_step',
            'workflow_steps',
            'readiness_evaluated',
            'documents_summary', 'estimate_summary', 'review_summary',
            'scope_summary',
            'ai_estimate_quota',
            'applied_estimate_id', 'updated_at',
        ], array_keys($snapshot->toArray()));
        self::assertSame(['total_count' => 2, 'ready_count' => 2], $snapshot->documentsSummary);
        self::assertSame(['review_items_total' => 3, 'review_items_blocking' => 1], $snapshot->reviewSummary);
        self::assertSame([
            'included' => 10,
            'purchased' => 0,
            'used' => 0,
            'available' => 10,
            'reservation_status' => null,
        ], $snapshot->aiEstimateQuota);
        self::assertSame('review', $snapshot->nextAction);
        self::assertSame('capital_repair', $snapshot->objectInput['construction_type']);
    }

    #[Test]
    public function simplified_snapshot_preserves_only_the_safe_scope_boundary_after_a_session_action(): void
    {
        $session = $this->makeSession(EstimateGenerationStatus::EstimateReviewRequired);
        $session->draft_payload = [
            'quality_summary' => ['total_work_items' => 12],
            'completeness' => [
                'status' => 'confirmed_scope_only',
                'scopes' => [[
                    'key' => 'heating',
                    'title' => 'Отопление',
                    'state' => 'unresolved',
                    'missing_items' => ['heating.radiators'],
                ]],
            ],
            'budget_scope' => [
                'direct_costs' => 1200.0,
                'overhead' => ['status' => 'not_calculated', 'amount' => null],
                'profit' => ['status' => 'not_calculated', 'amount' => null],
                'commercial_budget' => ['status' => 'not_calculated', 'amount' => null],
                'claim' => 'confirmed_scope_only',
            ],
            'arbiter_review' => [
                'mode' => 'shadow',
                'status' => 'reviewed',
                'outcome' => 'human_review',
                'prompt' => 'must never be returned to the client',
            ],
        ];

        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $session,
            permissions: ['estimate_generation.view'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );

        self::assertSame('confirmed_scope_only', $snapshot->scopeSummary['completeness']['status']);
        self::assertArrayNotHasKey('title', $snapshot->scopeSummary['completeness']['scopes'][0]);
        self::assertArrayNotHasKey('prompt', $snapshot->scopeSummary['arbiter_review']);
    }

    private function makeSession(EstimateGenerationStatus $status): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => 41,
            'organization_id' => 5,
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
            'ai_estimate_quota_snapshot' => [
                'included' => 10,
                'purchased' => 0,
                'used' => 0,
                'available' => 10,
                'reservation_status' => null,
            ],
            'updated_at' => CarbonImmutable::parse('2026-07-11 12:00:00'),
        ]);
        $session->setRelation('documents', collect());

        return $session;
    }

    /**
     * @param  list<CurrentEstimateClarification>  $questions
     * @param  list<string>  $answeredKeys
     */
    private function bindClarifications(array $questions, array $answeredKeys): void
    {
        $this->app->instance(
            EstimateClarificationCatalog::class,
            new class($questions) implements EstimateClarificationCatalog
            {
                public function __construct(private readonly array $questions) {}

                public function allCurrent(int $organizationId, int $projectId, int $sessionId): array
                {
                    return $this->questions;
                }
            },
        );
        $this->app->instance(
            EstimateClarificationAnswerRegistry::class,
            new class($answeredKeys) implements EstimateClarificationAnswerRegistry
            {
                public function __construct(private readonly array $answeredKeys) {}

                public function answeredKeys(int $organizationId, int $projectId, int $sessionId): array
                {
                    return $this->answeredKeys;
                }
            },
        );
        $this->app->forgetInstance(BuildSessionSnapshot::class);
    }
}
