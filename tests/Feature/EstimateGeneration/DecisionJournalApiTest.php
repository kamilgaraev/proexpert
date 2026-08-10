<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ApplyEstimateDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\EstimateDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\EstimateDecisionRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\RevertEstimateDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DecisionJournalApiTest extends TestCase
{
    #[Test]
    public function apply_is_idempotent_and_rejects_an_optimistic_version_conflict(): void
    {
        $repository = new InMemoryEstimateDecisionRepository;
        $apply = new ApplyEstimateDecision($repository);
        $actor = new ActorContext(10, 20, 30, 'decision-request-0001');

        $first = $apply->handle('42', 'room:area', 0, ['value' => 10.0, 'unit' => 'm2'], ['value' => 12.0, 'unit' => 'm2'], 'Уточнено по плану', $actor);
        $replay = $apply->handle('42', 'room:area', 0, ['value' => 10.0, 'unit' => 'm2'], ['value' => 12.0, 'unit' => 'm2'], 'Уточнено по плану', $actor);

        self::assertSame($first->id, $replay->id);
        self::assertSame(1, $first->version);
        self::assertCount(1, $repository->history('42', 'room:area'));

        $this->expectException(StaleEstimateGenerationState::class);
        $apply->handle('42', 'room:area', 0, $first->after, ['value' => 15.0, 'unit' => 'm2'], 'Устаревшая команда', new ActorContext(10, 20, 30, 'decision-request-0002'));
    }

    #[Test]
    public function revert_appends_an_inverse_decision_and_keeps_audit_immutable(): void
    {
        $repository = new InMemoryEstimateDecisionRepository;
        $applied = (new ApplyEstimateDecision($repository))->handle(
            '42',
            'room:area',
            0,
            ['value' => 10.0, 'unit' => 'm2'],
            ['value' => 12.0, 'unit' => 'm2'],
            'Уточнено по плану',
            new ActorContext(10, 20, 30, 'decision-request-0001'),
        );

        $reverted = (new RevertEstimateDecision($repository))->handle(
            '42',
            'room:area',
            1,
            'Отмена ошибочного решения',
            new ActorContext(10, 20, 30, 'decision-revert-0001'),
        );

        self::assertSame('revert', $reverted->sourceCommand);
        self::assertSame($applied->id, $reverted->revertedDecisionId);
        self::assertSame($applied->before, $reverted->after);
        self::assertSame(2, $reverted->version);
        self::assertCount(2, $repository->history('42', 'room:area'));
        self::assertSame($applied, $repository->history('42', 'room:area')[0]);
    }

    #[Test]
    public function production_reads_current_projection_without_correction_chain_replay(): void
    {
        $root = dirname(__DIR__, 3).'/app/BusinessModules/Addons/EstimateGeneration';
        $controller = file_get_contents($root.'/Http/Controllers/EstimateGenerationProjectModelCorrectionController.php');
        $readModel = file_get_contents($root.'/Http/Presentation/EloquentBuildingModelReadDataSource.php');
        $review = file_get_contents($root.'/Http/Presentation/ProjectModelReviewPayloadService.php');

        self::assertIsString($controller);
        self::assertIsString($readModel);
        self::assertIsString($review);
        self::assertStringContainsString('ApplyEstimateDecision $applyDecision', $controller);
        self::assertStringContainsString('RevertEstimateDecision $revertDecision', $controller);
        self::assertStringContainsString('currentDecisionRows', $readModel);
        self::assertStringContainsString('currentCorrections', $review);
        self::assertStringNotContainsString('ProjectModelCorrectionChainProjector', $controller.$readModel.$review);
    }
}

final class InMemoryEstimateDecisionRepository implements EstimateDecisionRepository
{
    /** @var array<string, list<EstimateDecision>> */
    private array $items = [];

    public function append(
        string $sessionId,
        string $decisionKey,
        int $expectedVersion,
        array $before,
        array $after,
        string $reason,
        ActorContext $actor,
        string $sourceCommand,
        ?int $revertedDecisionId = null,
    ): EstimateDecision {
        $key = $sessionId.'|'.$decisionKey;
        foreach ($this->items[$key] ?? [] as $decision) {
            if ($decision->actor->idempotencyKey === $actor->idempotencyKey) {
                if ($decision->fingerprint() !== EstimateDecision::fingerprintFor($sessionId, $decisionKey, $before, $after, $reason, $sourceCommand, $revertedDecisionId)) {
                    throw new RuntimeException('estimate_decision_idempotency_conflict');
                }

                return $decision;
            }
        }
        $latest = $this->latest($sessionId, $decisionKey);
        $version = $latest?->version ?? 0;
        if ($version !== $expectedVersion) {
            throw new StaleEstimateGenerationState((int) $sessionId, $expectedVersion);
        }
        $decision = new EstimateDecision(
            id: count($this->items[$key] ?? []) + 1,
            sessionId: $sessionId,
            decisionKey: $decisionKey,
            version: $version + 1,
            before: $before,
            after: $after,
            reason: $reason,
            actor: $actor,
            sourceCommand: $sourceCommand,
            occurredAt: '2026-08-10T12:00:00+00:00',
            revertedDecisionId: $revertedDecisionId,
        );
        $this->items[$key][] = $decision;

        return $decision;
    }

    public function latest(string $sessionId, string $decisionKey): ?EstimateDecision
    {
        $items = $this->items[$sessionId.'|'.$decisionKey] ?? [];

        return $items === [] ? null : $items[array_key_last($items)];
    }

    public function history(string $sessionId, string $decisionKey): array
    {
        return $this->items[$sessionId.'|'.$decisionKey] ?? [];
    }
}
