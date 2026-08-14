<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Application\Corrections\ApplyProjectFactCorrection;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelValueFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\EstimateDecisionConflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\ProjectModelReadProjection;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class CanonicalProjectModelProductionPathTest extends TestCase
{
    public function test_correction_creates_an_immutable_decision_and_new_current_fact_visible_to_readers(): void
    {
        [$models, $read] = $this->path();
        $before = $read->forScope(10, 20, 30)['facts'][0];

        $decision = (new ApplyProjectModelDecision($models))->apply(
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            sourceVersion: $before->sourceVersion,
            factId: $before->id,
            value: '8.1',
            unit: 'm2',
            actorId: '42',
            reason: 'Проверено по экспликации помещений',
            decisionId: 'decision:manual-area-1',
        );

        self::assertInstanceOf(Decision::class, $decision);
        self::assertSame($before->evidenceIds, $decision->evidenceIds);
        self::assertCount(1, $models->decisions);
        $after = $read->forScope(10, 20, 30);
        self::assertSame(['value' => '8.1', 'unit' => 'm2'], $after['effective_values'][0]['value']);
        self::assertSame(2, $after['facts'][0]->version);
        self::assertSame('user_assumption', $after['facts'][0]->origin);
    }

    public function test_read_projection_is_tenant_scoped_and_exposes_unresolved_conflicts(): void
    {
        [$models, $read] = $this->path();
        $current = $models->currentFacts(10, 20, 30)[0];
        $other = new Fact(
            'fact:conflicting-area', 10, 20, 30, $current->sourceVersion, $current->entityId,
            $current->type, '8.2', 'm2', 0.9, 'document', 'conflicted', $current->evidenceIds,
        );
        $conflictedCurrent = new Fact(
            $current->id, 10, 20, 30, $current->sourceVersion, $current->entityId,
            $current->type, $current->value, $current->unit, $current->confidence,
            $current->origin, 'conflicted', $current->evidenceIds,
        );
        $models->saveSourceModel([], [$conflictedCurrent, $other], array_values($models->evidence), [
            Conflict::between('conflict:area', [$conflictedCurrent, $other], 'value_mismatch'),
        ]);

        self::assertCount(1, $read->forScope(10, 20, 30)['facts']);
        self::assertCount(1, $read->forScope(10, 20, 30)['conflicts']);
        self::assertSame([], $read->forScope(99, 20, 30)['facts']);
    }

    public function test_dialogue_correction_is_value_fenced_and_rejects_stale_replay(): void
    {
        [$models] = $this->path();
        $correction = new ApplyProjectFactCorrection($models, new ApplyProjectModelDecision($models));
        $fingerprint = ProjectModelValueFingerprint::for(['value' => '7.94', 'unit' => 'm2']);

        $result = $correction->apply(
            10, 20, 30, 42, 'sha256:'.str_repeat('a', 64), $fingerprint,
            'fact:room:1:area', ['value' => '8.1', 'unit' => 'm2'],
            'Проверено по экспликации помещений', 'dialogue-request-1', 0,
        );

        self::assertTrue($result['reanalysis_requested']);
        self::assertCount(1, $models->decisions);
        $this->expectException(EstimateDecisionConflict::class);
        $correction->apply(
            10, 20, 30, 42, 'sha256:'.str_repeat('a', 64), $fingerprint,
            'fact:room:1:area', ['value' => '8.2', 'unit' => 'm2'],
            'Устаревшее повторное изменение', 'dialogue-request-2', 0,
        );
    }

    /** @return array{InMemoryProjectModelRepository,ProjectModelReadProjection} */
    private function path(): array
    {
        $models = new InMemoryProjectModelRepository;
        $sourceVersion = 'sha256:'.str_repeat('a', 64);
        $evidence = new Evidence(
            'evidence:1', 10, 20, 30, $sourceVersion, 'document:1', 'document', 1,
        );
        $models->saveSourceModel(
            [new Entity('room:1', 10, 20, 30, $sourceVersion, 'room', 'room:1')],
            [new Fact(
                'fact:room:1:area', 10, 20, 30, $sourceVersion, 'room:1', 'area', '7.94', 'm2',
                1.0, 'document', 'confirmed', [$evidence->id],
            )],
            [$evidence],
        );

        return [$models, new ProjectModelReadProjection($models)];
    }
}
