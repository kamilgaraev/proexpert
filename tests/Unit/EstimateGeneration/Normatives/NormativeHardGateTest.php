<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NormativeHardGateTest extends TestCase
{
    #[DataProvider('incompatibilities')]
    public function test_each_closed_compatibility_gate_rejects_candidate(string $field, mixed $value, string $reason): void
    {
        $candidate = $this->candidate([$field => $value]);
        $set = (new NormativeHardGate)->filter($this->intent(), [$candidate]);

        self::assertSame([], $set->candidates);
        self::assertSame([$reason], $set->rejected[0]->reasonCodes);
    }

    public static function incompatibilities(): array
    {
        return [
            'unit' => ['canonicalUnit', 'м3', 'unit_mismatch'],
            'dimension' => ['unitDimension', 'volume', 'unit_dimension_mismatch'],
            'material' => ['material', 'бетон', 'material_mismatch'],
            'technology' => ['technology', 'монолитная', 'technology_mismatch'],
            'structure' => ['structure', 'фундамент', 'structure_mismatch'],
            'section' => ['normativeSection', '06', 'normative_section_mismatch'],
            'object' => ['objectType', 'промышленный', 'object_type_mismatch'],
            'version' => ['datasetVersion', 'v2', 'dataset_version_mismatch'],
            'status' => ['datasetStatus', 'draft', 'dataset_status_mismatch'],
            'region' => ['regionCode', '77', 'region_mismatch'],
        ];
    }

    public function test_unknown_required_compatibility_data_is_rejected_closed(): void
    {
        $set = (new NormativeHardGate)->filter($this->intent(), [$this->candidate(['material' => null])]);

        self::assertSame(['material_unknown'], $set->rejected[0]->reasonCodes);
    }

    public function test_combined_reasons_and_evidence_are_retained(): void
    {
        $set = (new NormativeHardGate)->filter($this->intent(), [$this->candidate([
            'canonicalUnit' => 'м3', 'material' => 'бетон',
        ])]);

        self::assertSame(['unit_mismatch', 'material_mismatch'], $set->rejected[0]->reasonCodes);
        self::assertSame('candidate-1', $set->rejected[0]->candidate->id);
        self::assertNotEmpty($set->rejected[0]->evidence);
    }

    private function intent(): WorkIntentData
    {
        return new WorkIntentData(1, 2, 3, 'work-1', 'кладка стены', 'м2', 'area', 'кирпич', 'кладка', 'стена', '08', 'жилой', 'v1', 'published', '78', new DateTimeImmutable('2026-01-01'), ['doc:1']);
    }

    private function candidate(array $overrides = []): NormativeCandidateData
    {
        $values = array_replace([
            'id' => 'candidate-1', 'normativeId' => 10, 'datasetId' => 20, 'datasetVersion' => 'v1',
            'datasetStatus' => 'published', 'code' => '08-01-001-01', 'name' => 'Кладка стен',
            'canonicalUnit' => 'м2', 'unitDimension' => 'area', 'material' => 'кирпич',
            'technology' => 'кладка', 'structure' => 'стена', 'normativeSection' => '08',
            'objectType' => 'жилой', 'regionCode' => '78', 'validFrom' => new DateTimeImmutable('2025-01-01'),
            'validTo' => null, 'lexicalScore' => 0.8, 'semanticScore' => 0.7,
            'lexicalAlgorithmVersion' => 'lex-v1', 'semanticIndexVersion' => 'sem-v1',
            'sourceEvidence' => ['norm:10'],
        ], $overrides);

        return new NormativeCandidateData(...$values);
    }
}
