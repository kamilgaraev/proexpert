<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use PHPUnit\Framework\TestCase;

final class NormativeContextPinResolverTest extends TestCase
{
    public function test_explicit_approved_version_and_snapshot_quarter_are_deterministic(): void
    {
        $resolver = new class extends NormativeContextPinResolver
        {
            protected function approved(string $version): bool
            {
                return $version === 'v1';
            }
        };

        $pin = $resolver->resolve(['normative_dataset_version' => 'v1', 'year' => 2026, 'quarter' => 3]);

        self::assertSame('pinned', $pin['status']);
        self::assertSame('2026-07-01', $pin['applicability_date']);
        self::assertSame($pin, $resolver->resolve(['normative_dataset_version' => 'v1', 'year' => 2026, 'quarter' => 3]));
    }

    public function test_missing_explicit_version_is_blocking_without_query(): void
    {
        self::assertSame('review_required', (new NormativeContextPinResolver)->resolve(['business_date' => '2026-01-01'])['status']);
    }
}
