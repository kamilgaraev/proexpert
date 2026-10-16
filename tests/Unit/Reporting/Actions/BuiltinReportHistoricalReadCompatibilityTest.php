<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Actions;

use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\BuiltinReportHistoricalReadCompatibility;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BuiltinReportHistoricalReadCompatibilityTest extends TestCase
{
    #[DataProvider('manifestChanges')]
    public function test_only_the_exact_subscription_capability_transition_is_read_compatible(string $change, bool $expected): void
    {
        $builtin = new AcceptedProductionBuiltinPublishedReport(new AcceptedProductionCandidateContract);
        $current = $builtin->document();
        $frozen = $current;
        $frozen['capabilities'] = ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false];
        match ($change) {
            'exact' => null,
            'source' => $frozen['versions']['source_schema'] = 'changed',
            'formula' => $frozen['versions']['formula'] = 'changed',
            'permission' => $frozen['permissions']['view'] = ['other.view'],
            'columns' => $frozen['columns'] = array_slice($frozen['columns'], 1),
            'code' => $frozen['code'] = $current['code'] = 'other_report',
            'published_source' => $current['versions']['source_schema'] = 'changed',
            'partial_capabilities' => $frozen['capabilities']['supports_subscriptions'] = true,
            'same_capabilities' => $frozen['capabilities'] = $current['capabilities'],
        };
        $factory = new ReportDefinitionFactory;

        self::assertSame($expected, (new BuiltinReportHistoricalReadCompatibility($builtin))->allows(
            $factory->fromManifest($frozen),
            $factory->fromManifest($current),
        ));
    }

    public static function manifestChanges(): array
    {
        return [
            ['exact', true], ['source', false], ['formula', false], ['permission', false],
            ['columns', false], ['code', false], ['published_source', false],
            ['partial_capabilities', false], ['same_capabilities', false],
        ];
    }
}
