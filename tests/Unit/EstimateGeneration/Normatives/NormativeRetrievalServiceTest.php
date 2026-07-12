<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeCandidateSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class NormativeRetrievalServiceTest extends TestCase
{
    public function test_retrieval_is_scoped_bounded_and_deterministically_ordered(): void
    {
        $source = new class implements NormativeCandidateSource
        {
            public array $scope = [];

            public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
            {
                $this->scope = func_get_args();

                return [$this->candidate('b', 0.8), $this->candidate('a', 0.8), $this->candidate('c', 0.7)];
            }

            private function candidate(string $id, float $score): NormativeCandidateData
            {
                return new NormativeCandidateData($id, 1, 20, 'v1', 'published', '08', 'Кладка', 'м2', 'area', 'кирпич', 'кладка', 'стена', '08', 'жилой', '78', new DateTimeImmutable('2025-01-01'), null, $score, null, 'lex-v1', null, ['norm:1']);
            }
        };
        $service = new NormativeRetrievalService($source, new NormativeHardGate, 2, null);
        $intent = new WorkIntentData(7, 8, 9, 'w', 'кладка', 'м2', 'area', 'кирпич', 'кладка', 'стена', '08', 'жилой', 'v1', 'published', '78', new DateTimeImmutable('2026-01-01'), ['doc:1']);

        $set = $service->retrieve($intent);

        self::assertSame([7, 8, 'v1'], array_slice($source->scope, 0, 3));
        self::assertSame(['a', 'b'], array_map(static fn ($candidate): string => $candidate->id, $set->candidates));
        self::assertNull($set->semanticIndexVersion);
    }
}
