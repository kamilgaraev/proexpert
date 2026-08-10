<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationCorpus;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationCorpusRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationExample;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationExampleTrust;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationEstimateRowNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationReleaseGate;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationReviewDecision;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EvaluationCorpusTest extends TestCase
{
    #[Test]
    public function candidate_reviewed_and_rejected_examples_survive_new_corpus_instances(): void
    {
        $repository = new InMemoryEvaluationCorpusRepository;
        $firstRequest = $this->corpus($repository);
        $reviewedSource = 'sha256:'.str_repeat('a', 64);
        $rejectedSource = 'sha256:'.str_repeat('b', 64);
        $candidateSource = 'sha256:'.str_repeat('c', 64);

        $candidate = $firstRequest->addCandidate(
            organizationId: 17,
            sourceVersion: $reviewedSource,
            expectedFacts: [['type' => 'area', 'value' => 120]],
            expectedDecisions: [['field' => 'wall_material', 'value' => 'brick']],
            expectedQuantities: [['key' => 'walls', 'value' => 42]],
            expectedEstimateRows: [['name' => 'Кладка стен', 'code' => 'ГЭСН08-02-001-01', 'unit' => 'м3']],
            contractVersions: ['facts' => 'v2', 'prompt' => 'vision:v3'],
        );
        $firstRequest->addCandidate(17, $rejectedSource, [], [], [], [], ['facts' => 'v2']);
        $firstRequest->addCandidate(17, $candidateSource, [], [], [], [], ['facts' => 'v2']);
        $reviewDecision = new EvaluationReviewDecision(
            EvaluationExampleTrust::Reviewed,
            'system_admin',
            31,
            'Проверено по эталонной смете',
            new DateTimeImmutable('2026-08-10T12:00:00+03:00'),
        );
        $rejectDecision = new EvaluationReviewDecision(
            EvaluationExampleTrust::Rejected,
            'system_admin',
            31,
            'Источник содержит неоднозначные данные',
            new DateTimeImmutable('2026-08-10T12:05:00+03:00'),
        );
        $firstRequest->review(17, $reviewedSource, $reviewDecision);
        $firstRequest->reject(17, $rejectedSource, $rejectDecision);

        $nextRequest = $this->corpus($repository);
        $reviewed = $nextRequest->find(17, $reviewedSource);
        $rejected = $nextRequest->find(17, $rejectedSource);
        $stillCandidate = $nextRequest->find(17, $candidateSource);

        self::assertSame(EvaluationExampleTrust::Candidate, $candidate->trustStatus);
        self::assertSame('08-02-001-01', $candidate->expectedEstimateRows[0]['norm_code']);
        self::assertArrayNotHasKey('raw_payload', $candidate->expectedEstimateRows[0]);
        self::assertSame(EvaluationExampleTrust::Reviewed, $reviewed?->trustStatus);
        self::assertEquals($reviewDecision, $reviewed?->reviewDecision);
        self::assertSame(EvaluationExampleTrust::Rejected, $rejected?->trustStatus);
        self::assertEquals($rejectDecision, $rejected?->reviewDecision);
        self::assertSame(EvaluationExampleTrust::Candidate, $stillCandidate?->trustStatus);
    }

    #[Test]
    public function release_gate_reads_only_reviewed_examples_from_the_persistent_corpus(): void
    {
        $repository = new InMemoryEvaluationCorpusRepository;
        $corpus = $this->corpus($repository);
        $reviewedSource = 'sha256:'.str_repeat('d', 64);
        $candidateSource = 'sha256:'.str_repeat('e', 64);
        $rejectedSource = 'sha256:'.str_repeat('f', 64);
        foreach ([$reviewedSource, $candidateSource, $rejectedSource] as $sourceVersion) {
            $corpus->addCandidate(17, $sourceVersion, [], [], [], [], ['facts' => 'v2']);
        }
        $corpus->review(17, $reviewedSource, new EvaluationReviewDecision(
            EvaluationExampleTrust::Reviewed,
            'system_admin',
            31,
            'Проверено',
            new DateTimeImmutable('2026-08-10T12:00:00+03:00'),
        ));
        $corpus->reject(17, $rejectedSource, new EvaluationReviewDecision(
            EvaluationExampleTrust::Rejected,
            'system_admin',
            31,
            'Отклонено',
            new DateTimeImmutable('2026-08-10T12:05:00+03:00'),
        ));

        $releaseCorpus = (new EvaluationReleaseGate($this->corpus($repository)))->reviewedCorpus(17);

        self::assertCount(1, $releaseCorpus);
        self::assertSame($reviewedSource, $releaseCorpus[0]->sourceVersion);
        self::assertSame(EvaluationExampleTrust::Reviewed, $releaseCorpus[0]->trustStatus);
    }

    #[Test]
    public function split_is_stable_by_source_version_across_organizations(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('9', 64);
        $repository = new InMemoryEvaluationCorpusRepository;
        $corpus = $this->corpus($repository);

        $first = $corpus->addCandidate(17, $sourceVersion, [], [], [], [], ['facts' => 'v2']);
        $second = $corpus->addCandidate(18, $sourceVersion, [], [], [], [], ['facts' => 'v2']);

        self::assertSame($first->split, $second->split);
        self::assertContains($first->split, ['development', 'test']);
    }

    private function corpus(EvaluationCorpusRepository $repository): EvaluationCorpus
    {
        return new EvaluationCorpus(new EvaluationEstimateRowNormalizer, $repository);
    }
}

final class InMemoryEvaluationCorpusRepository implements EvaluationCorpusRepository
{
    /** @var array<string, EvaluationExample> */
    private array $examples = [];

    public function addCandidate(EvaluationExample $example): EvaluationExample
    {
        $key = $this->key($example->organizationId, $example->sourceVersion);
        $existing = $this->examples[$key] ?? null;
        if ($existing instanceof EvaluationExample) {
            if (! hash_equals($existing->fingerprint(), $example->fingerprint())) {
                throw new DomainException('Evaluation source version collision.');
            }

            return $existing;
        }

        return $this->examples[$key] = $example;
    }

    public function find(int $organizationId, string $sourceVersion): ?EvaluationExample
    {
        return $this->examples[$this->key($organizationId, $sourceVersion)] ?? null;
    }

    public function transition(
        int $organizationId,
        string $sourceVersion,
        EvaluationReviewDecision $decision,
    ): EvaluationExample {
        $key = $this->key($organizationId, $sourceVersion);
        $existing = $this->examples[$key] ?? null;
        if (! $existing instanceof EvaluationExample) {
            throw new InvalidArgumentException('Evaluation example was not found.');
        }

        return $this->examples[$key] = $existing->withReviewDecision($decision);
    }

    public function reviewed(int $organizationId): array
    {
        return array_values(array_filter(
            $this->examples,
            static fn (EvaluationExample $example): bool => $example->organizationId === $organizationId
                && $example->trustStatus === EvaluationExampleTrust::Reviewed,
        ));
    }

    private function key(int $organizationId, string $sourceVersion): string
    {
        return $organizationId.':'.$sourceVersion;
    }
}
