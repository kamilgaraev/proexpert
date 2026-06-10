<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartStatus;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartPayloadProjector;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EpmDataMartPayloadProjectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $loader = new FileLoader(new Filesystem(), dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'lang');
        $translator = new Translator($loader, 'ru');

        $container->instance('translator', $translator);
        $container->instance('config', new Repository([
            'app' => [
                'locale' => 'ru',
                'fallback_locale' => 'ru',
            ],
        ]));
        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });

        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_builds_snapshot_payload_with_management_boundaries_and_aggregates(): void
    {
        $scope = EpmDataMartScope::fromInput(EpmDataMartScope::PROJECT_PORTFOLIO_DASHBOARD, [
            'organization_id' => 7,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'as_of_date' => '2026-06-30',
            'currency' => 'rub',
        ]);

        $payload = $this->portfolioPayload();
        $snapshot = (new EpmDataMartPayloadProjector())->build($scope, $payload);

        $this->assertSame(EpmDataMartStatus::SUCCEEDED, $snapshot->status);
        $this->assertSame(EpmDataMartPayloadProjector::FORMULA_VERSION, $snapshot->formulaVersion);
        $this->assertSame('2026-06-10T12:00:00+03:00', $snapshot->generatedAt);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot->sourceHash);
        $this->assertSame('data_mart', $snapshot->freshness['calculation_source']);
        $this->assertSame('prohelper', $snapshot->sourceRefs['management_source_of_truth']);
        $this->assertSame('freshness_confirmation_only', $snapshot->sourceRefs['external_confirmation']['1c']['role']);
        $this->assertFalse($snapshot->sourceRefs['external_confirmation']['1c']['stores_accounting_duplicate']);
        $this->assertContains('accounting_entries', $snapshot->sourceRefs['excluded']);
        $this->assertContains('tax_accounting', $snapshot->sourceRefs['excluded']);
        $this->assertContains('regulated_reporting', $snapshot->sourceRefs['excluded']);
        $this->assertContains('legal_payroll', $snapshot->sourceRefs['excluded']);
        $this->assertStringNotContainsString('"accounting":"1c"', json_encode($snapshot->sourceRefs, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $snapshot->aggregates);
        $this->assertSame(101, $snapshot->aggregates[0]['project_id']);
        $this->assertSame('RUB', $snapshot->aggregates[0]['currency']);
        $this->assertSame($snapshot->sourceHash, $snapshot->aggregates[0]['source_hash']);
    }

    public function test_projector_detects_partial_and_stale_states(): void
    {
        $scope = EpmDataMartScope::fromInput(EpmDataMartScope::WIP_FORECAST, [
            'organization_id' => 7,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'as_of_date' => '2026-06-30',
        ]);
        $projector = new EpmDataMartPayloadProjector();

        $partial = $projector->build($scope, [
            'summary' => ['freshness_status' => 'actual'],
            'warnings' => ['Часть источников недоступна.'],
            'rows' => [],
            'meta' => ['generated_at' => '2026-06-10T12:00:00+03:00'],
        ]);

        $stale = $projector->build($scope, [
            'summary' => ['freshness_status' => 'stale'],
            'rows' => [],
            'meta' => ['generated_at' => '2026-06-10T12:00:00+03:00'],
        ]);

        $this->assertSame(EpmDataMartStatus::PARTIAL, $partial->status);
        $this->assertSame(EpmDataMartStatus::STALE, $stale->status);
    }

    public function test_error_summary_does_not_expose_stack_trace_sql_or_raw_payload(): void
    {
        $summary = (new EpmDataMartPayloadProjector())->errorSummary(new RuntimeException(
            'SQLSTATE[23505]: raw payload secret stack trace should not be exposed',
        ));
        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertSame('epm_data_mart_recalculation_failed', $summary['code']);
        $this->assertArrayNotHasKey('trace', $summary);
        $this->assertStringNotContainsString('SQLSTATE', $json);
        $this->assertStringNotContainsString('raw payload', $json);
        $this->assertStringNotContainsString('secret', $json);
        $this->assertStringNotContainsString('stack trace', $json);
        $this->assertStringNotContainsString('RuntimeException', $json);
    }

    private function portfolioPayload(): array
    {
        return [
            'summary' => [
                'projects_count' => 1,
                'freshness_status' => 'actual',
            ],
            'totals_by_currency' => [[
                'currency' => 'RUB',
                'revenue' => 1000.0,
                'cost' => 700.0,
                'gross_margin' => 300.0,
            ]],
            'projects' => [[
                'project' => [
                    'id' => 101,
                    'name' => 'Business Center',
                    'status' => 'active',
                ],
                'currency' => 'RUB',
                'risk_level' => 'low',
                'metrics' => [
                    'revenue' => 1000.0,
                    'cost' => 700.0,
                    'gross_margin' => 300.0,
                ],
            ]],
            'freshness' => [
                'status' => 'actual',
                'generated_at' => '2026-06-10T12:00:00+03:00',
            ],
            'source_of_truth' => [
                'portfolio' => [
                    'primary' => 'prohelper_epm_management_aggregates',
                ],
                'external_systems' => [
                    '1c' => 'confirmation_only',
                ],
                'cash_gap' => [
                    'primary' => 'prohelper_payment_calendar_and_cash_gap_forecast',
                    'accounting' => '1c',
                ],
            ],
        ];
    }
}
