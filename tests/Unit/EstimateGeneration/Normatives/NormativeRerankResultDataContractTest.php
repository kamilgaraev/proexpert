<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Exceptions\NormativeRerankingInvalidResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormativeRerankResultDataContractTest extends TestCase
{
    #[Test]
    public function provider_factory_accepts_only_a_complete_permutation_of_supplied_candidates(): void
    {
        $result = NormativeRerankResultData::fromProviderArray([
            'selected_candidate_id' => 'n-2',
            'ordering' => ['n-2', 'n-1'],
            'explanation_codes' => ['unit_match'],
            'evidence_refs' => ['quantity:floor'],
            'confidence' => 0.8,
            'schema_version' => 'normative-rerank-v1',
        ], ['n-1', 'n-2'], ['quantity:floor']);

        self::assertSame('n-2', $result->selectedCandidateId);
        self::assertSame(['n-2', 'n-1'], $result->ordering);
    }

    #[Test]
    public function provider_factory_rejects_invented_or_missing_candidates(): void
    {
        $this->expectException(NormativeRerankingInvalidResponse::class);

        NormativeRerankResultData::fromProviderArray([
            'selected_candidate_id' => 'invented',
            'ordering' => ['invented'],
            'explanation_codes' => ['unit_match'],
            'evidence_refs' => [],
            'confidence' => 0.8,
            'schema_version' => 'normative-rerank-v1',
        ], ['n-1', 'n-2']);
    }

    #[Test]
    public function provider_factory_rejects_open_schema_and_unbounded_reason_values(): void
    {
        $this->expectException(NormativeRerankingInvalidResponse::class);

        NormativeRerankResultData::fromProviderArray([
            'selected_candidate_id' => 'n-1',
            'ordering' => ['n-1'],
            'explanation_codes' => ['provider_free_text'],
            'evidence_refs' => [],
            'confidence' => 0.8,
            'schema_version' => 'normative-rerank-v1',
            'work_id' => 'oracle',
        ], ['n-1']);
    }
}
