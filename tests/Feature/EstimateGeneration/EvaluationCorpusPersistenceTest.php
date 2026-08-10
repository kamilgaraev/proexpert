<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EloquentEvaluationCorpusRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationCorpus;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationExampleTrust;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationEstimateRowNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationReleaseGate;
use App\BusinessModules\Addons\EstimateGeneration\Evaluation\EvaluationReviewDecision;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EvaluationCorpusPersistenceTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('estimate_generation_evaluation_examples');
        Schema::create('estimate_generation_evaluation_examples', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('source_version');
            $table->json('expected_facts');
            $table->json('expected_decisions');
            $table->json('expected_quantities');
            $table->json('expected_estimate_rows');
            $table->json('contract_versions');
            $table->string('trust_status');
            $table->string('dataset_split');
            $table->string('content_fingerprint');
            $table->string('review_actor_type')->nullable();
            $table->unsignedBigInteger('review_actor_id')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['organization_id', 'source_version']);
        });
    }

    public function test_reviewed_corpus_and_audit_decision_survive_new_repository_instances(): void
    {
        $firstRequest = $this->corpus();
        $reviewedSource = 'sha256:'.str_repeat('a', 64);
        $candidateSource = 'sha256:'.str_repeat('b', 64);
        $rejectedSource = 'sha256:'.str_repeat('c', 64);
        foreach ([$reviewedSource, $candidateSource, $rejectedSource] as $sourceVersion) {
            $firstRequest->addCandidate(17, $sourceVersion, [], [], [], [], ['prompt' => 'vision:v3']);
        }
        $decision = new EvaluationReviewDecision(
            EvaluationExampleTrust::Reviewed,
            'system_admin',
            31,
            'Проверено по эталонной смете',
            new DateTimeImmutable('2026-08-10T12:00:00+03:00'),
        );
        $firstRequest->review(17, $reviewedSource, $decision);
        $firstRequest->reject(17, $rejectedSource, new EvaluationReviewDecision(
            EvaluationExampleTrust::Rejected,
            'system_admin',
            31,
            'Источник отклонён',
            new DateTimeImmutable('2026-08-10T12:05:00+03:00'),
        ));

        $nextRequest = $this->corpus();
        $releaseCorpus = (new EvaluationReleaseGate($nextRequest))->reviewedCorpus(17);

        $this->assertCount(1, $releaseCorpus);
        $this->assertSame($reviewedSource, $releaseCorpus[0]->sourceVersion);
        $this->assertEquals($decision, $releaseCorpus[0]->reviewDecision);
        $this->assertSame(EvaluationExampleTrust::Candidate, $nextRequest->find(17, $candidateSource)?->trustStatus);
        $this->assertSame(EvaluationExampleTrust::Rejected, $nextRequest->find(17, $rejectedSource)?->trustStatus);
        $this->assertSame(3, DB::table('estimate_generation_evaluation_examples')->count());
    }

    public function test_logically_identical_review_retry_is_idempotent_after_persistence_normalization(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('d', 64);
        $firstRequest = $this->corpus();
        $firstRequest->addCandidate(17, $sourceVersion, [], [], [], [], ['prompt' => 'vision:v3']);
        $decision = new EvaluationReviewDecision(
            EvaluationExampleTrust::Reviewed,
            'system_admin',
            31,
            '  Проверено по контрольной выборке  ',
            new DateTimeImmutable('2026-08-10T12:00:00.987654+03:00'),
        );

        $first = $firstRequest->review(17, $sourceVersion, $decision);
        $retry = $this->corpus()->review(17, $sourceVersion, $decision);

        $this->assertSame('Проверено по контрольной выборке', $first->reviewDecision?->reason);
        $this->assertSame(
            '2026-08-10T09:00:00.000000+00:00',
            $first->reviewDecision?->decidedAt->format('Y-m-d\TH:i:s.uP'),
        );
        $this->assertEquals($first->reviewDecision, $retry->reviewDecision);
        $this->assertSame(1, DB::table('estimate_generation_evaluation_examples')->count());
    }

    public function test_logically_different_review_retry_remains_an_immutable_conflict(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('e', 64);
        $corpus = $this->corpus();
        $corpus->addCandidate(17, $sourceVersion, [], [], [], [], ['prompt' => 'vision:v3']);
        $corpus->review(17, $sourceVersion, new EvaluationReviewDecision(
            EvaluationExampleTrust::Reviewed,
            'system_admin',
            31,
            'Проверено по контрольной выборке',
            new DateTimeImmutable('2026-08-10T12:00:00+03:00'),
        ));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Evaluation review decision is immutable.');

        $this->corpus()->review(17, $sourceVersion, new EvaluationReviewDecision(
            EvaluationExampleTrust::Reviewed,
            'system_admin',
            31,
            'Обнаружено расхождение',
            new DateTimeImmutable('2026-08-10T12:00:01+03:00'),
        ));
    }

    private function corpus(): EvaluationCorpus
    {
        return new EvaluationCorpus(
            new EvaluationEstimateRowNormalizer,
            new EloquentEvaluationCorpusRepository(DB::connection()),
        );
    }
}
