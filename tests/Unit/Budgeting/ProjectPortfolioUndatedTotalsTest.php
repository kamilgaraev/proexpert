<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\ProjectPortfolioDashboardFilters;
use App\BusinessModules\Features\Budgeting\Services\ProjectPortfolioDashboardPayloadBuilder;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ProjectPortfolioUndatedTotalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container();
        $container->instance('translator', new Translator(new FileLoader(new Filesystem(), dirname(__DIR__, 3) . '/lang'), 'ru'));
        $container->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_totals_include_hidden_rows_without_mixing_currencies_or_losing_kopecks(): void
    {
        $projects = [1 => ['id' => 1, 'name' => 'Первый'], 2 => ['id' => 2, 'name' => 'Второй']];
        $rows = [];
        foreach ([[1, 'USD', '123456789012345.67'], [2, 'USD', '0.34'], [1, 'RUB', '2100.01'], [99, 'USD', '999.00']] as [$id, $currency, $amount]) {
            $rows[] = [
                'project_id' => $id,
                'currency' => $currency,
                'complete' => false,
                'freshness_status' => 'partial',
                'undated' => ['items_count' => 1, 'totals_by_currency' => [$currency => ['inflow' => '0.00', 'outflow' => $amount]]],
            ];
        }
        $report = (new ProjectPortfolioDashboardPayloadBuilder())->build(
            new ProjectPortfolioDashboardFilters(1, '2026-09-01', '2026-09-30', '2026-09-06', limit: 1),
            $projects,
            ['cash_gap' => ['available' => true, 'rows' => $rows]],
            '2026-09-06T00:00:00+03:00',
        );
        $totals = array_column($report['totals_by_currency'], null, 'currency');
        self::assertCount(1, $report['projects']);
        self::assertSame(2, $totals['USD']['undated']['items_count']);
        self::assertSame('123456789012346.01', $totals['USD']['undated']['totals_by_currency']['USD']['outflow']);
        self::assertSame('2100.01', $totals['RUB']['undated']['totals_by_currency']['RUB']['outflow']);
        self::assertArrayNotHasKey('RUB', $totals['USD']['undated']['totals_by_currency']);
        self::assertFalse($totals['USD']['cash_gap_complete']);
        self::assertSame(0.0, $totals['USD']['cash_gap']);
    }
}
