<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Actions;

use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\BuiltinReportHistoricalReadCompatibility;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureBuiltinPublishedReport;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureCandidateContract;
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

        $contract = new ContractSettlementExposureBuiltinPublishedReport(new ContractSettlementExposureCandidateContract);
        self::assertSame($expected, (new BuiltinReportHistoricalReadCompatibility($builtin, $contract))->allows(
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

    #[DataProvider('rendererChanges')]
    public function test_only_the_exact_contract_renderer_transition_preserves_historical_downloads(string $change, bool $expected): void
    {
        $accepted = new AcceptedProductionBuiltinPublishedReport(new AcceptedProductionCandidateContract);
        $contract = new ContractSettlementExposureBuiltinPublishedReport(new ContractSettlementExposureCandidateContract);
        $current = $contract->document();
        self::assertSame('1.1.0', $current['versions']['renderer']);
        $frozen = $current;
        $frozen['versions']['renderer'] = '1.0.0';
        match ($change) {
            'exact' => null,
            'source' => $frozen['versions']['source_schema'] = 'changed',
            'formula' => $frozen['versions']['formula'] = 'changed',
            'permission' => $frozen['permissions']['view'] = ['other.view'],
            'columns' => $frozen['columns'] = array_slice($frozen['columns'], 1),
            'code' => $frozen['code'] = $current['code'] = 'other_report',
            'published_source' => $current['versions']['source_schema'] = 'changed',
            'published_renderer' => $current['versions']['renderer'] = '1.2.0',
            'older_renderer' => $frozen['versions']['renderer'] = '0.9.0',
            'same_renderer' => $frozen['versions']['renderer'] = $current['versions']['renderer'],
        };
        $factory = new ReportDefinitionFactory;
        if ($change === 'exact') {
            self::assertNotSame($factory->fromManifest($frozen)->definitionHash->value, $factory->fromManifest($current)->definitionHash->value);
        }
        self::assertSame($expected, (new BuiltinReportHistoricalReadCompatibility($accepted, $contract))->allows(
            $factory->fromManifest($frozen),
            $factory->fromManifest($current),
        ));
    }

    public static function rendererChanges(): array
    {
        return [
            ['exact', true], ['source', false], ['formula', false], ['permission', false],
            ['columns', false], ['code', false], ['published_source', false],
            ['published_renderer', false], ['older_renderer', false], ['same_renderer', false],
        ];
    }
}
