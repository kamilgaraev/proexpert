<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\DTOs\ProjectPortfolioDashboardFilters;
use App\BusinessModules\Features\Budgeting\Http\Requests\ProjectPortfolioDashboardRequest;
use App\BusinessModules\Features\Budgeting\Services\ProjectPortfolioDashboardPayloadBuilder;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class ProjectPortfolioDashboardApiContractTest extends TestCase
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

        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_request_accepts_portfolio_filters(): void
    {
        $rules = (new ProjectPortfolioDashboardRequest())->rules();

        foreach ([
            'period_start',
            'period_end',
            'as_of_date',
            'project_manager_id',
            'project_status',
            'project_type',
            'responsibility_center_id',
            'currency',
            'limit',
            'top_n',
        ] as $filter) {
            $this->assertArrayHasKey($filter, $rules);
        }
    }

    public function test_payload_contract_contains_portfolio_dashboard_shape(): void
    {
        $payload = $this->builder()->build(
            filters: $this->filters(),
            projects: $this->projects(),
            components: $this->components(),
            generatedAt: '2026-06-09T10:00:00+03:00',
        );

        foreach ([
            'summary',
            'totals_by_currency',
            'projects',
            'risk_flags',
            'problem_flags',
            'filters',
            'source_of_truth',
            'freshness',
            'actions',
            'meta',
        ] as $key) {
            $this->assertArrayHasKey($key, $payload);
        }

        $this->assertSame('2026-06-09T10:00:00+03:00', $payload['meta']['generated_at']);
        $this->assertSame('2026-06-30', $payload['filters']['as_of_date']);
        $this->assertSame(2, $payload['summary']['projects_available']);
        $this->assertSame(2, $payload['summary']['projects_returned']);
        $this->assertSame('RUB', $payload['totals_by_currency'][0]['currency']);
        $this->assertSame(230000.0, $payload['totals_by_currency'][0]['revenue']);
        $this->assertSame(135000.0, $payload['totals_by_currency'][0]['cost']);
        $this->assertSame(95000.0, $payload['totals_by_currency'][0]['gross_margin']);
        $this->assertSame(18000.0, $payload['totals_by_currency'][0]['wip_total']);

        $row = $payload['projects'][0];
        $this->assertSame(7, $row['project']['id']);
        $this->assertSame('Business Center', $row['project']['name']);
        $this->assertSame('active', $row['project']['status']);
        $this->assertSame(31, $row['project']['manager']['id']);
        $this->assertSame('RUB', $row['currency']);
        $this->assertSame('critical', $row['risk_level']);
        $this->assertContains('cash_gap_risk', $row['risk_flags']);
        $this->assertContains('budget_limit_risk', $row['problem_flags']);

        foreach ([
            'revenue',
            'cost',
            'gross_margin',
            'margin_percent',
            'forecast_revenue',
            'forecast_cost',
            'forecast_gross_margin',
            'forecast_margin_percent',
            'wip',
            'wip_total',
            'ftc',
            'eac',
            'ctc',
            'cash_gap',
            'overdue_receivables',
            'overdue_payables',
        ] as $metric) {
            $this->assertArrayHasKey($metric, $row['metrics']);
        }

        $this->assertSame(250000.0, $row['budget']['plan_amount']);
        $this->assertSame(1, $row['budget']['limit_risk']['blocked_count']);
        $this->assertSame(2, $row['budget']['approval_status']['pending_count']);
        $this->assertSame('/budgeting/project-margin?project_id=7&period_start=2026-06-01&period_end=2026-06-30&currency=RUB', $row['drill_down']['margin']['href']);
        $this->assertSame('/api/v1/admin/budgeting/project-margin?project_id=7&period_start=2026-06-01&period_end=2026-06-30&currency=RUB', $row['drill_down']['margin']['api_href']);
        $this->assertSame('budgeting.project_margin', $row['drill_down']['margin']['route_hint']);

        $this->assertContains('external_confirmation_attention', array_column($payload['problem_flags'], 'code'));
        $this->assertContains('cover_project_cash_gap', array_column($payload['actions'], 'code'));
        $this->assertSame('/budgeting/cfo-command-center?project_id=7&period_start=2026-06-01&period_end=2026-06-30&currency=RUB', $payload['actions'][0]['href']);
    }

    public function test_partial_data_returns_flags_without_breaking_shape(): void
    {
        $components = $this->emptyComponents();
        $components['project_margin'] = [
            'available' => false,
            'report' => ['rows' => [], 'warnings' => ['Маржинальность временно недоступна.']],
            'freshness' => ['status' => 'unavailable'],
        ];
        $components['wip_forecast'] = [
            'available' => true,
            'partial_reason' => 'responsibility_center_filter_not_supported',
            'report' => ['rows' => [], 'warnings' => ['Прогноз завершения показан частично.']],
            'freshness' => ['status' => 'partial'],
        ];
        $components['plan_fact']['report']['rows'] = [[
            'project' => ['id' => 7, 'name' => 'Business Center'],
            'currency' => 'RUB',
            'plan_amount' => 100000.0,
            'forecast_amount' => 110000.0,
            'actual_amount' => 90000.0,
            'committed_amount' => 5000.0,
            'variance_amount' => -10000.0,
            'variance_percent' => -10.0,
            'risk_level' => 'medium',
        ]];

        $payload = $this->builder()->build(
            filters: $this->filters(responsibilityCenterId: 10, responsibilityCenterUuid: 'center-uuid'),
            projects: $this->projects(),
            components: $components,
            generatedAt: '2026-06-09T10:00:00+03:00',
        );

        $this->assertSame('unavailable', $payload['freshness']['status']);
        $this->assertContains('project_margin_unavailable', array_column($payload['problem_flags'], 'code'));
        $this->assertContains('wip_forecast_responsibility_center_partial', array_column($payload['problem_flags'], 'code'));
        $this->assertSame(7, $payload['projects'][0]['project']['id']);
        $this->assertContains('project_margin_unavailable', $payload['projects'][0]['problem_flags']);
        $this->assertContains('wip_forecast_responsibility_center_partial', $payload['projects'][0]['problem_flags']);
        $this->assertSame('partial', $payload['projects'][0]['freshness']['wip_forecast']);
    }

    public function test_source_of_truth_keeps_external_systems_as_confirmation_only(): void
    {
        $payload = $this->builder()->build(
            filters: $this->filters(),
            projects: $this->projects(),
            components: $this->emptyComponents(),
            generatedAt: '2026-06-09T10:00:00+03:00',
        );

        $this->assertSame('confirmation_only', $payload['source_of_truth']['external_systems']['1c']);
        $this->assertSame('confirmation_only', $payload['source_of_truth']['external_systems']['bank']);
        $this->assertSame('confirmation_only', $payload['source_of_truth']['external_systems']['edo']);
        $this->assertContains('accounting_entries', $payload['source_of_truth']['excluded']);
        $this->assertContains('tax_accounting', $payload['source_of_truth']['excluded']);
        $this->assertContains('regulated_reporting', $payload['source_of_truth']['excluded']);
        $this->assertContains('payroll', $payload['source_of_truth']['excluded']);
    }

    public function test_project_registry_rows_are_returned_even_without_metric_rows(): void
    {
        $payload = $this->builder()->build(
            filters: $this->filters(),
            projects: $this->projects(),
            components: $this->emptyComponents(),
            generatedAt: '2026-06-09T10:00:00+03:00',
        );

        $this->assertSame(2, $payload['summary']['projects_available']);
        $this->assertSame(2, $payload['summary']['projects_returned']);
        $this->assertSame('actual', $payload['freshness']['status']);
        $this->assertSame([7, 8], array_column(array_column($payload['projects'], 'project'), 'id'));
        $this->assertSame('low', $payload['projects'][0]['risk_level']);
        $this->assertSame([], $payload['projects'][0]['problem_flags']);
        $this->assertSame(0.0, $payload['projects'][0]['metrics']['revenue']);
    }

    public function test_projects_are_sorted_by_highest_risk_before_limit(): void
    {
        $components = $this->emptyComponents();
        $components['project_margin']['report']['rows'] = [
            [
                'project' => ['id' => 7, 'name' => 'Business Center'],
                'currency' => 'RUB',
                'actual' => ['revenue' => 100000.0, 'cost' => 80000.0, 'gross_margin' => 20000.0, 'margin_percent' => 20.0],
                'forecast' => ['revenue' => 100000.0, 'cost' => 80000.0, 'gross_margin' => 20000.0, 'margin_percent' => 20.0],
            ],
            [
                'project' => ['id' => 8, 'name' => 'Warehouse'],
                'currency' => 'RUB',
                'actual' => ['revenue' => 80000.0, 'cost' => 95000.0, 'gross_margin' => -15000.0, 'margin_percent' => -18.75],
                'forecast' => ['revenue' => 85000.0, 'cost' => 100000.0, 'gross_margin' => -15000.0, 'margin_percent' => -17.65],
            ],
        ];

        $payload = $this->builder()->build(
            filters: $this->filters(limit: 1),
            projects: $this->projects(),
            components: $components,
            generatedAt: '2026-06-09T10:00:00+03:00',
        );

        $this->assertSame(8, $payload['projects'][0]['project']['id']);
        $this->assertSame('critical', $payload['projects'][0]['risk_level']);
        $this->assertContains('negative_margin', $payload['projects'][0]['risk_flags']);
        $this->assertSame(180000.0, $payload['totals_by_currency'][0]['revenue']);
        $this->assertSame(2, $payload['meta']['projects_available']);
        $this->assertSame(1, $payload['meta']['projects_returned']);
    }

    public function test_wip_forecast_revenue_at_completion_maps_to_contract_forecast_revenue(): void
    {
        $components = $this->emptyComponents();
        $components['wip_forecast']['report']['rows'] = [[
            'project' => ['id' => 7, 'name' => 'Business Center'],
            'currency' => 'RUB',
            'metrics' => [
                'wip_total' => 18000.0,
                'ftc' => 30000.0,
                'eac' => 140000.0,
                'ctc' => 45000.0,
                'forecast_revenue_at_completion' => 155000.0,
                'forecast_gross_margin' => 25000.0,
                'forecast_margin_percent' => 16.13,
            ],
            'problem_flags' => [],
            'risk_flags' => [],
            'quality_status' => 'actual',
        ]];

        $payload = $this->builder()->build(
            filters: $this->filters(),
            projects: $this->projects(),
            components: $components,
            generatedAt: '2026-06-09T10:00:00+03:00',
        );

        $this->assertSame(155000.0, $payload['projects'][0]['metrics']['forecast_revenue']);
        $this->assertSame(155000.0, $payload['totals_by_currency'][0]['forecast_revenue']);
    }

    private function builder(): ProjectPortfolioDashboardPayloadBuilder
    {
        return new ProjectPortfolioDashboardPayloadBuilder();
    }

    private function filters(
        ?int $responsibilityCenterId = null,
        ?string $responsibilityCenterUuid = null,
        int $limit = 10,
    ): ProjectPortfolioDashboardFilters {
        return new ProjectPortfolioDashboardFilters(
            organizationId: 42,
            periodStart: '2026-06-01',
            periodEnd: '2026-06-30',
            asOfDate: '2026-06-30',
            projectManagerId: 31,
            projectStatus: 'active',
            projectType: 'commercial',
            responsibilityCenterId: $responsibilityCenterId,
            responsibilityCenterUuid: $responsibilityCenterUuid,
            currency: 'RUB',
            limit: $limit,
            topN: 5,
        );
    }

    private function projects(): array
    {
        return [
            7 => [
                'id' => 7,
                'name' => 'Business Center',
                'status' => 'active',
                'type' => 'commercial',
                'manager' => ['id' => 31, 'name' => 'Иван Петров', 'email' => 'ivan@example.test'],
            ],
            8 => [
                'id' => 8,
                'name' => 'Warehouse',
                'status' => 'paused',
                'type' => 'industrial',
                'manager' => null,
            ],
        ];
    }

    private function components(): array
    {
        $components = $this->emptyComponents();
        $components['project_margin']['report']['rows'] = [
            [
                'project' => ['id' => 7, 'name' => 'Business Center'],
                'currency' => 'RUB',
                'actual' => ['revenue' => 150000.0, 'cost' => 110000.0, 'gross_margin' => 40000.0, 'margin_percent' => 26.67],
                'forecast' => ['revenue' => 155000.0, 'cost' => 130000.0, 'gross_margin' => 25000.0, 'margin_percent' => 16.13],
                'problem_flags' => [],
                'risk_flags' => [],
                'quality_status' => 'actual',
            ],
            [
                'project' => ['id' => 8, 'name' => 'Warehouse'],
                'currency' => 'RUB',
                'actual' => ['revenue' => 80000.0, 'cost' => 25000.0, 'gross_margin' => 55000.0, 'margin_percent' => 68.75],
                'forecast' => ['revenue' => 90000.0, 'cost' => 35000.0, 'gross_margin' => 55000.0, 'margin_percent' => 61.11],
                'problem_flags' => [],
                'risk_flags' => [],
                'quality_status' => 'actual',
            ],
        ];
        $components['wip_forecast']['report']['rows'] = [[
            'project' => ['id' => 7, 'name' => 'Business Center'],
            'currency' => 'RUB',
            'metrics' => [
                'wip' => 12000.0,
                'wip_total' => 18000.0,
                'ftc' => 30000.0,
                'eac' => 140000.0,
                'ctc' => 45000.0,
                'forecast_revenue' => 155000.0,
                'forecast_gross_margin' => 25000.0,
                'forecast_margin_percent' => 16.13,
            ],
            'problem_flags' => [],
            'risk_flags' => [],
            'quality_status' => 'actual',
        ]];
        $components['plan_fact']['report']['rows'] = [[
            'project' => ['id' => 7, 'name' => 'Business Center'],
            'currency' => 'RUB',
            'plan_amount' => 250000.0,
            'forecast_amount' => 270000.0,
            'actual_amount' => 210000.0,
            'committed_amount' => 40000.0,
            'variance_amount' => -60000.0,
            'variance_percent' => -24.0,
            'risk_level' => 'high',
        ]];
        $components['cash_gap']['rows'] = [[
            'project_id' => 7,
            'currency' => 'RUB',
            'risk_level' => 'high',
            'has_gap' => true,
            'first_gap_date' => '2026-06-15',
            'max_gap_amount' => 20000.0,
            'opening_balance' => 0.0,
            'closing_balance' => -20000.0,
            'inflows' => 50000.0,
            'outflows' => 70000.0,
            'overdue_receivables' => 9000.0,
            'overdue_payables' => 4000.0,
            'freshness_status' => 'actual',
        ]];
        $components['limit_risk']['rows'] = [[
            'project_id' => 7,
            'currency' => 'RUB',
            'reserved_amount' => 5000.0,
            'reserved_count' => 1,
            'warning_count' => 0,
            'exceeded_count' => 1,
            'requires_exception_count' => 0,
            'blocked_count' => 1,
            'latest_checked_at' => '2026-06-09T09:00:00+03:00',
        ]];
        $components['approvals']['rows'] = [[
            'project_id' => 7,
            'currency' => 'RUB',
            'pending_count' => 2,
            'pending_documents_count' => 1,
            'latest_pending_created_at' => '2026-06-09T09:30:00+03:00',
        ]];
        $components['one_c_exchange']['freshness'] = [
            'status' => 'warning',
            'problem_count' => 1,
            'open_conflicts_count' => 1,
        ];

        return $components;
    }

    private function emptyComponents(): array
    {
        return [
            'project_margin' => ['available' => true, 'report' => ['rows' => []], 'freshness' => ['status' => 'actual']],
            'plan_fact' => ['available' => true, 'report' => ['rows' => []], 'freshness' => ['status' => 'actual']],
            'wip_forecast' => ['available' => true, 'report' => ['rows' => []], 'freshness' => ['status' => 'actual']],
            'cash_gap' => ['available' => true, 'rows' => [], 'freshness' => ['status' => 'actual']],
            'limit_risk' => ['available' => true, 'rows' => [], 'freshness' => ['status' => 'actual']],
            'approvals' => ['available' => true, 'rows' => [], 'freshness' => ['status' => 'actual']],
            'one_c_exchange' => ['available' => true, 'summary' => [], 'freshness' => ['status' => 'actual']],
        ];
    }
}
