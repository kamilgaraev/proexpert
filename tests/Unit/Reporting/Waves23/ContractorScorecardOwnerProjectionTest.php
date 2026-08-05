<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorScorecardSnapshotMaterializer;
use ReflectionClass;
use Tests\TestCase;

final class ContractorScorecardOwnerProjectionTest extends TestCase
{
    public function test_source_resolver_materializes_each_owner_with_its_own_filter_contract(): void
    {
        $source = file_get_contents(base_path('app/BusinessModules/ContractorMarketplace/Reporting/Scorecard/Services/ContractorScorecardSourceResolver.php'));

        self::assertIsString($source);
        self::assertStringNotContainsString('ReportRunRecord', $source);
        self::assertStringNotContainsString("'filters = ?::jsonb'", $source);
        self::assertStringContainsString("'as_of' => \$query->asOf->format('Y-m-d')", $source);
        self::assertStringContainsString("'period_start' => \$periodFrom", $source);
        self::assertStringContainsString("'period_from' => \$periodFrom", $source);
        self::assertStringContainsString('->materialize(', $source);
    }

    public function test_cohort_bounds_are_strict_and_calendar_aligned(): void
    {
        $service = (new ReflectionClass(ContractorScorecardSnapshotMaterializer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'cohortBounds');

        self::assertSame(['2026-02-01', '2026-02-28'], $method->invoke($service, '2026-02', 'month'));
        self::assertSame(['2026-04-01', '2026-06-30'], $method->invoke($service, '2026-Q2', 'quarter'));
        self::assertSame(['2026-01-01', '2026-12-31'], $method->invoke($service, '2026', 'year'));
    }
}
