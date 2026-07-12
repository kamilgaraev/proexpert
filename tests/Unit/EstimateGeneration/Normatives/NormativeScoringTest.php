<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeScoring;
use PHPUnit\Framework\TestCase;

final class NormativeScoringTest extends TestCase
{
    public function test_normalizes_different_scales_with_null_semantic_and_canonical_ties(): void
    {
        $ranked = (new NormativeScoring)->rank([
            ['id' => 'b', 'lexical' => 100.0, 'semantic' => null],
            ['id' => 'c', 'lexical' => 0.0, 'semantic' => 1000.0],
            ['id' => 'a', 'lexical' => 100.0, 'semantic' => null],
        ]);

        self::assertSame(['c', 'a', 'b'], array_column($ranked, 'id'));
        self::assertSame('normative-combined-v1', NormativeScoring::VERSION);
    }
}
