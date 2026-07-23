<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionOperationalSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionSnapshotData;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReadinessResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionOperationalSnapshotDataTest extends TestCase
{
    #[Test]
    public function persisted_draft_blocker_replaces_apply_status_and_next_action(): void
    {
        $builder = (new \ReflectionClass(BuildSessionOperationalSnapshot::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BuildSessionOperationalSnapshot::class, 'withPersistedBlockingIssues');
        $ready = new ReadinessResult(
            'ready_to_apply', true, true, [], [], [],
            ['code' => 'apply_draft', 'message_key' => 'apply', 'message' => 'apply'],
        );

        $result = $method->invoke($builder, $ready, [
            'draft_blocking_issues' => json_encode([[
                'code' => 'required_scope_unresolved',
                'message_key' => 'estimate_generation.readiness_required_scope_unresolved',
                'message' => 'Не учтены обязательные работы',
            ]], JSON_THROW_ON_ERROR),
        ]);

        self::assertInstanceOf(ReadinessResult::class, $result);
        self::assertSame('draft_needs_review', $result->status);
        self::assertFalse($result->canApply);
        self::assertSame('review_draft', $result->nextAction['code']);
        self::assertSame(1, $result->metrics['gate_required_scope_unresolved']);
    }

    #[Test]
    public function operational_checkpoint_queries_are_scoped_to_the_active_generation_attempt(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionOperationalSnapshot.php',
        );

        self::assertIsString($source);
        self::assertGreaterThanOrEqual(2, substr_count($source, "->where('generation_attempt_id', \$generationAttemptId)"));
        self::assertStringContainsString('$generationAttemptId = $this->generationAttemptId($session);', $source);
    }

    #[Test]
    public function estimate_summary_aggregates_only_latest_logical_item_revisions(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionOperationalSnapshot.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('items.id IS NULL OR items.id = (', $source);
        self::assertStringContainsString('COALESCE(latest.logical_key, latest.key) = COALESCE(items.logical_key, items.key)', $source);
        self::assertStringContainsString('ORDER BY latest.revision DESC NULLS LAST, latest.id DESC', $source);
        self::assertStringContainsString("COUNT(items.id) FILTER (WHERE items.item_type NOT IN ('operation','resource_note','review_note')) AS items", $source);
    }

    #[Test]
    public function operational_contract_keeps_exact_money_and_only_safe_summaries(): void
    {
        $snapshot = new SessionSnapshotData(
            id: 41,
            projectId: 17,
            status: EstimateGenerationStatus::Generating,
            processingStage: 'match_normatives',
            processingProgress: 57,
            stateVersion: 9,
            operationalVersion: 'sha256:'.str_repeat('a', 64),
            availableActions: [],
            blockingIssues: [],
            warnings: [['code' => 'review', 'message_key' => 'estimate_generation.review']],
            nextAction: 'wait',
            readinessEvaluated: true,
            canGenerate: false,
            canApply: false,
            currentCheckpoint: ['stage' => 'match_normatives', 'status' => 'running', 'attempt' => 2, 'lease_expires_at' => '2026-07-11T12:00:00+00:00', 'lease_expired' => false],
            queueSummary: ['pending' => 3, 'running' => 1],
            recoverySummary: ['recoverable' => 1, 'next_retry_at' => '2026-07-11T12:00:00+00:00'],
            documentsSummary: ['total' => 2, 'ready' => 1],
            estimateSummary: ['items' => 4000000, 'total_cost' => '123456789012345678.12345678', 'currency' => 'RUB'],
            reviewSummary: ['blocking' => 1],
            evidenceSummary: ['active' => 8, 'invalidated' => 1],
            qualitySummary: ['status' => 'review_required'],
            usageSummary: ['attempts' => 4, 'tokens' => 1200, 'cost_amount' => '0.12345678', 'currency' => 'RUB'],
            failureSummary: ['active' => 1, 'categories' => ['recoverable' => 1]],
            appliedEstimateId: null,
            updatedAt: '2026-07-11T12:00:00+00:00',
            objectInput: ['parameters' => []],
        );

        $payload = $snapshot->toArray();

        self::assertSame(17, $payload['project_id']);
        self::assertSame('sha256:'.str_repeat('a', 64), $payload['operational_version']);
        self::assertSame('123456789012345678.12345678', $payload['estimate_summary']['total_cost']);
        self::assertSame('0.12345678', $payload['usage_summary']['cost_amount']);
        self::assertSame(4000000, $payload['estimate_summary']['items']);
        self::assertSame('{}', json_encode($payload['object_input']['parameters'], JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('lease_age_seconds', $payload['current_checkpoint']);
        self::assertSame([], array_intersect(
            ['pages', 'facts', 'text', 'prompt', 'payload', 'storage_path', 'provider_secret'],
            array_keys($payload),
        ));
    }

    #[Test]
    public function scope_summary_exposes_only_the_safe_boundary_without_arbiter_context(): void
    {
        $builder = (new \ReflectionClass(BuildSessionOperationalSnapshot::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(BuildSessionOperationalSnapshot::class, 'scopeSummary');

        $summary = $method->invoke($builder, [
            'scope_completeness' => json_encode([
                'status' => 'confirmed_scope_only',
                'scopes' => [[
                    'key' => 'heating',
                    'title' => 'Отопление',
                    'state' => 'unresolved',
                    'missing_items' => ['heating.radiators'],
                ]],
            ], JSON_THROW_ON_ERROR),
            'scope_budget' => json_encode([
                'direct_costs' => 1200.0,
                'overhead' => ['status' => 'not_calculated', 'amount' => null],
                'profit' => ['status' => 'not_calculated', 'amount' => null],
                'commercial_budget' => ['status' => 'not_calculated', 'amount' => null],
                'claim' => 'confirmed_scope_only',
            ], JSON_THROW_ON_ERROR),
            'scope_arbiter_review' => json_encode([
                'mode' => 'shadow',
                'status' => 'reviewed',
                'outcome' => 'human_review',
                'input_hash' => 'sha256:'.str_repeat('a', 64),
                'prompt' => 'must never be returned to the client',
            ], JSON_THROW_ON_ERROR),
        ]);

        self::assertSame('confirmed_scope_only', $summary['completeness']['status']);
        self::assertSame('heating', $summary['completeness']['scopes'][0]['key']);
        self::assertArrayNotHasKey('title', $summary['completeness']['scopes'][0]);
        self::assertSame('not_calculated', $summary['budget_scope']['overhead']['status']);
        self::assertSame('human_review', $summary['arbiter_review']['outcome']);
        self::assertArrayNotHasKey('prompt', $summary['arbiter_review']);
    }

    #[Test]
    public function operational_snapshot_reads_only_the_safe_scope_fragments_from_the_draft(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/BuildSessionOperationalSnapshot.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString("draft_payload #> '{completeness}', '{}'::jsonb) AS scope_completeness", $source);
        self::assertStringContainsString("draft_payload #> '{budget_scope}', '{}'::jsonb) AS scope_budget", $source);
        self::assertStringContainsString("draft_payload #> '{arbiter_review}', '{}'::jsonb) AS scope_arbiter_review", $source);
    }
}
