<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Monitoring\CostTrendResult;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\DashboardFilters;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\DashboardMetricRows;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationDashboardMetrics;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationDashboardQueryFactory;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationDashboardRepository;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\EstimateGenerationDashboardService;
use App\BusinessModules\Addons\EstimateGeneration\Monitoring\SqlEstimateGenerationDashboardRepository;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class EstimateGenerationDashboardTest extends TestCase
{
    public function test_dashboard_queries_apply_all_filters_and_never_select_sensitive_payloads(): void
    {
        $filters = DashboardFilters::fromArray([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-14',
            'organization_id' => 17,
            'project_id' => 31,
            'provider' => 'timeweb',
            'model' => 'vision-v2',
            'stage' => 'understand_documents',
            'status' => 'failed',
            'document_type' => 'application/pdf',
            'mode' => 'strict_documents',
        ], CarbonImmutable::parse('2026-07-14 12:00:00 UTC'));
        $factory = new EstimateGenerationDashboardQueryFactory;

        $queries = [
            $factory->sessionMetrics($filters),
            $factory->usageMetrics($filters),
            $factory->queueHealth($filters),
            $factory->currencySelection($filters),
            $factory->costTrend($filters, ['RUB', 'USD']),
        ];

        foreach ($queries as $query) {
            self::assertLessThanOrEqual(1000, $query->rowLimit);
            self::assertStringNotContainsString('select *', strtolower($query->sql));
            self::assertStringNotContainsString('extracted_text', strtolower($query->sql));
            self::assertStringNotContainsString('structured_payload', strtolower($query->sql));
            self::assertStringNotContainsString('output_payload', strtolower($query->sql));
            self::assertStringNotContainsString('price_snapshot', strtolower($query->sql));
            self::assertStringNotContainsString('safe_context', strtolower($query->sql));
        }

        $canonicalBindings = [
            '2026-07-01 00:00:00', '2026-07-15 00:00:00', 17, 31, 'failed',
            'application/pdf', 'strict_documents', 'timeweb', 'vision-v2', 'understand_documents',
        ];
        foreach (array_slice($queries, 0, 4) as $query) {
            self::assertStringContainsString('WITH filtered_sessions AS MATERIALIZED', $query->sql);
            self::assertStringContainsString('SELECT sessions.id, sessions.organization_id, sessions.status', $query->sql);
            self::assertStringContainsString("WHERE\n    sessions.created_at >= ?", $query->sql);
            self::assertStringContainsString('usage_filter.organization_id = sessions.organization_id', $query->sql);
            self::assertStringContainsString('usage_filter.session_id = sessions.id', $query->sql);
            self::assertStringContainsString('usage_filter.provider = ?', $query->sql);
            self::assertStringContainsString('usage_filter.requested_model = ?', $query->sql);
            self::assertStringContainsString('usage_filter.stage = ?', $query->sql);
            self::assertSame($canonicalBindings, $query->bindings);
        }
        self::assertStringContainsString('SELECT sessions.id, sessions.organization_id, sessions.status', $queries[4]->sql);
        self::assertStringContainsString("WHERE\n    sessions.created_at >= ?", $queries[4]->sql);
        self::assertStringContainsString('usage_filter.organization_id = sessions.organization_id', $queries[4]->sql);
        self::assertStringContainsString('usage_filter.session_id = sessions.id', $queries[4]->sql);
        self::assertSame([...$canonicalBindings, 'RUB', 'USD'], $queries[4]->bindings);
        self::assertStringNotContainsString('usage.created_at >= ?', $queries[1]->sql);
        self::assertStringContainsString("DATE_TRUNC('day', filtered_sessions.created_at)", $queries[4]->sql);
        foreach ([$queries[1], $queries[3]] as $query) {
            self::assertStringContainsString(
                'JOIN filtered_sessions ON usage.organization_id = filtered_sessions.organization_id AND usage.session_id = filtered_sessions.id',
                $query->sql,
            );
        }
        self::assertStringContainsString(
            'JOIN estimate_generation_ai_usage AS usage ON usage.organization_id = filtered_sessions.organization_id AND usage.session_id = filtered_sessions.id',
            $queries[4]->sql,
        );
        foreach ([$queries[1], $queries[3], $queries[4]] as $query) {
            self::assertStringNotContainsString('filtered_sessions.id = usage.session_id', $query->sql);
        }
        self::assertStringContainsString('JOIN filtered_sessions ON filtered_sessions.id = checkpoints.session_id', $queries[2]->sql);
        self::assertStringContainsString('ORDER BY total_cost DESC, currency ASC', $queries[3]->sql);
        self::assertSame(DashboardFilters::MAX_CURRENCY_SERIES + 1, $queries[3]->rowLimit);
        self::assertSame(DashboardFilters::MAX_DAYS * DashboardFilters::MAX_CURRENCY_SERIES, $queries[4]->rowLimit);
    }

    public function test_metrics_are_factual_and_cost_ratios_use_successful_and_applied_denominators(): void
    {
        $metrics = EstimateGenerationDashboardMetrics::fromRows(
            sessions: [
                'sessions_total' => '20',
                'successful_sessions' => '8',
                'applied_sessions' => '4',
                'current_review_backlog_sessions' => '5',
                'documents_total' => '36',
                'average_duration_ms' => '1250.5',
                'p95_duration_ms' => '4000',
            ],
            usage: [
                'total_cost' => '24.00000000',
                'currency' => 'RUB',
            ],
            queue: [
                'running_jobs' => '3',
                'stale_jobs' => '1',
                'oldest_queue_age_seconds' => '240',
            ],
        );

        self::assertSame(0.5, $metrics['apply_rate']);
        self::assertSame(0.25, $metrics['current_review_backlog_share']);
        self::assertSame(3.0, $metrics['cost_per_successful_session']);
        self::assertSame(6.0, $metrics['cost_per_applied_session']);
        self::assertSame(4000, $metrics['p95_duration_ms']);
        self::assertSame(240, $metrics['oldest_queue_age_seconds']);
        self::assertSame('RUB', $metrics['currency']);
    }

    public function test_mixed_currency_cost_is_not_reported_as_one_false_total(): void
    {
        $metrics = EstimateGenerationDashboardMetrics::fromRows(
            ['sessions_total' => 2, 'successful_sessions' => 2, 'applied_sessions' => 1],
            ['total_cost' => null, 'currency' => null],
            [],
        );

        self::assertNull($metrics['total_cost']);
        self::assertNull($metrics['cost_per_successful_session']);
        self::assertNull($metrics['cost_per_applied_session']);
        self::assertNull($metrics['currency']);
    }

    public function test_dashboard_period_is_bounded_for_operational_queries(): void
    {
        $filters = DashboardFilters::fromArray([
            'date_from' => '2025-01-01',
            'date_to' => '2026-07-14',
        ], CarbonImmutable::parse('2026-07-14 12:00:00 UTC'));

        self::assertSame((float) DashboardFilters::MAX_DAYS, $filters->from->diffInDays($filters->until));
    }

    public function test_current_review_backlog_share_has_explicit_zero_full_and_invalid_bounds(): void
    {
        self::assertSame(0.0, EstimateGenerationDashboardMetrics::fromRows(
            ['sessions_total' => 10, 'current_review_backlog_sessions' => 0], [], [],
        )['current_review_backlog_share']);
        self::assertSame(1.0, EstimateGenerationDashboardMetrics::fromRows(
            ['sessions_total' => 10, 'current_review_backlog_sessions' => 10], [], [],
        )['current_review_backlog_share']);

        $this->expectException(UnexpectedValueException::class);
        EstimateGenerationDashboardMetrics::fromRows(
            ['sessions_total' => 10, 'current_review_backlog_sessions' => 11], [], [],
        );
    }

    public function test_fake_repository_proves_filters_change_every_metric_denominator_and_trend(): void
    {
        $repository = new FixtureDashboardRepository;
        $service = new EstimateGenerationDashboardService($repository, new CacheRepository(new ArrayStore));

        $review = $service->metrics(DashboardFilters::fromArray([
            'date_from' => '2026-07-01', 'date_to' => '2026-07-14',
            'organization_id' => 17, 'project_id' => 31, 'provider' => 'timeweb',
        ]));
        $applied = $service->metrics(DashboardFilters::fromArray([
            'date_from' => '2026-07-01', 'date_to' => '2026-07-14',
            'organization_id' => 17, 'project_id' => 31, 'provider' => 'openai',
        ]));

        self::assertSame([1, 0, 1, 1, 10.0, 1], [
            $review['sessions_total'], $review['applied_sessions'],
            $review['current_review_backlog_sessions'], $review['documents_total'],
            $review['total_cost'], $review['running_jobs'],
        ]);
        self::assertSame([1, 1, 0, 2, 20.0, 0], [
            $applied['sessions_total'], $applied['applied_sessions'],
            $applied['current_review_backlog_sessions'], $applied['documents_total'],
            $applied['total_cost'], $applied['running_jobs'],
        ]);
        self::assertSame(1.0, $review['current_review_backlog_share']);
        self::assertSame(0.0, $applied['current_review_backlog_share']);
        self::assertSame('timeweb', $repository->metricFilters[0]->provider);
        self::assertSame('openai', $repository->metricFilters[1]->provider);
    }

    public function test_currency_selection_reports_omitted_series_without_partial_buckets(): void
    {
        $repository = new FixtureDashboardRepository(DashboardFilters::MAX_CURRENCY_SERIES + 1);
        $service = new EstimateGenerationDashboardService($repository, new CacheRepository(new ArrayStore));
        $result = $service->costTrend(DashboardFilters::fromArray([
            'date_from' => '2026-07-01', 'date_to' => '2026-07-14',
        ]));

        self::assertTrue($result->truncated);
        self::assertSame(1, $result->omittedCurrencies);
        self::assertCount(DashboardFilters::MAX_CURRENCY_SERIES * 2, $result->rows);
        foreach (array_chunk($result->rows, 2) as $currencyRows) {
            self::assertCount(2, $currencyRows);
        }
    }

    public function test_sql_repository_detects_max_plus_one_currency_and_fetches_only_complete_selected_series(): void
    {
        $database = $this->createMock(ConnectionInterface::class);
        $trendBindings = [];
        $database->method('select')->willReturnCallback(function (string $sql, array $bindings) use (&$trendBindings): array {
            if (str_contains($sql, 'currency_totals')) {
                return array_map(static fn (int $currency): object => (object) [
                    'currency' => "C{$currency}",
                    'total_cost' => 100 - $currency,
                    'currencies_total' => DashboardFilters::MAX_CURRENCY_SERIES + 1,
                ], range(1, DashboardFilters::MAX_CURRENCY_SERIES + 1));
            }

            $trendBindings = $bindings;

            return array_map(static fn (int $row): object => (object) [
                'bucket' => $row % 2 === 0 ? '2026-07-02' : '2026-07-01',
                'total_cost' => 1,
                'currency' => 'C'.(int) ceil($row / 2),
                'sessions' => 1,
            ], range(1, DashboardFilters::MAX_CURRENCY_SERIES * 2));
        });
        $filters = DashboardFilters::fromArray(['date_from' => '2026-07-01', 'date_to' => '2026-07-14']);

        $result = (new SqlEstimateGenerationDashboardRepository(
            $database,
            new EstimateGenerationDashboardQueryFactory,
        ))->costTrend($filters);

        self::assertTrue($result->truncated);
        self::assertSame(1, $result->omittedCurrencies);
        self::assertCount(DashboardFilters::MAX_CURRENCY_SERIES * 2, $result->rows);
        self::assertSame(['C1', 'C2', 'C3', 'C4'], array_slice($trendBindings, -4));
    }

    public function test_cost_widget_visibly_reports_omitted_currency_series(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/Filament/Widgets/EstimateGeneration/CostTrendWidget.php');

        self::assertIsString($source);
        self::assertStringContainsString('currency_series_limited', $source);
        self::assertStringContainsString('$result->truncated', $source);
        self::assertStringContainsString('$result->omittedCurrencies', $source);
    }
}

final class FixtureDashboardRepository implements EstimateGenerationDashboardRepository
{
    /** @var list<DashboardFilters> */
    public array $metricFilters = [];

    public function __construct(private readonly int $currencyCount = 1) {}

    public function metricRows(DashboardFilters $filters): DashboardMetricRows
    {
        $this->metricFilters[] = $filters;
        $review = $filters->provider !== 'openai';

        return new DashboardMetricRows(
            sessions: [
                'sessions_total' => 1,
                'successful_sessions' => 1,
                'applied_sessions' => $review ? 0 : 1,
                'current_review_backlog_sessions' => $review ? 1 : 0,
                'documents_total' => $review ? 1 : 2,
                'average_duration_ms' => $review ? 100 : 200,
                'p95_duration_ms' => $review ? 100 : 200,
            ],
            usage: ['total_cost' => $review ? 10 : 20, 'currency' => 'RUB'],
            queue: ['running_jobs' => $review ? 1 : 0, 'stale_jobs' => 0, 'oldest_queue_age_seconds' => 0],
        );
    }

    public function costTrend(DashboardFilters $filters): CostTrendResult
    {
        $rows = [];
        $visible = min($this->currencyCount, DashboardFilters::MAX_CURRENCY_SERIES);
        for ($currency = 1; $currency <= $visible; $currency++) {
            foreach (['2026-07-01', '2026-07-02'] as $bucket) {
                $rows[] = ['bucket' => $bucket, 'total_cost' => (float) $currency, 'currency' => "C{$currency}", 'sessions' => 1];
            }
        }

        return new CostTrendResult(
            $rows,
            $this->currencyCount > DashboardFilters::MAX_CURRENCY_SERIES,
            max(0, $this->currencyCount - DashboardFilters::MAX_CURRENCY_SERIES),
        );
    }
}
