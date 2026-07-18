<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionSnapshotData;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionOperationalSnapshotDataTest extends TestCase
{
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
}
