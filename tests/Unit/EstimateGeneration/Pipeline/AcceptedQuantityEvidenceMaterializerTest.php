<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptedQuantityEvidenceMaterializerTest extends TestCase
{
    #[Test]
    public function accepted_quantity_is_idempotent_and_verifiable_only_in_exact_pipeline_scope(): void
    {
        $repository = new InMemoryEvidenceRepository;
        $materializer = new AcceptedQuantityEvidenceMaterializer($repository);
        $context = new PipelineContext(
            30, 10, 20, 1, 'sha256:'.str_repeat('a', 64), 'generating',
            generationAttemptId: '00000000-0000-4000-8000-000000000001',
            baseInputVersion: 'sha256:'.str_repeat('b', 64),
        );
        $quantity = QuantityData::fromArray([
            'key' => 'floor_area', 'unit' => 'm2', 'amount' => '12.000000',
            'formula_key' => 'floor.net_area', 'formula_version' => 'v1',
            'formula_inputs' => ['room-1' => '12.000000'], 'source' => 'evidenced',
            'evidence_ids' => ['1'], 'model_version' => 'building-model:v1',
            'assumptions' => [], 'review_blockers' => [],
        ]);
        $item = ['key' => 'floor-finish', 'quantity' => '12.000000', 'unit' => 'm2'];

        $first = $materializer->materialize($context, $quantity, $item);
        $retry = $materializer->materialize($context, $quantity, $item);
        $accepted = [...$item, 'quantity_evidence_id' => $first->id, 'quantity_evidence_fingerprint' => $first->fingerprint];

        self::assertSame($first->id, $retry->id);
        self::assertTrue((new AcceptedQuantityEvidenceVerifier($repository))->verify($context, $accepted));
        self::assertFalse((new AcceptedQuantityEvidenceVerifier($repository))->verify(
            new PipelineContext(31, 10, 20, 1, $context->inputVersion, 'generating', baseInputVersion: $context->baseInputVersion),
            $accepted,
        ));
        self::assertSame(64, strlen((string) $first->value['formula_hash']));
        self::assertSame(64, strlen((string) $first->value['source_evidence_hash']));
    }
}
