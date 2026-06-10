<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\Services\CfoCommandCenterPayloadBuilder;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

final class CfoCommandCenterPayloadBuilderTest extends TestCase
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

    public function test_contract_contains_summary_aggregates_limited_items_flags_actions_and_meta(): void
    {
        $payload = $this->builder()->build(
            filters: $this->filters(),
            aggregates: $this->riskyAggregates(),
            items: [
                'upcoming_payments' => [['id' => 'payment_document:1']],
                'expected_inflows' => [['id' => 'payment_document:2']],
                'overdue' => [['id' => 'payment_document:3']],
                'limit_overruns' => [['id' => 10]],
                'plan_fact_deviations' => [['drill_down_key' => 'key']],
                'approval_blockers' => [['id' => 11]],
                'one_c_exchange_issues' => [['id' => 12]],
            ],
            sourceOfTruth: $this->sourceOfTruth(),
            freshness: $this->freshness(),
            generatedAt: '2026-06-09T10:00:00+03:00',
            itemLimit: 10,
        );

        $this->assertSame('critical', $payload['summary']['health']);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('aggregates', $payload);
        $this->assertArrayHasKey('items', $payload);
        $this->assertArrayHasKey('problem_flags', $payload);
        $this->assertArrayHasKey('risk_flags', $payload);
        $this->assertArrayHasKey('actions', $payload);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertSame(2500.0, $payload['summary']['cash_gap']['max_gap_amount']);
        $this->assertSame(3000.0, $payload['summary']['payments']['upcoming_outflow_amount']);
        $this->assertSame(2, $payload['summary']['limits']['blocked_count']);
        $this->assertSame(3, $payload['summary']['approvals']['pending_count']);
        $this->assertSame('warning', $payload['summary']['one_c_exchange']['health']);
        $this->assertContains('cash_gap_risk', array_column($payload['risk_flags'], 'code'));
        $this->assertContains('budget_limit_blocked', array_column($payload['problem_flags'], 'code'));
        $problemFlags = array_column($payload['problem_flags'], null, 'code');
        $riskFlags = array_column($payload['risk_flags'], null, 'code');
        $this->assertSame(
            'Откройте платежные документы, проверьте заблокированные заявки и согласуйте решение по лимиту.',
            $problemFlags['budget_limit_blocked']['action_hint'],
        );
        $this->assertSame('/payments?tab=documents', $problemFlags['budget_limit_blocked']['route_hint']);
        $this->assertSame(
            'Откройте платежный календарь, проверьте ближайшие выплаты и ожидаемые поступления до даты разрыва.',
            $riskFlags['cash_gap_risk']['action_hint'],
        );
        $this->assertSame('/payments?tab=calendar&cash_gap=1', $riskFlags['cash_gap_risk']['route_hint']);
        $this->assertContains('cover_cash_gap', array_column($payload['actions'], 'code'));
        $this->assertSame('prohelper_management_forecast', $payload['meta']['source_of_truth']['cash_gap']['primary']);
        $this->assertSame(10, $payload['meta']['item_limits']['upcoming_payments']);
    }

    public function test_empty_state_keeps_stable_shape_without_actions_or_flags(): void
    {
        $payload = $this->builder()->build(
            filters: $this->filters(),
            aggregates: $this->emptyAggregates(),
            items: [
                'upcoming_payments' => [],
                'expected_inflows' => [],
                'overdue' => [],
                'limit_overruns' => [],
                'plan_fact_deviations' => [],
                'approval_blockers' => [],
                'one_c_exchange_issues' => [],
            ],
            sourceOfTruth: $this->sourceOfTruth(),
            freshness: $this->freshness(),
            generatedAt: '2026-06-09T10:00:00+03:00',
            itemLimit: 10,
        );

        $this->assertSame('ok', $payload['summary']['health']);
        $this->assertSame(0, $payload['summary']['payments']['items_count']);
        $this->assertSame(0, $payload['summary']['problem_flags_count']);
        $this->assertSame(0, $payload['summary']['risk_flags_count']);
        $this->assertSame(0, $payload['summary']['actions_count']);
        $this->assertSame([], $payload['problem_flags']);
        $this->assertSame([], $payload['risk_flags']);
        $this->assertSame([], $payload['actions']);
        $this->assertSame([], $payload['items']['upcoming_payments']);
    }

    public function test_partial_cash_gap_unavailability_raises_problem_flag(): void
    {
        $aggregates = $this->emptyAggregates();
        $aggregates['cash_gap']['available'] = true;
        $aggregates['cash_gap']['currencies'] = ['RUB'];
        $aggregates['cash_gap']['requested_currencies'] = ['RUB', 'USD'];
        $aggregates['cash_gap']['unavailable_currencies'] = ['USD'];

        $payload = $this->builder()->build(
            filters: $this->filters(),
            aggregates: $aggregates,
            items: [
                'upcoming_payments' => [],
                'expected_inflows' => [],
                'overdue' => [],
                'limit_overruns' => [],
                'plan_fact_deviations' => [],
                'approval_blockers' => [],
                'one_c_exchange_issues' => [],
            ],
            sourceOfTruth: $this->sourceOfTruth(),
            freshness: $this->freshness(),
            generatedAt: '2026-06-09T10:00:00+03:00',
            itemLimit: 10,
        );

        $this->assertContains('cash_gap_unavailable', array_column($payload['problem_flags'], 'code'));
        $this->assertSame(['USD'], $payload['problem_flags'][0]['details']['unavailable_currencies']);
        $this->assertSame(
            'Откройте платежный календарь и проверьте начальные остатки по валютам, которые не попали в прогноз.',
            $payload['problem_flags'][0]['action_hint'],
        );
        $this->assertSame('/payments?tab=calendar', $payload['problem_flags'][0]['route_hint']);
        $this->assertSame('warning', $payload['summary']['health']);
    }

    public function test_project_portfolio_contract_surfaces_project_kpis_and_top_problem_projects(): void
    {
        $aggregates = $this->emptyAggregates();
        $aggregates['project_portfolio'] = [
            'available' => true,
            'summary' => [
                'projects_count' => 3,
                'active_projects_count' => 2,
                'problem_projects_count' => 1,
                'risk_projects_count' => 2,
                'cash_gap_projects_count' => 1,
                'budget_deviation_projects_count' => 1,
                'top_problem_projects_count' => 1,
                'freshness_status' => 'attention',
                'by_currency' => [
                    'RUB' => [
                        'revenue' => 150000.0,
                        'cost' => 110000.0,
                        'gross_margin' => 40000.0,
                        'wip_total' => 12000.0,
                        'ftc' => 30000.0,
                        'eac' => 140000.0,
                        'ctc' => 45000.0,
                        'cash_gap_signal' => -20000.0,
                    ],
                ],
                'problem_flags' => ['budget_overrun'],
                'risk_flags' => ['cash_gap_risk'],
            ],
        ];

        $payload = $this->builder()->build(
            filters: $this->filters(),
            aggregates: $aggregates,
            items: [
                'upcoming_payments' => [],
                'expected_inflows' => [],
                'overdue' => [],
                'limit_overruns' => [],
                'plan_fact_deviations' => [],
                'approval_blockers' => [],
                'one_c_exchange_issues' => [],
                'top_problem_projects' => [[
                    'project' => ['id' => 7, 'name' => 'Business Center', 'status' => 'active'],
                    'currency' => 'RUB',
                    'score' => 17,
                    'metrics' => [
                        'revenue' => 150000.0,
                        'cost' => 110000.0,
                        'gross_margin' => 40000.0,
                        'wip_total' => 12000.0,
                        'ftc' => 30000.0,
                        'cash_gap_signal' => -20000.0,
                    ],
                    'problem_flags' => ['budget_overrun'],
                    'risk_flags' => ['cash_gap_risk'],
                ]],
            ],
            sourceOfTruth: [
                ...$this->sourceOfTruth(),
                'project_portfolio' => ['primary' => 'project_margin_and_wip_reports'],
            ],
            freshness: [
                ...$this->freshness(),
                'project_portfolio' => ['generated_at' => '2026-06-09T10:00:00+03:00'],
            ],
            generatedAt: '2026-06-09T10:00:00+03:00',
            itemLimit: 10,
        );

        $this->assertSame(3, $payload['summary']['project_portfolio']['projects_count']);
        $this->assertSame(40000.0, $payload['summary']['project_portfolio']['by_currency']['RUB']['gross_margin']);
        $this->assertSame(12000.0, $payload['summary']['project_portfolio']['by_currency']['RUB']['wip_total']);
        $this->assertSame(1, $payload['summary']['project_portfolio']['cash_gap_projects_count']);
        $this->assertContains('project_portfolio_attention', array_column($payload['problem_flags'], 'code'));
        $this->assertContains('project_cash_gap_risk', array_column($payload['risk_flags'], 'code'));
        $problemFlags = array_column($payload['problem_flags'], null, 'code');
        $this->assertSame(
            'Откройте портфель проектов и начните с проектов с высоким риском в верхней части таблицы.',
            $problemFlags['project_portfolio_attention']['action_hint'],
        );
        $this->assertSame('/budgeting?tab=project_portfolio', $problemFlags['project_portfolio_attention']['route_hint']);
        $this->assertContains('review_problem_projects', array_column($payload['actions'], 'code'));
        $this->assertSame(10, $payload['meta']['item_limits']['top_problem_projects']);
        $this->assertSame(7, $payload['items']['top_problem_projects'][0]['project']['id']);
        $this->assertSame('project_margin_and_wip_reports', $payload['meta']['source_of_truth']['project_portfolio']['primary']);
    }

    private function builder(): CfoCommandCenterPayloadBuilder
    {
        return new CfoCommandCenterPayloadBuilder();
    }

    private function filters(): array
    {
        return [
            'organization_id' => 42,
            'project_id' => 14,
            'responsibility_center_id' => 'center-uuid',
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'currency' => 'RUB',
            'item_limit' => 10,
        ];
    }

    private function riskyAggregates(): array
    {
        return [
            'cash_gap' => [
                'available' => true,
                'currencies' => ['RUB'],
                'cash_position_by_currency' => [
                    'RUB' => [
                        'opening_balance' => 1000.0,
                        'closing_balance' => -1500.0,
                        'net_forecast' => -2500.0,
                    ],
                ],
                'has_gap' => true,
                'first_gap_date' => '2026-06-12',
                'max_gap_amount' => 2500.0,
                'highest_risk_level' => 'critical',
                'unavailable_currencies' => [],
            ],
            'payment_calendar' => [
                'summary' => [
                    'items_count' => 8,
                    'upcoming_outflow_amount' => 3000.0,
                    'expected_inflow_amount' => 700.0,
                    'overdue_count' => 2,
                    'overdue_outflow_amount' => 900.0,
                    'overdue_inflow_amount' => 600.0,
                ],
            ],
            'limits' => [
                'summary' => [
                    'reserved_amount' => 1200.0,
                    'reserved_count' => 4,
                    'warning_count' => 1,
                    'exceeded_count' => 1,
                    'requires_exception_count' => 1,
                    'blocked_count' => 2,
                ],
            ],
            'plan_fact' => [
                'available' => true,
                'summary' => [
                    'rows_count' => 5,
                    'highest_risk_level' => 'critical',
                    'critical_rows_count' => 1,
                    'high_rows_count' => 2,
                ],
            ],
            'approvals' => [
                'summary' => [
                    'pending_count' => 3,
                    'pending_documents_count' => 2,
                ],
            ],
            'one_c_exchange' => [
                'available' => true,
                'summary' => [
                    'health' => 'warning',
                    'problem_count' => 1,
                    'open_conflicts_count' => 1,
                ],
            ],
        ];
    }

    private function emptyAggregates(): array
    {
        return [
            'cash_gap' => [
                'available' => true,
                'currencies' => ['RUB'],
                'cash_position_by_currency' => [
                    'RUB' => [
                        'opening_balance' => 0.0,
                        'closing_balance' => 0.0,
                        'net_forecast' => 0.0,
                    ],
                ],
                'has_gap' => false,
                'first_gap_date' => null,
                'max_gap_amount' => 0.0,
                'highest_risk_level' => 'low',
                'unavailable_currencies' => [],
            ],
            'payment_calendar' => [
                'summary' => [
                    'items_count' => 0,
                    'upcoming_outflow_amount' => 0.0,
                    'expected_inflow_amount' => 0.0,
                    'overdue_count' => 0,
                    'overdue_outflow_amount' => 0.0,
                    'overdue_inflow_amount' => 0.0,
                ],
            ],
            'limits' => [
                'summary' => [
                    'reserved_amount' => 0.0,
                    'reserved_count' => 0,
                    'warning_count' => 0,
                    'exceeded_count' => 0,
                    'requires_exception_count' => 0,
                    'blocked_count' => 0,
                ],
            ],
            'plan_fact' => [
                'available' => true,
                'summary' => [
                    'rows_count' => 0,
                    'highest_risk_level' => 'low',
                    'critical_rows_count' => 0,
                    'high_rows_count' => 0,
                ],
            ],
            'approvals' => [
                'summary' => [
                    'pending_count' => 0,
                    'pending_documents_count' => 0,
                ],
            ],
            'one_c_exchange' => [
                'available' => true,
                'summary' => [
                    'health' => 'ok',
                    'problem_count' => 0,
                    'open_conflicts_count' => 0,
                ],
            ],
        ];
    }

    private function sourceOfTruth(): array
    {
        return [
            'cash_gap' => ['primary' => 'prohelper_management_forecast'],
        ];
    }

    private function freshness(): array
    {
        return [
            'cash_gap' => ['generated_at' => '2026-06-09T10:00:00+03:00'],
        ];
    }
}
