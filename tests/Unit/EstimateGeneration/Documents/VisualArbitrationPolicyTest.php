<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\VisualArbitrationPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisualArbitrationPolicyTest extends TestCase
{
    #[Test]
    public function unknown_reason_reduction_is_associative_with_complete_lineage(): void
    {
        $policy = new VisualArbitrationPolicy;
        $accepted = [
            'status' => 'accepted',
            'reason_code' => 'accepted_fixture',
            'supporting_claim_ids' => ['literal:1'],
            'evidence_refs' => ['literal:evidence'],
        ];
        $conditional = [
            'status' => 'conditional',
            'reason_code' => 'needs_confirmation',
            'supporting_claim_ids' => ['construction:1'],
            'evidence_refs' => ['construction:evidence'],
        ];
        $future = [
            'status' => 'accepted',
            'reason_code' => 'future_reason',
            'supporting_claim_ids' => ['risk:1'],
            'evidence_refs' => ['risk:evidence'],
        ];

        $flat = $policy->reduce([$accepted, $conditional, $future]);
        $left = $policy->reduce([$policy->reduce([$accepted, $conditional]), $future]);
        $right = $policy->reduce([$accepted, $policy->reduce([$conditional, $future])]);
        $perClaim = $policy->reduce(array_map(static fn (array $decision): array => $policy->reduce([$decision]), [
            $accepted,
            $conditional,
            $future,
        ]));

        self::assertSame($flat, $left);
        self::assertSame($flat, $right);
        self::assertSame($flat, $perClaim);
        self::assertSame('conditional', $flat['status']);
        self::assertSame('arbitration_reason_unknown', $flat['reason_code']);
        self::assertSame('arbitration_evidence_conflict', $flat['limitation_code']);
        self::assertSame(['construction:1', 'literal:1', 'risk:1'], $flat['supporting_claim_ids']);
        self::assertSame(['construction:evidence', 'literal:evidence', 'risk:evidence'], $flat['evidence_refs']);
        self::assertStringNotContainsString('future_', json_encode($flat, JSON_THROW_ON_ERROR));
    }
}
