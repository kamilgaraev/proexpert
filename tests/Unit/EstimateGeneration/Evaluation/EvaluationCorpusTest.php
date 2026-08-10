<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationCorpus;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationExampleTrust;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationEstimateRowNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EvaluationCorpusTest extends TestCase
{
    #[Test]
    public function user_correction_is_a_candidate_until_explicit_review(): void
    {
        $corpus = new EvaluationCorpus(new EvaluationEstimateRowNormalizer);
        $sourceVersion = 'sha256:'.str_repeat('a', 64);

        $candidate = $corpus->addCandidate(
            sourceVersion: $sourceVersion,
            expectedFacts: [['type' => 'area', 'value' => 120]],
            expectedDecisions: [['field' => 'wall_material', 'value' => 'brick']],
            expectedQuantities: [['key' => 'walls', 'value' => 42]],
            expectedEstimateRows: [['name' => 'Кладка стен', 'code' => 'ГЭСН08-02-001-01', 'unit' => 'м3']],
            contractVersions: ['facts' => 'v2', 'prompt' => 'vision:v3'],
        );

        self::assertSame(EvaluationExampleTrust::Candidate, $candidate->trustStatus);
        self::assertSame([], $corpus->listReviewed());
        self::assertSame('08-02-001-01', $candidate->expectedEstimateRows[0]['norm_code']);
        self::assertArrayNotHasKey('raw_payload', $candidate->expectedEstimateRows[0]);

        $reviewed = $corpus->review($sourceVersion);

        self::assertSame(EvaluationExampleTrust::Reviewed, $reviewed->trustStatus);
        self::assertSame([$reviewed], $corpus->listReviewed());
    }

    #[Test]
    public function rejected_examples_are_excluded_and_split_is_stable_by_source_version(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('b', 64);
        $first = new EvaluationCorpus(new EvaluationEstimateRowNormalizer);
        $second = new EvaluationCorpus(new EvaluationEstimateRowNormalizer);

        $firstExample = $first->addCandidate($sourceVersion, [], [], [], [], ['facts' => 'v2']);
        $secondExample = $second->addCandidate($sourceVersion, [], [], [], [], ['facts' => 'v2']);

        self::assertSame($firstExample->split, $secondExample->split);
        self::assertContains($firstExample->split, ['development', 'test']);
        self::assertSame(EvaluationExampleTrust::Rejected, $first->reject($sourceVersion)->trustStatus);
        self::assertSame([], $first->listReviewed());
    }
}
