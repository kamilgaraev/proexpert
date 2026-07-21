<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality\Arbiter;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterReviewContextFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArbiterReviewContextFactoryTest extends TestCase
{
    #[Test]
    public function it_keeps_internal_numeric_evidence_ids_as_verifiable_references(): void
    {
        $context = (new ArbiterReviewContextFactory)->make([
            'completeness' => [
                'status' => 'confirmed_scope_only',
                'scopes' => [['key' => 'heating', 'state' => 'unresolved']],
            ],
            'local_estimates' => [[
                'key' => 'heating',
                'sections' => [['work_items' => [[
                    'metadata' => ['composition_work_key' => 'heating.unit'],
                    'quantity_evidence' => ['evidence_ids' => [17]],
                ]]]],
            ]],
        ]);

        self::assertContains('17', $context['evidence_refs']);
    }
}
