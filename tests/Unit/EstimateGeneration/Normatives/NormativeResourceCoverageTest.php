<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeResourceCoverage;
use PHPUnit\Framework\TestCase;

final class NormativeResourceCoverageTest extends TestCase
{
    public function test_only_exact_full_resource_set_is_complete(): void
    {
        $coverage = new NormativeResourceCoverage;
        $first = ['norm_resource_id' => 11, 'price_id' => 101];
        $second = ['norm_resource_id' => 12, 'price_id' => 102];

        self::assertFalse($coverage->complete(2, ['materials' => [$first]]));
        self::assertTrue($coverage->complete(2, ['materials' => [$first], 'labor' => [$second]]));
        self::assertFalse($coverage->complete(2, ['materials' => [$first, $first], 'labor' => [$second]]));
        self::assertFalse($coverage->complete(0, []));
    }
}
