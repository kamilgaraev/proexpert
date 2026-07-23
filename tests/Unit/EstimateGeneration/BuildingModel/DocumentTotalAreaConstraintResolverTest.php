<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DocumentTotalAreaConstraintResolver;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\AnalysisFloorAreaQuantityFactory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentTotalAreaConstraintResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_only_a_consensus_of_current_trusted_quantity_documents(): void
    {
        $resolver = new DocumentTotalAreaConstraintResolver;

        $constraint = $resolver->resolve([
            $this->document(11, 'a', 180.0, 2),
            $this->document(12, 'b', 180.005, 2),
        ]);

        self::assertNotNull($constraint);
        self::assertSame(180.0, $constraint['total_area_m2']);
        self::assertSame(2, $constraint['floor_count']);
        self::assertSame([11, 12], array_column($constraint['sources'], 'document_id'));
    }

    #[Test]
    public function it_fails_closed_for_conflicting_or_degraded_current_area_documents(): void
    {
        $resolver = new DocumentTotalAreaConstraintResolver;

        self::assertNull($resolver->resolve([
            $this->document(11, 'a', 180.0, 2),
            $this->document(12, 'b', 220.0, 2),
        ]));
        self::assertNull($resolver->resolve([
            $this->document(11, 'a', 180.0, 2),
            $this->document(12, 'b', 180.0, 2, qualityLevel: 'poor'),
        ]));
    }

    #[Test]
    public function floor_count_only_context_document_does_not_poison_exact_area_consensus(): void
    {
        $resolver = new DocumentTotalAreaConstraintResolver;
        $context = $this->document(12, 'b', 180.0, 2);
        unset($context['facts_summary']['total_area_m2']);
        $context['facts_summary']['document_understanding']['role_for_estimation'] = 'context_only';
        $context['facts_summary']['document_understanding']['extracted_capabilities']['has_quantities'] = false;

        $constraint = $resolver->resolve([
            $this->document(11, 'a', 180.0, 2),
            $context,
        ]);

        self::assertNotNull($constraint);
        self::assertSame(180.0, $constraint['total_area_m2']);
        self::assertSame(2, $constraint['floor_count']);
        self::assertSame([11], array_column($constraint['sources'], 'document_id'));

        $floorArea = (new AnalysisFloorAreaQuantityFactory)->make([
            'normalized_building_model' => [
                'metrics' => ['floor_count' => 2, 'room_count' => 15],
                'model_version' => 'building-model:v1',
            ],
            'document_total_area' => [
                'amount' => number_format($constraint['total_area_m2'], 6, '.', ''),
                'evidence_id' => 901,
                'confidence' => 0.95,
                'floor_count' => $constraint['floor_count'],
            ],
        ]);

        self::assertNotNull($floorArea);
        self::assertSame([], $floorArea->reviewBlockers);
        $finishFloor = (new WorkItemQuantityMapper)->map('finish.floor', ['floor_area' => $floorArea]);
        self::assertNotNull($finishFloor);
        self::assertSame([], $finishFloor->reviewBlockers);
        self::assertSame(['901'], $finishFloor->evidenceIds);
    }

    #[Test]
    public function stale_or_mismatched_evidence_does_not_match_the_current_consensus(): void
    {
        $resolver = new DocumentTotalAreaConstraintResolver;
        $constraint = $resolver->resolve([$this->document(11, 'a', 180.0, 2)]);

        self::assertNotNull($constraint);
        self::assertFalse($resolver->matchesEvidence($constraint, $this->evidence(21, 11, 'c', 180.0)));
        self::assertFalse($resolver->matchesEvidence($constraint, $this->evidence(22, 11, 'a', 179.0)));
        self::assertTrue($resolver->matchesEvidence($constraint, $this->evidence(23, 11, 'a', 180.0)));
    }

    /** @return array<string, mixed> */
    private function document(
        int $id,
        string $hashCharacter,
        float $area,
        int $floors,
        string $qualityLevel = 'good',
    ): array {
        return [
            'id' => $id,
            'status' => 'ready',
            'quality_level' => $qualityLevel,
            'quality_score' => 0.95,
            'source_version' => 'sha256:'.str_repeat($hashCharacter, 64),
            'facts_summary' => [
                'total_area_m2' => $area,
                'floor_count' => $floors,
                'document_understanding' => [
                    'role_for_estimation' => 'geometry_source',
                    'extracted_capabilities' => ['has_quantities' => true, 'requires_manual_review' => false],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function evidence(int $id, int $documentId, string $hashCharacter, float $area): array
    {
        return [
            'id' => $id,
            'type' => 'source_fact',
            'source_type' => 'document',
            'source_version' => 'sha256:'.str_repeat($hashCharacter, 64),
            'locator' => ['document_id' => $documentId],
            'value' => ['fact_key' => 'area', 'fact_value' => $area, 'unit' => 'm2'],
            'confidence' => 0.95,
            'producer_name' => 'pipeline',
            'producer_version' => 'pipeline:v2',
            'invalidated_at' => null,
        ];
    }
}
