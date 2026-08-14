<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\BoundedSourceVersionHasher;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalProjectModelPipelineProjection;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EvidenceAwarePipelineBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineBaseInputVersion;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EvidenceAwarePipelineBaseInputVersionResolverTest extends TestCase
{
    #[Test]
    public function canonical_project_model_snapshot_is_part_of_projection_version(): void
    {
        $token = 'sha256:'.str_repeat('d', 64);
        $repository = $this->createMock(ProjectModelRepository::class);
        $repository->expects(self::once())->method('snapshotForUnderstanding')->with(10, 20, 30, 10_000)->willReturn([
            'snapshot' => new ProjectModelSnapshot([], [], [], []),
            'token' => $token,
        ]);
        $resolver = new EvidenceAwarePipelineBaseInputVersionResolver(new CanonicalProjectModelPipelineProjection($repository));
        $session = $this->session();
        $documents = $this->documentsProjection($session);
        $projectionVersion = $resolver->fromProjection(
            is_array($session->input_payload) ? $session->input_payload : [],
            $documents,
            10,
            20,
            30,
        );

        self::assertSame(
            PipelineBaseInputVersion::fromProjection($session->input_payload, $documents, $token),
            $projectionVersion,
        );
        self::assertNotSame(
            PipelineBaseInputVersion::fromProjection($session->input_payload, $documents),
            $projectionVersion,
        );
    }

    #[Test]
    public function both_eloquent_consumers_delegate_to_the_shared_evidence_aware_resolver(): void
    {
        $root = dirname(__DIR__, 4);
        $planner = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/EloquentPipelineExecutionPlanner.php');
        $sessionResolver = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/EloquentSessionBaseInputVersionResolver.php');

        self::assertIsString($planner);
        self::assertIsString($sessionResolver);
        self::assertStringContainsString('EvidenceAwarePipelineBaseInputVersionResolver', $planner);
        self::assertStringContainsString('->fromSession(', $planner);
        self::assertStringContainsString('EvidenceAwarePipelineBaseInputVersionResolver', $sessionResolver);
        self::assertStringContainsString('->fromSession(', $sessionResolver);
        self::assertStringNotContainsString('PipelineBaseInputVersion::', $planner);
        self::assertStringNotContainsString('PipelineBaseInputVersion::', $sessionResolver);
    }

    private function session(): EstimateGenerationSession
    {
        $document = new EstimateGenerationDocument([
            'status' => 'ready',
            'checksum_sha256' => str_repeat('a', 64),
            'structured_payload' => [],
            'facts_summary' => ['total_area_m2' => 180],
            'quality_score' => 0.9,
            'quality_level' => 'good',
            'quality_flags' => [],
        ]);
        $document->id = 40;
        foreach (['facts', 'drawingElements', 'quantityTakeoffs', 'scopeInferences'] as $relation) {
            $document->setRelation($relation, new Collection);
        }
        $session = new EstimateGenerationSession([
            'organization_id' => 10,
            'project_id' => 20,
            'input_payload' => ['description' => 'house', 'generation_attempt_id' => 'attempt-a'],
        ]);
        $session->id = 30;
        $session->setRelation('documents', new Collection([$document]));

        return $session;
    }

    /** @return list<array{id: int, source_version: string, status: string, derived_version: string}> */
    private function documentsProjection(EstimateGenerationSession $session): array
    {
        $document = $session->documents->sole();
        $hasher = new BoundedSourceVersionHasher;
        $hasher->start((int) $document->getKey(), [
            'structured_payload' => $document->structured_payload,
            'facts_summary' => $document->facts_summary,
            'quality_score' => $document->quality_score,
            'quality_level' => $document->quality_level,
            'quality_flags' => $document->quality_flags,
        ]);
        $derivedVersions = $hasher->finish();

        return $session->documents->map(static fn (EstimateGenerationDocument $document): array => [
            'id' => (int) $document->getKey(),
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'status' => (string) $document->status,
            'derived_version' => $derivedVersions[(int) $document->getKey()],
        ])->all();
    }
}
